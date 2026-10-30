<?php

declare(strict_types=1);

/**
 * OpenAPI tool transport through Nextcloud's real HTTP client stack
 * (ClientService, DNS pinning, remote host validation, Guzzle/cURL) without
 * an installed Nextcloud or DB. Run in a disposable, network-isolated image.
 * DNS answers are fixed by overriding DnsPinMiddleware::dnsGetRecord(); the
 * classification, pinning and the actual connection stay Nextcloud's own.
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

use OCA\EducAI\OpenApi\ApiExecutionException;
use OCA\EducAI\OpenApi\ApiHttpTransport;
use OCA\EducAI\OpenApi\ApiNetworkPolicy;

const SMOKE_TOKEN = 'synthetic-smoke-token';
const REBIND_HOST = 'api.rebind.example';

$mocks = new class('openapi-transport-smoke') extends \PHPUnit\Framework\TestCase {
	public function dependency(string $type): object {
		return $this->createMock($type);
	}
};

/** @param array<string,object> $known */
$construct = static function (string $class, array $known) use ($mocks): object {
	$reflection = new ReflectionClass($class);
	$arguments = [];
	foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
		$type = $parameter->getType()->getName();
		$arguments[] = $known[$type] ?? ($type === 'OCP\\ServerVersion' ? new \OCP\ServerVersion() : $mocks->dependency($type));
	}
	return $reflection->newInstanceArgs($arguments);
};

$testTargets = false;
$config = $mocks->dependency(\OCP\IConfig::class);
$config->method('getSystemValueBool')->willReturnCallback(static fn (string $key, bool $default = false): bool => $key === 'installed' ? true : ($key === 'allow_local_remote_servers' ? false : $default));
$config->method('getSystemValueString')->willReturnCallback(static fn (string $key, string $default = ''): string => $default);
$config->method('getSystemValue')->willReturnCallback(static fn (string $key, mixed $default = ''): mixed => $default);
$config->method('getAppValue')->willReturnCallback(static function (string $app, string $key, string $default = '') use (&$testTargets): string {
	return $key === ApiNetworkPolicy::CONFIG_ALLOW_TEST_TARGETS && $testTargets ? 'yes' : $default;
});
$certificates = $mocks->dependency(\OCP\ICertificateManager::class);
$certificates->method('getAbsoluteBundlePath')->willReturn($root . '/resources/config/ca-bundle.crt');
$cacheFactory = $mocks->dependency(\OCP\ICacheFactory::class);
$cacheFactory->method('createLocal')->willReturnCallback(static fn (): \OCP\ICache => new \OC\Memcache\ArrayCache());
$logger = new \Psr\Log\NullLogger();
$ipClassifier = new \OC\Net\IpAddressClassifier();
$known = [
	\OCP\IConfig::class => $config,
	\OCP\ICertificateManager::class => $certificates,
	\OCP\ICacheFactory::class => $cacheFactory,
	\Psr\Log\LoggerInterface::class => $logger,
	\OC\Net\IpAddressClassifier::class => $ipClassifier,
	\OC\Net\HostnameClassifier::class => new \OC\Net\HostnameClassifier(),
];
$known[\OC\Http\Client\NegativeDnsCache::class] = $construct(\OC\Http\Client\NegativeDnsCache::class, $known);

$dnsAnswers = [];
$dnsArguments = [];
foreach ((new ReflectionClass(\OC\Http\Client\DnsPinMiddleware::class))->getConstructor()->getParameters() as $parameter) {
	$dnsArguments[] = $known[$parameter->getType()->getName()];
}
$dns = new class($dnsAnswers, ...$dnsArguments) extends \OC\Http\Client\DnsPinMiddleware {
	/** @param array<string,string> $answers */
	public function __construct(
		private array &$answers,
		mixed ...$parent,
	) {
		parent::__construct(...$parent);
	}

	protected function dnsGetRecord(string $hostname, int $type): array|false {
		$ip = $this->answers[rtrim($hostname, '.')] ?? null;
		return $ip !== null && $type === DNS_A ? [['type' => 'A', 'ip' => $ip]] : false;
	}
};
$known[\OC\Http\Client\DnsPinMiddleware::class] = $dns;
$known[\OCP\Security\IRemoteHostValidator::class] = $construct(\OC\Security\RemoteHostValidator::class, $known);
$clientService = $construct(\OC\Http\Client\ClientService::class, $known);
$transport = new ApiHttpTransport($clientService, new ApiNetworkPolicy($config), $logger);

