<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Service for encrypting and decrypting sensitive credentials.
 *
 * Uses Nextcloud's ICrypto interface for encryption using the instance secret.
 * Encrypted values are prefixed with "$ENCRYPTED$" to distinguish them from
 * plaintext values (for migration purposes).
 */
class CredentialService {
    private const ENCRYPTION_PREFIX = '$ENCRYPTED$';

    private ICrypto $crypto;
    private LoggerInterface $logger;

    public function __construct(
        ICrypto $crypto,
        LoggerInterface $logger
    ) {
        $this->crypto = $crypto;
        $this->logger = $logger;
    }

    /**
     * Encrypt a credential value.
     *
     * @param string $value The plaintext value to encrypt
     * @return string The encrypted value with prefix
     */
    public function encrypt(string $value): string {
        if ($value === '') {
            return '';
        }

        // Don't double-encrypt
        if ($this->isEncrypted($value)) {
            return $value;
        }

        try {
            $encrypted = $this->crypto->encrypt($value);
            return self::ENCRYPTION_PREFIX . $encrypted;
        } catch (\Exception $e) {
            $this->logger->error('EducAI: Failed to encrypt credential', [
                'exception' => $e->getMessage(),
            ]);
            // Return empty string on encryption failure to avoid storing plaintext
            throw new \RuntimeException('Failed to encrypt credential: ' . $e->getMessage());
        }
    }

    /**
     * Decrypt a credential value.
     *
     * Handles both encrypted values (with prefix) and legacy plaintext values.
     * Legacy plaintext values are returned as-is for backward compatibility.
     *
     * @param string|null $value The value to decrypt (may be encrypted or plaintext)
     * @return string The decrypted plaintext value
     */
    public function decrypt(?string $value): string {
        if ($value === null || $value === '') {
            return '';
        }

        // Check if this is an encrypted value
        if (!$this->isEncrypted($value)) {
            // Legacy plaintext value - return as-is
            // The migration will happen when the value is next saved
            $this->logger->debug('EducAI: Found legacy unencrypted credential, will migrate on next save');
            return $value;
        }

        $encrypted = substr($value, strlen(self::ENCRYPTION_PREFIX));
        return $this->crypto->decrypt($encrypted);
    }

    /**
     * Check if a value is encrypted (has the encryption prefix).
     *
     * @param string|null $value The value to check
     * @return bool True if the value is encrypted
     */
    public function isEncrypted(?string $value): bool {
        if ($value === null || $value === '') {
            return false;
        }
        return str_starts_with($value, self::ENCRYPTION_PREFIX);
    }

    /**
     * Check if a value needs migration (is plaintext but should be encrypted).
     *
     * @param string|null $value The value to check
     * @return bool True if the value is non-empty plaintext that needs encryption
     */
    public function needsMigration(?string $value): bool {
        if ($value === null || $value === '') {
            return false;
        }
        return !$this->isEncrypted($value);
    }

    /**
     * Mask a credential value for display.
     * Returns asterisks for non-empty values, empty string for empty values.
     *
     * @param string|null $value The value to mask
     * @return string The masked value ('***' or '')
     */
    public function mask(?string $value): string {
        if ($value === null || $value === '') {
            return '';
        }
        return '***';
    }
}
