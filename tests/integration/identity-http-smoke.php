<?php

declare(strict_types=1);

/**
 * Real Nextcloud/Guzzle/cURL transport smoke, without an installed NC or DB.
 * Run in a disposable, network-isolated Nextcloud image; no exposed host ports.
 * PHPUnit only supplies unrelated config/certificate/validator dependencies.
 */
if (PHP_SAPI !== 'cli' || !extension_loaded('curl')) {
	fwrite(STDERR, "CLI PHP with cURL is required.\n");
	exit(2);
}
$root = getenv('EDUCAI_TEST_NEXTCLOUD_ROOT') ?: '/usr/src/nextcloud';
$app = dirname(__DIR__, 2);
$nextcloudVersion = (static function () use ($root): string {
	require $root . '/version.php';
	return $OC_VersionString;
})();
require $root . '/3rdparty/autoload.php';
require $app . '/vendor-bin/phpunit/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($root, $app): void {
	foreach (['OC\\' => $root . '/lib/private/', 'OCP\\' => $root . '/lib/public/', 'OCA\\EducAI\\' => $app . '/lib/'] as $prefix => $directory) {
		if (str_starts_with($class, $prefix)) {
			$path = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
			if (is_file($path)) {
				require_once $path;
			}
		}
	}
});

$mocks = new class('identity-http-smoke') extends \PHPUnit\Framework\TestCase {
	public function dependency(string $type): object {
		return $this->createMock($type);
	}
};
$config = $mocks->dependency(\OCP\IConfig::class);
$config->method('getSystemValueString')->willReturn('');
$config->method('getSystemValueBool')->willReturnCallback(static fn (string $key): bool => in_array($key, ['installed', 'allow_local_remote_servers'], true));
$certificates = $mocks->dependency(\OCP\ICertificateManager::class);
$certificates->method('getAbsoluteBundlePath')->willReturn($root . '/resources/config/ca-bundle.crt');
$validator = $mocks->dependency(\OCP\Security\IRemoteHostValidator::class);
$guzzle = new \GuzzleHttp\Client(['handler' => \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\CurlHandler())]);
$dependencies = [
	\OCP\IConfig::class => $config,
	\OCP\ICertificateManager::class => $certificates,
	\GuzzleHttp\Client::class => $guzzle,
	\OCP\Security\IRemoteHostValidator::class => $validator,
	\Psr\Log\LoggerInterface::class => new \Psr\Log\NullLogger(),
];
$class = new ReflectionClass(\OC\Http\Client\Client::class);
$arguments = [];
foreach ($class->getConstructor()->getParameters() as $parameter) {
	$type = $parameter->getType()->getName();
	$arguments[] = $dependencies[$type] ?? ($type === \OCP\ServerVersion::class ? new \OCP\ServerVersion() : $mocks->dependency($type));
}
$raw = $class->newInstanceArgs($arguments);
$service = $mocks->dependency(\OCP\Http\Client\IClientService::class);
$service->method('newClient')->willReturn($raw);
$http = \OCA\EducAI\Service\IdentityHttpClient::fromService($service);
$options = ['headers' => ['User-Agent' => 'Talk-AI-Benign-Transport-Smoke'], 'timeout' => 5];

