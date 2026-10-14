<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use OCA\EducAI\AppInfo\Application;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Remembers the last explicit administrator connection check per service.
 *
 * Only safe display fields and a fingerprint are stored. The fingerprint hashes
 * the effective target and a hash of the effective key, so a check can later be
 * compared with the saved settings without storing or revealing a credential.
 */
class ConnectionCheckStore {
	public const SERVICES = ['model', 'docling', 'vision', 'speech', 'catalogue'];

	private const KEY_PREFIX = 'connection_check_';
	// Model references may be up to 512 bytes and are used as the model summary.
	private const MAX_SUMMARY_LENGTH = 512;
	private const MAX_ERROR_LENGTH = 500;

	public function __construct(
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param array<int,mixed> $target Effective endpoint, profile, authentication and model values
	 */
	public static function fingerprint(string $service, array $target, ?string $secret): string {
		$encoded = json_encode($target, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		return hash('sha256', $service . "\0" . ($encoded === false ? '' : $encoded) . "\0" . hash('sha256', (string)$secret));
	}

	/**
	 * Remember a check. A storage failure never changes the reported test result.
	 */
	public function record(string $service, bool $success, ?int $durationMs, ?string $summary, ?string $error, ?string $fingerprint): void {
		if (!in_array($service, self::SERVICES, true)) {
			throw new \InvalidArgumentException('Unknown connection check service');
		}
		$record = [
			'success' => $success,
			'checked_at' => time(),
			'duration_ms' => $durationMs === null ? null : max(0, $durationMs),
			'summary' => self::clean($summary, self::MAX_SUMMARY_LENGTH),
			'error' => $success ? null : self::clean($error, self::MAX_ERROR_LENGTH),
			'fingerprint' => self::isFingerprint($fingerprint) ? $fingerprint : null,
		];
		try {
			$this->config->setAppValue(Application::APP_ID, self::KEY_PREFIX . $service, json_encode($record, JSON_THROW_ON_ERROR));
		} catch (\Throwable $e) {
			$this->logger->warning('The connection check result could not be remembered', ['service' => $service]);
		}
	}

	/**
	 * @return array{success:bool,checked_at:int,duration_ms:?int,summary:?string,error:?string,fingerprint:?string}|null
	 */
	public function read(string $service): ?array {
		if (!in_array($service, self::SERVICES, true)) {
			return null;
		}
		$record = json_decode($this->config->getAppValue(Application::APP_ID, self::KEY_PREFIX . $service, ''), true);
		if (!is_array($record) || !is_bool($record['success'] ?? null) || !is_int($record['checked_at'] ?? null)) {
			return null;
		}
		$durationMs = $record['duration_ms'] ?? null;
		$fingerprint = $record['fingerprint'] ?? null;
		return [
			'success' => $record['success'],
			'checked_at' => $record['checked_at'],
			'duration_ms' => is_int($durationMs) && $durationMs >= 0 ? $durationMs : null,
			'summary' => self::clean(is_string($record['summary'] ?? null) ? $record['summary'] : null, self::MAX_SUMMARY_LENGTH),
			'error' => $record['success'] ? null : self::clean(is_string($record['error'] ?? null) ? $record['error'] : null, self::MAX_ERROR_LENGTH),
			'fingerprint' => self::isFingerprint($fingerprint) ? $fingerprint : null,
		];
	}

	/**
	 * Public check shape. "current" means the saved settings still resolve to
	 * exactly the endpoint, options and key that were tested.
	 *
	 * @param array{success:bool,checked_at:int,duration_ms:?int,summary:?string,error:?string,fingerprint:?string} $record
	 * @return array{success:bool,checked_at:int,duration_ms:?int,summary:?string,error:?string,current:bool}
	 */
	public static function present(array $record, ?string $currentFingerprint): array {
		return [
			'success' => $record['success'],
			'checked_at' => $record['checked_at'],
			'duration_ms' => $record['duration_ms'],
			'summary' => $record['summary'],
			'error' => $record['error'],
			'current' => $record['fingerprint'] !== null && $currentFingerprint !== null
				&& hash_equals($record['fingerprint'], $currentFingerprint),
		];
	}

	private static function isFingerprint(mixed $value): bool {
		return is_string($value) && preg_match('/^[0-9a-f]{64}$/D', $value) === 1;
	}

	private static function clean(?string $value, int $maxLength): ?string {
		if ($value === null) {
			return null;
		}
		$value = trim((string)preg_replace('/\p{Cc}+/u', ' ', mb_scrub($value, 'UTF-8')));
		return $value === '' ? null : mb_substr($value, 0, $maxLength, 'UTF-8');
	}
}
