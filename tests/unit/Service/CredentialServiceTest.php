<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Service\CredentialService;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CredentialServiceTest extends TestCase {
	public function testDecryptThrowsWhenEncryptedValueCannotBeDecrypted(): void {
		$crypto = new class implements ICrypto {
			public function encrypt($data): string {
				return 'encrypted';
			}

			public function decrypt($data): string {
				throw new \RuntimeException('bad ciphertext');
			}
		};

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())
			->method('error');

		$service = new CredentialService($crypto, $logger);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('bad ciphertext');

		$service->decrypt('$ENCRYPTED$invalid');
	}

	public function testDecryptStillReturnsLegacyPlaintext(): void {
		$crypto = new class implements ICrypto {
			public function encrypt($data): string {
				return 'encrypted';
			}

			public function decrypt($data): string {
				throw new \RuntimeException('decrypt should not be called');
			}
		};

		$service = new CredentialService($crypto, $this->createMock(LoggerInterface::class));

		$this->assertSame('legacy-secret', $service->decrypt('legacy-secret'));
	}
}