$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) {
	throw new RuntimeException('Could not allocate loopback fixture port.');
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
$origin = 'http://' . $address;
$log = tempnam(sys_get_temp_dir(), 'educai-http-');
$serverLog = tempnam(sys_get_temp_dir(), 'educai-http-server-');
putenv('EDUCAI_HTTP_FIXTURE_LOG=' . $log);
$server = proc_open([PHP_BINARY, '-S', $address, __DIR__ . '/identity-http-fixture.php'], [0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']], $pipes);
if (!is_resource($server)) {
	throw new RuntimeException('Could not start loopback fixture.');
}
fclose($pipes[0]);
$results = [];
$check = static function (string $name, callable $test) use (&$results): void {
	try {
		if ($test() !== true) {
			throw new RuntimeException('Assertion failed.');
		}
		$results[$name] = 'pass';
	} catch (Throwable $exception) {
		$results[$name] = 'FAIL: ' . get_class($exception);
	}
};

try {
	$ready = false;
	for ($attempt = 0; $attempt < 100; $attempt++) {
		$probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.05);
		if (is_resource($probe)) {
			fclose($probe);
			$ready = true;
			break;
		}
		usleep(10000);
	}
	if (!$ready) {
		throw new RuntimeException('Loopback fixture did not become ready.');
	}

	$check('baseline_native_brotli_decoding_reproduced', static function () use ($raw, $origin, $options): bool {
		$options['headers']['Accept-Encoding'] = 'br';
		return $raw->get($origin . '/br', $options)->getBody() === '{"fixture":"brotli"}';
	});
	$check('get_negotiates_identity_and_preserves_json', static function () use ($http, $origin, $options): bool {
		$body = json_decode($http->get($origin . '/echo', $options)->getBody(), true, 512, JSON_THROW_ON_ERROR);
		return $body['encoding'] === 'identity' && $body['method'] === 'GET';
	});
	$check('post_json_preserved', static function () use ($http, $origin, $options): bool {
		$options['json'] = ['fixture' => 'Unicode 🦝'];
		$body = json_decode($http->post($origin . '/echo', $options)->getBody(), true, 512, JSON_THROW_ON_ERROR);
		return $body['method'] === 'POST' && $body['encoding'] === 'identity' && json_decode($body['body'], true) === $options['json'];
	});
	$check('post_multipart_preserved', static function () use ($http, $origin, $options): bool {
		$options['multipart'] = [['name' => 'document', 'contents' => '%PDF benign', 'filename' => 'fixture.pdf']];
		$body = json_decode($http->post($origin . '/echo', $options)->getBody(), true, 512, JSON_THROW_ON_ERROR);
		return $body['method'] === 'POST' && $body['encoding'] === 'identity' && $body['file'] === '%PDF benign';
	});
	$check('delete_preserved', static fn (): bool => json_decode($http->delete($origin . '/echo', $options)->getBody(), true)['method'] === 'DELETE');
	$check('binary_preserved_byte_for_byte', static fn (): bool => $http->get($origin . '/binary', $options)->getBody() === "\x00\x01\xfffixture\r\n");
	$check('uncompressed_redirect_preserved', static fn (): bool => json_decode($http->get($origin . '/redirect', $options)->getBody(), true)['encoding'] === 'identity');
	$check('uncompressed_http_error_preserved', static function () use ($http, $origin, $options): bool {
		try {
			$http->get($origin . '/error', $options);
		} catch (\GuzzleHttp\Exception\ServerException $exception) {
			return $exception->getResponse()->getStatusCode() === 503;
		}
		return false;
	});
	$check('http_errors_false_preserved', static fn (): bool => $http->get($origin . '/error', $options + ['http_errors' => false])->getStatusCode() === 503);
	$check('chunked_sse_resource_and_payload_preserved', static function () use ($http, $origin, $options): bool {
		$response = $http->get($origin . '/sse', $options + ['stream' => true]);
		$body = $response->getBody();
		if (!is_resource($body)) {
			return false;
		}
		try {
			return stream_get_contents($body) === "data: first\n\ndata: second\n\ndata: [DONE]\n\n";
		} finally {
			fclose($body);
		}
	});

	foreach (['br', 'gzip', 'mixed', 'stacked', 'multiple', 'error-br', 'redirect-br'] as $path) {
		foreach ([false, true] as $stream) {
			$check($path . ($stream ? '_stream' : '_buffered') . '_rejected_before_consumer', static function () use ($http, $origin, $options, $path, $stream, $log): bool {
				$called = false;
				$before = count(file($log));
				$options['stream'] = $stream;
				$options['on_headers'] = static function () use (&$called): void { $called = true; };
				try {
					$http->get($origin . '/' . $path, $options);
				} catch (\OCA\EducAI\Exception\UnexpectedContentEncodingException $exception) {
					return !$called && count(file($log)) === $before + 1;
				}
				return false;
			});
		}
	}
	$check('native_curl_overrides_cannot_enable_decoding', static function () use ($http, $origin, $options): bool {
		$options['decode_content'] = true;
		$options['headers']['aCcEpT-eNcOdInG'] = 'br';
		$options['curl'] = [CURLOPT_ENCODING => 'br', CURLOPT_HTTP_CONTENT_DECODING => true];
		try {
			$http->get($origin . '/br', $options);
		} catch (\OCA\EducAI\Exception\UnexpectedContentEncodingException $exception) {
			return true;
		}
		return false;
	});
} finally {
	proc_terminate($server);
	proc_close($server);
	unlink($log);
	unlink($serverLog);
}
echo json_encode(['nextcloud' => $nextcloudVersion, 'php' => PHP_VERSION, 'curl' => curl_version()['version'], 'checks' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit(count(array_filter($results, static fn (string $result): bool => $result !== 'pass')) === 0 ? 0 : 1);
