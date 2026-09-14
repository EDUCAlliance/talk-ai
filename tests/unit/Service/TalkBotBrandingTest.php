<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Db\SettingsMapper;
use OCA\EducAI\Service\BrandingService;
use OCA\EducAI\Service\CredentialService;
use OCA\EducAI\Service\TalkBotRegistrationService;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TalkBotBrandingTest extends TestCase {
	public function testManagedBotNameUpdatesInPlaceWithoutChangingActivationOrIdentity(): void {
		$bot = new class {
			public string $name = 'EDUC AI';
			public int $state = 0;
			public array $rooms = ['room-a', 'room-b'];
			public function jsonSerialize(): array { return ['id' => 42, 'name' => $this->name]; }
			public function getName(): string { return $this->name; }
			public function setName(string $name): void { $this->name = $name; }
		};
		$mapper = new class($bot) {
			public int $updates = 0;
			public function __construct(public object $bot) {}
			public function findById(int $id): object {
				if ($id !== 42) {
					throw new \RuntimeException('Unexpected ID');
				}
				return $this->bot;
			}
			public function update(object $bot): void { $this->updates++; }
		};
		$service = $this->createService($mapper);
		$this->assertSame('updated', $service->updateManagedDisplayName()['status']);
		$this->assertSame('Campus Assistant', $bot->name);
		$this->assertSame(0, $bot->state);
		$this->assertSame(['room-a', 'room-b'], $bot->rooms);
		$this->assertSame(1, $mapper->updates);
		$this->assertSame('updated', $service->updateManagedDisplayName()['status']);
		$this->assertSame(1, $mapper->updates);
	}

	public function testMissingMapperReportsUnappliedTalkName(): void {
		$service = $this->createService(null);
		$this->assertSame('error', $service->updateManagedDisplayName()['status']);
	}

	private function createService(?object $mapper): TalkBotRegistrationService {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static fn (string $app, string $key, string $default): string => [
			'talk_bot_id' => '42',
			'talk_bot_url' => 'https://nextcloud.example/apps/educai/webhook/talk',
			'display_name' => 'Campus Assistant',
		][$key] ?? $default);
		$service = $this->getMockBuilder(TalkBotRegistrationService::class)
			->setConstructorArgs([
				$this->createMock(SettingsMapper::class),
				$this->createMock(CredentialService::class),
				$this->createMock(IAppManager::class),
				$config,
				$this->createMock(IRequest::class),
				$this->createMock(IURLGenerator::class),
				$this->createMock(IClientService::class),
				$this->createMock(LoggerInterface::class),
				new BrandingService($config),
			])
			->onlyMethods(['getTalkBotServerMapper', 'isTalkAvailable'])->getMock();
		$service->method('getTalkBotServerMapper')->willReturn($mapper);
		$service->method('isTalkAvailable')->willReturn(true);
		return $service;
	}
}
