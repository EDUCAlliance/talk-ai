<?php

declare(strict_types=1);

namespace OCA\EducAI\OpenApi;

use OCA\EducAI\AppInfo\Application;
use OCP\IConfig;

/**
 * Network targets for API connections. Production targets are public HTTPS
 * origins; Nextcloud's client additionally pins DNS and refuses local
 * addresses at connect time. Plain HTTP and local literal hosts are only for
 * disposable test instances and require an explicit occ flag.
 */
class ApiNetworkPolicy {
	public const CONFIG_ALLOW_TEST_TARGETS = 'openapi_allow_insecure_test_targets';

	private const BLOCKED_SUFFIXES = ['.localhost', '.local', '.internal', '.lan', '.home.arpa', '.localdomain'];

	public function __construct(
		private IConfig $config,
	) {
	}

	public function allowsTestTargets(): bool {
		return $this->config->getAppValue(Application::APP_ID, self::CONFIG_ALLOW_TEST_TARGETS, 'no') === 'yes';
	}

	/** Nextcloud's own local-address protection is disabled instance-wide. */
	public function localRemoteServersAllowed(): bool {
		return $this->config->getSystemValueBool('allow_local_remote_servers', false);
	}

	/**
	 * @throws \InvalidArgumentException with the reason code as message
	 */
	public function normalizeTarget(string $url): string {
		$allowTest = $this->allowsTestTargets();
		$parsed = ApiUrl::parseBase($url, $allowTest);
		if ($parsed === null) {
			throw new \InvalidArgumentException(ApiUrl::parseBase($url, true) !== null ? 'base_url_https_required' : 'base_url_invalid');
		}
		if (!$allowTest && self::isBlockedHost($parsed['host'])) {
			throw new \InvalidArgumentException('base_url_private_host');
		}
		return $parsed['base'];
	}

	public static function isBlockedHost(string $host): bool {
		$host = strtolower(ApiUrl::hostForClassification($host));
		if ($host === 'localhost' || !str_contains($host, '.') && filter_var($host, FILTER_VALIDATE_IP) === false) {
			return true;
		}
		foreach (self::BLOCKED_SUFFIXES as $suffix) {
			if (str_ends_with($host, $suffix)) {
				return true;
			}
		}
		if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
			return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
				|| self::isSpecialIp($host);
		}
		// Numeric shorthands such as 2130706433 or 0x7f.1 are resolved as addresses by some resolvers.
		return preg_match('/^(?:0x[0-9a-f]*|[0-9]+)(?:\.(?:0x[0-9a-f]*|[0-9]+))*$/iD', $host) === 1;
	}

	private static function isSpecialIp(string $ip): bool {
		if (str_contains($ip, ':')) {
			$lower = strtolower($ip);
			return str_starts_with($lower, 'fe8') || str_starts_with($lower, 'fe9') || str_starts_with($lower, 'fea') || str_starts_with($lower, 'feb')
				|| str_starts_with($lower, 'fc') || str_starts_with($lower, 'fd') || str_starts_with($lower, '::ffff:') || str_starts_with($lower, '64:ff9b:')
				|| $lower === '::' || $lower === '::1';
		}
		$long = ip2long($ip);
		if ($long === false) {
			return true;
		}
		foreach ([['100.64.0.0', 10], ['192.0.0.0', 24], ['198.18.0.0', 15], ['0.0.0.0', 8]] as [$network, $bits]) {
			$mask = -1 << (32 - $bits);
			if (($long & $mask) === (ip2long($network) & $mask)) {
				return true;
			}
		}
		return false;
	}
}
