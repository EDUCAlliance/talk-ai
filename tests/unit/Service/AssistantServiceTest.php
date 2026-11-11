<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Db\Bot;
use OCA\EducAI\Exception\AssistantExistsException;
use OCA\EducAI\Service\AssistantService;
use OCA\EducAI\Service\BotService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AssistantServiceTest extends TestCase {
	/** @var array<string,string> */
	private array $userValues = [];

	public function testNobodyHasAnAssistantBeforeSettingOneUp(): void {
		$service = new AssistantService($this->createBotService(), $this->createConfig());

		$this->assertNull($service->findAssistant('alice'));
		$this->assertNull($service->getAssistantId('alice'));
	}

	public function testCreatesAPersonalBotWithItsOwnMentionAndRemembersIt(): void {
		$created = [];
		$botService = $this->createBotService();
		$botService->expects($this->once())
			->method('createBot')
			->willReturnCallback(function (...$arguments) use (&$created): Bot {
				$created = $arguments;
				return $this->createBot(31, (string)$arguments[0], 'personal', '@' . $arguments[2]);
			});
		$service = new AssistantService($botService, $this->createConfig());

		$bot = $service->createAssistant('alice', 'Personal assistant', 'Help me.', null, null, false, [['is_builtin' => true, 'builtin_name' => 'room_search_documents']], 'Just for me');

		$this->assertSame(31, $bot->getId());
		$this->assertSame('alice', $created[0]);
		$this->assertSame('Personal assistant', $created[1]);
		$this->assertSame('assistant-alice', $created[2]);
		$this->assertSame('Help me.', $created[3]);
		$this->assertFalse($created[4], 'the assistant is never public');
		$this->assertSame('personal', $created[7]);
		$this->assertNull($created[8]);
		$this->assertNull($created[9]);
		$this->assertSame([['is_builtin' => true, 'builtin_name' => 'room_search_documents']], $created[11]);
		$this->assertSame('Just for me', $created[12]);
		$this->assertSame('31', $this->userValues['alice/educai/assistant_bot_id']);
	}

	public function testMentionUsesOnlyAllowedCharactersAndAvoidsTakenNames(): void {
		$taken = ['@assistant-pascal-kienast-uni-potsdam-de', '@assistant-pascal-kienast-uni-potsdam-de-2'];
		$mention = null;
		$botService = $this->createBotService($taken);
		$botService->method('createBot')
			->willReturnCallback(function (...$arguments) use (&$mention): Bot {
				$mention = $arguments[2];
				return $this->createBot(5, (string)$arguments[0], 'personal', '@' . $arguments[2]);
			});
		$service = new AssistantService($botService, $this->createConfig());

		$service->createAssistant('Pascal.Kienast@uni-potsdam.de', 'Personal assistant', 'Help me.');

		$this->assertSame('assistant-pascal-kienast-uni-potsdam-de-3', $mention);
		$this->assertMatchesRegularExpression('/^[a-z0-9_-]+$/', (string)$mention);
	}

	public function testUserIdsWithoutUsableCharactersStillGetAMention(): void {
		$mention = null;
		$botService = $this->createBotService();
		$botService->method('createBot')
			->willReturnCallback(function (...$arguments) use (&$mention): Bot {
				$mention = $arguments[2];
				return $this->createBot(6, (string)$arguments[0], 'personal', '@' . $arguments[2]);
			});
		$service = new AssistantService($botService, $this->createConfig());

		$service->createAssistant('***', 'Personal assistant', 'Help me.');

		$this->assertMatchesRegularExpression('/^assistant-[a-f0-9]{12}$/', (string)$mention);
	}

	public function testLongUserIdsStayWithinTheMentionColumn(): void {
		$mention = null;
		$longId = str_repeat('abcdefghij', 20);
		$botService = $this->createBotService(['@assistant-' . substr($longId, 0, 60)]);
		$botService->method('createBot')
			->willReturnCallback(function (...$arguments) use (&$mention): Bot {
				$mention = $arguments[2];
				return $this->createBot(8, (string)$arguments[0], 'personal', '@' . $arguments[2]);
			});
		$service = new AssistantService($botService, $this->createConfig());

		$service->createAssistant($longId, 'Personal assistant', 'Help me.');

		$this->assertLessThanOrEqual(99, strlen((string)$mention));
		$this->assertStringEndsWith('-2', (string)$mention);
	}

	public function testAUserHasAtMostOneAssistant(): void {
		$botService = $this->createBotService([], [31 => $this->createBot(31, 'alice', 'personal')]);
		$botService->expects($this->never())->method('createBot');
		$service = new AssistantService($botService, $this->createConfig(['alice/educai/assistant_bot_id' => '31']));

		$this->expectException(AssistantExistsException::class);
		$service->createAssistant('alice', 'Personal assistant', 'Help me.');
	}

	public function testARememberedBotOnlyCountsWhileItIsThePersonalBotOfThatUser(): void {
		$bots = [
			31 => $this->createBot(31, 'alice', 'groups'),
			32 => $this->createBot(32, 'bob', 'personal'),
			33 => $this->createBot(33, 'alice', 'personal'),
		];
		foreach (['31' => null, '32' => null, '33' => 33, '99' => null, '' => null, 'x' => null] as $stored => $expected) {
			$service = new AssistantService(
				$this->createBotService([], $bots),
				$this->createConfig(['alice/educai/assistant_bot_id' => (string)$stored]),
			);

			$this->assertSame($expected, $service->getAssistantId('alice'), 'stored value "' . $stored . '"');
		}
	}

	public function testMarksOnlyTheAssistantInAList(): void {
		$service = new AssistantService(
			$this->createBotService([], [33 => $this->createBot(33, 'alice', 'personal')]),
			$this->createConfig(['alice/educai/assistant_bot_id' => '33']),
		);

		$marked = $service->markAssistant('alice', [['id' => 12], ['id' => 33], ['id' => '33'], []]);

		$this->assertSame([false, true, true, false], array_column($marked, 'is_assistant'));
		$this->assertSame([false], array_column($service->markAssistant('bob', [['id' => 33]]), 'is_assistant'));
	}

	public function testForgetsTheAssistantOnlyWhenItsBotIsDeleted(): void {
		$config = $this->createConfig(['alice/educai/assistant_bot_id' => '33']);
		$service = new AssistantService($this->createBotService(), $config);

		$service->forget('alice', 12);
		$this->assertSame('33', $this->userValues['alice/educai/assistant_bot_id']);

		$service->forget('alice', 33);
		$this->assertArrayNotHasKey('alice/educai/assistant_bot_id', $this->userValues);
	}

	/**
	 * @param array<int,string> $takenMentions
	 * @param array<int,Bot> $bots
	 */
	private function createBotService(array $takenMentions = [], array $bots = []): BotService&MockObject {
		$botService = $this->createMock(BotService::class);
		$botService->method('findByMentionName')
			->willReturnCallback(function (string $mention) use ($takenMentions): Bot {
				if (!in_array($mention, $takenMentions, true)) {
					throw new DoesNotExistException('free');
				}
				return $this->createBot(1, 'someone', 'groups', $mention);
			});
		$botService->method('getBot')
			->willReturnCallback(static function (int $id) use ($bots): Bot {
				return $bots[$id] ?? throw new DoesNotExistException('gone');
			});

		return $botService;
	}

	/**
	 * @param array<string,string> $values
	 */
	private function createConfig(array $values = []): IConfig {
		$this->userValues = $values;
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')
			->willReturnCallback(fn (string $userId, string $app, string $key, $default = '') => $this->userValues["$userId/$app/$key"] ?? $default);
		$config->method('setUserValue')
			->willReturnCallback(function (string $userId, string $app, string $key, $value): void {
				$this->userValues["$userId/$app/$key"] = (string)$value;
			});
		$config->method('deleteUserValue')
			->willReturnCallback(function (string $userId, string $app, string $key): void {
				unset($this->userValues["$userId/$app/$key"]);
			});

		return $config;
	}

	private function createBot(int $id, string $userId, string $visibility, string $mention = '@bot'): Bot {
		$bot = new Bot();
		$bot->setId($id);
		$bot->setUserId($userId);
		$bot->setVisibility($visibility);
		$bot->setMentionName($mention);

		return $bot;
	}
}
