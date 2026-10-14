<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Service\ConnectionCheckStore;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ConnectionCheckStoreTest extends TestCase {
	private array $storage = [];

	public function testRecordsOnlySafeFieldsPerServiceAndReadsThemBack(): void {
		$store = $this->store();
		$fingerprint = ConnectionCheckStore::fingerprint('vision', ['https://vision.example/v1/chat/completions', 'vision-model'], 'private-key');
		$before = time();
		$store->record('vision', false, 1234, 'vision-model', 'Vision connection test failed', $fingerprint);
		$store->record('model', true, 12, 'secondary:model-b', 'ignored for a success', null);

		$this->assertSame(['educai/connection_check_model', 'educai/connection_check_vision'], array_keys($this->sorted()));
		$vision = $store->read('vision');
		$this->assertGreaterThanOrEqual($before, $vision['checked_at']);
		$this->assertLessThanOrEqual(time(), $vision['checked_at']);
		unset($vision['checked_at']);
		$this->assertSame([
			'success' => false, 'duration_ms' => 1234, 'summary' => 'vision-model',
			'error' => 'Vision connection test failed', 'fingerprint' => $fingerprint,
		], $vision);
		$this->assertNull($store->read('model')['error'], 'A successful check has no error');
		$this->assertNull($store->read('docling'));
		$this->assertStringNotContainsString('private-key', json_encode($this->storage));
		$this->assertSame(['success', 'checked_at', 'duration_ms', 'summary', 'error', 'fingerprint'],
			array_keys(json_decode($this->storage['educai/connection_check_vision'], true)));
	}

	public function testPresentMarksACheckCurrentOnlyForTheSameFingerprint(): void {
		$fingerprint = ConnectionCheckStore::fingerprint('speech', ['https://speech.example', 'whisper'], 'key-a');
		$record = ['success' => true, 'checked_at' => 100, 'duration_ms' => 5, 'summary' => 'whisper', 'error' => null, 'fingerprint' => $fingerprint];
		$this->assertSame(['success' => true, 'checked_at' => 100, 'duration_ms' => 5, 'summary' => 'whisper', 'error' => null, 'current' => true],
			ConnectionCheckStore::present($record, ConnectionCheckStore::fingerprint('speech', ['https://speech.example', 'whisper'], 'key-a')));
		foreach ([
			ConnectionCheckStore::fingerprint('speech', ['https://speech.example', 'whisper'], 'key-b'),
			ConnectionCheckStore::fingerprint('speech', ['https://other.example', 'whisper'], 'key-a'),
			ConnectionCheckStore::fingerprint('speech', ['https://speech.example', 'whisper-large'], 'key-a'),
			ConnectionCheckStore::fingerprint('vision', ['https://speech.example', 'whisper'], 'key-a'),
			null,
		] as $current) {
			$this->assertFalse(ConnectionCheckStore::present($record, $current)['current']);
		}
		$this->assertFalse(ConnectionCheckStore::present(['fingerprint' => null] + $record, null)['current']);
		$this->assertArrayNotHasKey('fingerprint', ConnectionCheckStore::present($record, null));
	}

	public function testFingerprintHashesTheKeyAndIsDeterministic(): void {
		$a = ConnectionCheckStore::fingerprint('docling', ['https://d.example/v1/convert/file', 'docling_serve', 'x_api_key'], 'secret-key');
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a);
		$this->assertSame($a, ConnectionCheckStore::fingerprint('docling', ['https://d.example/v1/convert/file', 'docling_serve', 'x_api_key'], 'secret-key'));
		$this->assertNotSame($a, ConnectionCheckStore::fingerprint('docling', ['https://d.example/v1/convert/file', 'docling_serve', 'x_api_key'], 'other-key'));
		$this->assertSame(ConnectionCheckStore::fingerprint('catalogue', ['https://c.example'], null),
			ConnectionCheckStore::fingerprint('catalogue', ['https://c.example'], ''));
	}

	public function testSummariesAndErrorsAreSanitizedAndBounded(): void {
		$store = $this->store();
		$store->record('docling', false, -5, "Docling\u{0000} Serve\n1.36.0 · x_api_key", str_repeat('e', 900) . "\x1b", 'not-a-fingerprint');
		$record = $store->read('docling');
		$this->assertSame('Docling  Serve 1.36.0 · x_api_key', $record['summary']);
		$this->assertSame(500, mb_strlen($record['error']));
		$this->assertSame(0, $record['duration_ms']);
		$this->assertNull($record['fingerprint']);

		$store->record('model', true, null, 'primary:' . str_repeat('m', 600), null, null);
		$this->assertSame(512, mb_strlen($store->read('model')['summary']));
		$store->record('catalogue', false, null, "\x80invalid utf-8", '   ', null);
		$this->assertSame('?invalid utf-8', $store->read('catalogue')['summary']);
		$this->assertNull($store->read('catalogue')['error']);
	}

	public function testMalformedRecordsAndUnknownServicesAreIgnored(): void {
		$store = $this->store();
		foreach (['{', '[]', '{"success":"yes","checked_at":1}', '{"success":true}', '{"success":true,"checked_at":"1"}'] as $raw) {
			$this->storage['educai/connection_check_speech'] = $raw;
			$this->assertNull($store->read('speech'), $raw);
		}
		$this->storage['educai/connection_check_speech'] = json_encode(['success' => true, 'checked_at' => 7,
			'duration_ms' => 'slow', 'summary' => ['x'], 'error' => 'kept only for failures', 'fingerprint' => 'abc']);
		$this->assertSame(['success' => true, 'checked_at' => 7, 'duration_ms' => null, 'summary' => null, 'error' => null, 'fingerprint' => null],
			$store->read('speech'));
		$this->assertNull($store->read('unknown'));
		$this->expectException(\InvalidArgumentException::class);
		$store->record('unknown', true, null, null, null, null);
	}

	public function testStorageFailureDoesNotBreakTheTestResult(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('setAppValue')->willThrowException(new \RuntimeException('database private-detail'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('The connection check result could not be remembered', ['service' => 'model']);
		(new ConnectionCheckStore($config, $logger))->record('model', true, 1, 'primary:a', null, null);
	}

	private function store(): ConnectionCheckStore {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $this->storage[$app . '/' . $key] ?? $default);
		$config->method('setAppValue')->willReturnCallback(function (string $app, string $key, string $value): void {
			$this->storage[$app . '/' . $key] = $value;
		});
		return new ConnectionCheckStore($config, $this->createMock(LoggerInterface::class));
	}

	private function sorted(): array {
		$storage = $this->storage;
		ksort($storage);
		return $storage;
	}
}
