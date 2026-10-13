<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Db\Settings;
use OCA\EducAI\Service\EmbeddingClient;
use OCA\EducAI\Service\EmbeddingConfigurationService;
use OCA\EducAI\Service\RateLimitService;
use OCA\EducAI\Service\SettingsService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ModelReferenceEmbeddingTest extends TestCase {
	public static function references(): array {
		return [['primary:vendor/model'], ['secondary:vendor/model']];
	}

	#[DataProvider('references')]
	public function testQualifiedChatDefaultDoesNotLeakIntoEmbeddingRequests(string $reference): void {
		$settings = new Settings();
		$settings->setDefaultModel($reference);
		$settings->setApiEndpoint('https://primary.invalid/v1/chat/completions');
		$service = $this->createMock(SettingsService::class);
		$service->method('getSettings')->willReturn($settings);
		$service->method('getRagConfig')->willReturn(['embedding_model' => '', 'embedding_api_key' => 'test-key']);
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('{"data":[{"embedding":[0.1,0.2]}]}');
		$http = $this->createMock(IClient::class);
		$http->expects($this->once())->method('post')->with('https://primary.invalid/v1/embeddings',
			$this->callback(static fn (array $options): bool => $options['json']['model'] === 'vendor/model'))
			->willReturn($response);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($http);
		$embedding = new EmbeddingClient($clients, $service, $this->createMock(RateLimitService::class), $this->createMock(LoggerInterface::class));
		$this->assertSame([[0.1, 0.2]], $embedding->embedTexts(['test']));
		$this->assertSame('primary:literal-embedding-name', $embedding->getActiveModel('primary:literal-embedding-name'));
	}

	public function testQualifyingDefaultDoesNotMarkExistingEmbeddingVectorsStale(): void {
		$before = new Settings();
		$before->setDefaultModel('vendor/model');
		$after = clone $before;
		$after->setDefaultModel('secondary:vendor/model');
		$config = $this->createMock(IConfig::class);
		$config->expects($this->never())->method('setAppValue');
		(new EmbeddingConfigurationService($config))->recordChange($before, $after);
	}

	public function testChangingInheritedRawModelStillMarksBothIndexesStale(): void {
		$before = new Settings();
		$before->setDefaultModel('primary:old');
		$after = clone $before;
		$after->setDefaultModel('primary:new');
		$config = $this->createMock(IConfig::class);
		$config->expects($this->exactly(2))->method('setAppValue')->with('educai', $this->anything(), '1');
		(new EmbeddingConfigurationService($config))->recordChange($before, $after);
	}
}