$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) {
	throw new RuntimeException('Could not allocate loopback fixture port.');
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
$port = (int)substr($address, strrpos($address, ':') + 1);
$base = 'http://' . $address . '/v1';
$requestLog = tempnam(sys_get_temp_dir(), 'educai-openapi-');
$serverLog = tempnam(sys_get_temp_dir(), 'educai-openapi-server-');
$server = proc_open(
	[PHP_BINARY, '-S', $address, __DIR__ . '/openapi-http-fixture.php'],
	[0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']],
	$pipes,
	null,
	['EDUCAI_OPENAPI_FIXTURE_LOG' => $requestLog, 'PHP_CLI_SERVER_WORKERS' => '4'] + getenv(),
);
if (!is_resource($server)) {
	throw new RuntimeException('Could not start loopback fixture.');
}
fclose($pipes[0]);

$requests = static fn (): int => count(file($requestLog, FILE_SKIP_EMPTY_LINES));
// The built-in server logs every accepted connection, asynchronously.
$serverLines = static function () use ($serverLog): int {
	$count = -1;
	for ($attempt = 0; $attempt < 20; $attempt++) {
		clearstatcache(true, $serverLog);
		$previous = $count;
		$count = count(file($serverLog, FILE_SKIP_EMPTY_LINES));
		if ($count === $previous) {
			break;
		}
		usleep(150000);
	}
	return $count;
};
$failure = static function (callable $call): ?ApiExecutionException {
	try {
		$call();
	} catch (ApiExecutionException $e) {
		return $e;
	}
	return null;
};
$results = [];
$check = static function (string $name, callable $test) use (&$results): void {
	try {
		$results[$name] = $test() === true ? 'pass' : 'FAIL: assertion';
	} catch (Throwable $exception) {
		$results[$name] = 'FAIL: ' . get_class($exception);
	}
};
$get = static fn (string $path, float $timeout = 5.0, array $headers = []): array => $transport->getJson($base . $path, $base, $headers, $timeout, ['connection_id' => 1]);
$fails = static fn (string $path, string $code, float $timeout = 5.0): bool => $failure(static fn () => $get($path, $timeout))?->getErrorCode() === $code;
// The fixture delays these bodies by 3 s (streamed: 2 MiB over about 3.2 s).
$failsWithin = static function (string $path, string $code, float $seconds) use ($fails): bool {
	$start = microtime(true);
	return $fails($path, $code) && microtime(true) - $start < $seconds;
};

try {
	$ready = false;
	for ($attempt = 0; $attempt < 100 && !$ready; $attempt++) {
		$probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.05);
		if (is_resource($probe)) {
			fclose($probe);
			$ready = true;
		} else {
			usleep(10000);
		}
	}
	if (!$ready) {
		throw new RuntimeException('Loopback fixture did not become ready.');
	}

	$testTargets = false;
	$check('loopback_base_refused_without_test_flag', static function () use ($failure, $get, $requests): bool {
		$before = $requests();
		return $failure(static fn () => $get('/courses'))?->getErrorCode() === 'target_not_allowed' && $requests() === $before;
	});
	$check('dns_answer_to_loopback_refused_by_nextcloud_before_connecting', static function () use (&$dnsAnswers, $transport, $failure, $port, $requests, $serverLines): bool {
		$dnsAnswers[REBIND_HOST] = '127.0.0.1';
		$rebound = 'https://' . REBIND_HOST . ':' . $port . '/v1';
		[$beforeRequests, $beforeLines] = [$requests(), $serverLines()];
		$error = $failure(static fn () => $transport->getJson($rebound . '/courses', $rebound, ['Authorization' => 'Bearer ' . SMOKE_TOKEN], 3.0));
		return $error?->getErrorCode() === 'target_not_allowed' && !$error->wasRequestSent()
			&& $requests() === $beforeRequests && $serverLines() === $beforeLines;
	});
	$check('connection_uses_the_pinned_public_answer_not_the_system_resolver', static function () use (&$dnsAnswers, $transport, $failure, $port, $requests, $serverLines): bool {
		$dnsAnswers[REBIND_HOST] = '93.184.215.14';
		$pinned = 'https://' . REBIND_HOST . ':' . $port . '/v1';
		[$beforeRequests, $beforeLines] = [$requests(), $serverLines()];
		$error = $failure(static fn () => $transport->getJson($pinned . '/courses', $pinned, ['Authorization' => 'Bearer ' . SMOKE_TOKEN], 3.0));
		return in_array($error?->getErrorCode(), ['transport_error', 'timeout'], true)
			&& $requests() === $beforeRequests && $serverLines() === $beforeLines;
	});

	$testTargets = true;
	$check('get_sends_exact_url_auth_and_identity_encoding', static function () use ($transport, $base): bool {
		$response = $transport->getJson($base . '/courses?q=a%26b&tags=x%2Cy', $base, ['Authorization' => 'Bearer ' . SMOKE_TOKEN], 5.0);
		$request = $response['data']['request'];
		return $response['status'] === 200 && $request['method'] === 'GET'
			&& $request['uri'] === '/v1/courses?q=a%26b&tags=x%2Cy'
			&& $request['authorization'] === 'Bearer ' . SMOKE_TOKEN
			&& $request['accept'] === 'application/json'
			&& $request['accept_encoding'] === 'identity';
	});
	$check('encoded_slash_in_path_value_stays_one_segment', static fn (): bool => $get('/courses/a%2Fb')['data']['request']['uri'] === '/v1/courses/a%2Fb');
	$check('vendor_json_media_type_accepted', static fn (): bool => $get('/vendor')['data'] === ['data' => []]);
	$check('target_outside_approved_base_never_requested', static function () use ($transport, $failure, $base, $requests): bool {
		$before = $requests();
		$outside = substr($base, 0, -3) . '/v2/courses';
		return $failure(static fn () => $transport->getJson($outside, $base, [], 5.0))?->getErrorCode() === 'target_outside_base' && $requests() === $before;
	});
	$check('redirect_reported_and_not_followed', static function () use ($fails, $requests): bool {
		$before = $requests();
		return $fails('/redirect', 'redirect_blocked') && $requests() === $before + 1;
	});
	$check('unauthorized_is_auth_rejected', static fn (): bool => $fails('/unauthorized', 'auth_rejected'));
	$check('rate_limit_reports_retry_after', static fn (): bool => $failure(static fn () => $get('/rate-limited'))?->getDetails() === ['http_status' => 429, 'retry_after_seconds' => 7]);
	$check('server_error_refused_from_headers_and_body_not_echoed', static function () use ($failure, $get): bool {
		$start = microtime(true);
		$error = $failure(static fn () => $get('/broken'));
		return $error?->getErrorCode() === 'upstream_error' && $error->wasRequestSent()
			&& !str_contains($error->getMessage(), 'Ignore') && microtime(true) - $start < 1.5;
	});
	$check('wrong_content_type_refused_from_headers', static fn (): bool => $failsWithin('/html', 'response_not_json', 1.5));
	$check('invalid_json_reported', static fn (): bool => $fails('/invalid', 'response_invalid_json'));
	$check('json_depth_limited', static fn (): bool => $fails('/deep', 'response_too_deep'));
	$check('compressed_response_rejected', static fn (): bool => $fails('/gzip', 'response_compressed'));
	$check('declared_oversize_refused_from_headers', static fn (): bool => $failsWithin('/declared-large', 'response_too_large', 1.5));
	$check('streamed_oversize_aborted_during_transfer', static fn (): bool => $failsWithin('/streamed-large', 'response_too_large', 2.6));
	$check('document_fetch_sends_no_credentials', static fn (): bool => json_decode($transport->fetchDocument($base . '/openapi.json'), true)['openapi'] === '3.0.3');
	$check('timeout_reported', static function () use ($fails): bool {
		$start = microtime(true);
		return $fails('/slow', 'timeout', 1.0) && microtime(true) - $start < 2.5;
	});
} finally {
	proc_terminate($server);
	proc_close($server);
	unlink($requestLog);
	unlink($serverLog);
}
echo json_encode(['nextcloud' => $nextcloudVersion, 'php' => PHP_VERSION, 'curl' => curl_version()['version'], 'checks' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit(count(array_filter($results, static fn (string $result): bool => $result !== 'pass')) === 0 ? 0 : 1);
