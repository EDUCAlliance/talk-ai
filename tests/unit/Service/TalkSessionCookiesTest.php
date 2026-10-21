<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Controller\TalkBotController;
use OCA\EducAI\Service\TalkBotRegistrationService;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TalkSessionCookiesTest extends TestCase {
	#[DataProvider('cookieTargets')]
	public function testSessionCookiesUseOcsTargetHostname(string $class, string $baseUrl, string $expectedHost): void {
		$originalCookies = $_COOKIE;
		$_COOKIE = ['nc_session' => 'synthetic-session', 'nc_sameSiteCookielax' => 'true'];
		try {
			$instance = $this->service($class, $baseUrl);
			$jar = (new \ReflectionMethod($class, 'getForwardedCookies'))->invoke($instance);
			$cookies = $this->cookieData($jar);
			$this->assertCount(2, $cookies);
			$this->assertSame(['nc_session', 'nc_sameSiteCookielax'], array_column($cookies, 'Name'));
			$this->assertSame(['synthetic-session', 'true'], array_column($cookies, 'Value'));
			$this->assertSame([$expectedHost, $expectedHost], array_column($cookies, 'Domain'));
			$this->assertSame(['/', '/'], array_column($cookies, 'Path'));
			if (method_exists($jar, 'withCookieHeader')) {
				$request = new \GuzzleHttp\Psr7\Request('GET', $baseUrl . 'ocs/v2.php/apps/spreed/api/v4/room');
				$this->assertSame('nc_session=synthetic-session; nc_sameSiteCookielax=true', $jar->withCookieHeader($request)->getHeaderLine('Cookie'));
				$otherHost = $request->withUri(new \GuzzleHttp\Psr7\Uri('https://unrelated.example/ocs/'));
				$this->assertSame('', $jar->withCookieHeader($otherHost)->getHeaderLine('Cookie'));
			}
		} finally {
			$_COOKIE = $originalCookies;
		}
	}

	public static function cookieTargets(): iterable {
		foreach ([TalkBotController::class, TalkBotRegistrationService::class] as $class) {
			foreach ([
				'hostname' => ['https://nextcloud.example/', 'nextcloud.example'],
				'hostname with port' => ['https://nextcloud.example:8443/', 'nextcloud.example'],
				'IPv4 with port' => ['http://127.0.0.1:18136/', '127.0.0.1'],
				'IPv6' => ['http://[::1]/', '[::1]'],
				'IPv6 with port' => ['https://[2001:db8::1]:8443/', '[2001:db8::1]'],
				'proxy webroot' => ['https://cloud.example:8443/nextcloud/', 'cloud.example'],
				'localhost' => ['http://localhost:8080/nextcloud/', 'localhost'],
			] as $label => [$baseUrl, $host]) {
				yield $class . ' ' . $label => [$class, $baseUrl, $host];
			}
		}
	}

	public function testInvalidTargetDoesNotCreateUnscopedCookies(): void {
		$originalCookies = $_COOKIE;
		$_COOKIE = ['nc_session' => 'synthetic-session'];
		try {
			foreach ([TalkBotController::class, TalkBotRegistrationService::class] as $class) {
				$instance = $this->service($class, '/relative/');
				$jar = (new \ReflectionMethod($class, 'getForwardedCookies'))->invoke($instance);
				$this->assertSame([], $this->cookieData($jar));
			}
		} finally {
			$_COOKIE = $originalCookies;
		}
	}

	private function service(string $class, string $baseUrl): object {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->with('')->willReturn($baseUrl);
		$request = $this->createMock(IRequest::class);
		$request->expects($this->never())->method('getServerHost');
		$reflection = new \ReflectionClass($class);
		$instance = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty('urlGenerator')->setValue($instance, $urlGenerator);
		$reflection->getProperty($class === TalkBotController::class ? 'ncRequest' : 'request')->setValue($instance, $request);
		return $instance;
	}

	private function cookieData(\GuzzleHttp\Cookie\CookieJar $jar): array {
		if (method_exists($jar, 'toArray')) {
			return $jar->toArray();
		}
		// The standalone unit bootstrap supplies a minimal Guzzle double.
		return array_map(static fn ($cookie): array => $cookie->data, $jar->cookies);
	}
}
