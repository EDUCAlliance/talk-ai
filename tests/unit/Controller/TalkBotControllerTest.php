<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Controller;

use OCA\EducAI\AppInfo\Application;
use OCA\EducAI\Controller\TalkBotController;
use OCA\EducAI\Db\Bot;
use OCA\EducAI\Db\BotMapper;
use OCA\EducAI\Service\BotService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TalkBotControllerTest extends TestCase {
	public function testRoomsRequiresAuthenticatedUser(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('get');
		$client->expects($this->never())->method('post');

		$controller = $this->createController($client, $this->createRequest(), null);

		$response = $controller->rooms();

		$this->assertSame(401, $response->getStatus());
		$this->assertSame('Not authenticated', $response->getData()['error']);
	}

	public function testRoomsMapsAndSortsAccessibleConversations(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('get')
			->with($this->stringContains('/ocs/v2.php/apps/spreed/api/v4/room'))
			->willReturn($this->createResponse(['ocs' => ['data' => [
				[
					'token' => 'member-room',
					'displayName' => 'Member Room',
					'type' => 2,
					'participantType' => 3,
					'readOnly' => 0,
					'lastActivity' => 300,
				],
				[
					'token' => 'mod-room',
					'displayName' => 'Moderator Room',
					'type' => 2,
					'participantType' => 2,
					'readOnly' => 0,
					'lastActivity' => 100,
				],
			]]]));

		$controller = $this->createController($client);

		$response = $controller->rooms();
		$rooms = $response->getData()['rooms'];

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('mod-room', $rooms[0]['token']);
		$this->assertTrue($rooms[0]['isModerator']);
		$this->assertSame('member-room', $rooms[1]['token']);
		$this->assertFalse($rooms[1]['isModerator']);
	}

	public function testEnableBotReturnsForbiddenForNonModeratorsWhenBotIsDisabled(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->exactly(2))
			->method('get')
			->willReturnOnConsecutiveCalls(
				$this->createResponse(['ocs' => ['data' => ['participantType' => 3]]]),
				$this->createResponse(['ocs' => ['data' => [
					['id' => 42, 'name' => Application::APP_DISPLAY_NAME, 'state' => 0],
				]]])
			);
		$client->expects($this->never())->method('post');

		$controller = $this->createController($client);

		$response = $controller->enableBot('room-token');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(
			'You do not have permission to enable bots in this conversation. Only moderators can enable bots.',
			$response->getData()['error']
		);
	}

	public function testEnableBotKeepsAlreadyEnabledResponseIdempotentForNonModerators(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->exactly(2))
			->method('get')
			->willReturnOnConsecutiveCalls(
				$this->createResponse(['ocs' => ['data' => ['participantType' => 3]]]),
				$this->createResponse(['ocs' => ['data' => [
					['id' => 42, 'name' => Application::APP_DISPLAY_NAME, 'state' => 1],
				]]])
			);
		$client->expects($this->never())->method('post');

		$controller = $this->createController($client);

		$response = $controller->enableBot('room-token');

		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
		$this->assertSame('Bot already enabled', $response->getData()['message']);
	}

	public function testStartBotChatCreatesRoomEnablesBotAndSendsMessage(): void {
		$bot = $this->createBot(12, 'Catalogue', 'catalogue');
		$botMapper = $this->createMock(BotMapper::class);
		$botMapper->expects($this->once())->method('findById')->with(12)->willReturn($bot);

		$botService = $this->createMock(BotService::class);
		$botService->expects($this->once())->method('userCanAccessBot')->with($bot, 'alice')->willReturn(true);

		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('get')
			->with($this->stringContains('/ocs/v2.php/apps/spreed/api/v1/bot/new-token'))
			->willReturn($this->createResponse(['ocs' => ['data' => [
				['id' => 42, 'name' => Application::APP_DISPLAY_NAME, 'state' => 0],
			]]]));

		$postCalls = [];
		$client->expects($this->exactly(3))
			->method('post')
			->willReturnCallback(function (string $uri, array $options) use (&$postCalls): IResponse {
				$postCalls[] = ['uri' => $uri, 'options' => $options];
				if (count($postCalls) === 1) {
					return $this->createResponse(['ocs' => ['data' => [
						'token' => 'new-token',
					]]]);
				}
				if (count($postCalls) === 2) {
					return $this->createResponse(['ocs' => ['data' => []]]);
				}
				return $this->createResponse(['ocs' => ['data' => ['id' => 456]]]);
			});

		$controller = $this->createController($client, $this->createRequest([
			'botId' => 12,
			'mode' => 'new',
			'roomName' => '',
			'message' => 'What can you help me with?',
			'sendMessage' => true,
		]), 'alice', $botMapper, $botService);

		$response = $controller->startBotChat();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('new-token', $response->getData()['roomToken']);
		$this->assertSame('https://nextcloud.local/index.php/call/new-token', $response->getData()['talkUrl']);
		$this->assertTrue($response->getData()['botEnabled']);
		$this->assertFalse($response->getData()['botWasAlreadyEnabled']);
		$this->assertTrue($response->getData()['messageSent']);
		$this->assertSame(456, $response->getData()['messageId']);
		$this->assertStringContainsString('/api/v4/room', $postCalls[0]['uri']);
		$this->assertSame(2, $postCalls[0]['options']['body']['roomType']);
		$this->assertSame('Chat with Catalogue', $postCalls[0]['options']['body']['roomName']);
		$this->assertStringContainsString('/api/v1/bot/new-token/42', $postCalls[1]['uri']);
		$this->assertStringContainsString('/api/v1/chat/new-token', $postCalls[2]['uri']);
		$this->assertSame('@catalogue What can you help me with?', $postCalls[2]['options']['body']['message']);
	}

	public function testStartBotChatExistingRoomWithoutModeratorRightsReturnsForbiddenWhenBotDisabled(): void {
		$bot = $this->createBot(12, 'Catalogue', '@catalogue');
		$botMapper = $this->createMock(BotMapper::class);
		$botMapper->method('findById')->with(12)->willReturn($bot);

		$botService = $this->createMock(BotService::class);
		$botService->method('userCanAccessBot')->with($bot, 'alice')->willReturn(true);

		$client = $this->createMock(IClient::class);
		$client->expects($this->exactly(2))
			->method('get')
			->willReturnOnConsecutiveCalls(
				$this->createResponse(['ocs' => ['data' => ['token' => 'shared-room', 'participantType' => 3]]]),
				$this->createResponse(['ocs' => ['data' => [
					['id' => 42, 'name' => Application::APP_DISPLAY_NAME, 'state' => 0],
				]]])
			);
		$client->expects($this->never())->method('post');

		$controller = $this->createController($client, $this->createRequest([
			'botId' => 12,
			'mode' => 'existing',
			'roomToken' => 'shared-room',
			'message' => '@catalogue Hello',
			'sendMessage' => true,
		]), 'alice', $botMapper, $botService);

		$response = $controller->startBotChat();

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(
			'You do not have permission to enable bots in this conversation. Only moderators can enable bots.',
			$response->getData()['error']
		);
	}

	public function testStartBotChatExistingRoomWithEnabledBotCanSendMessage(): void {
		$bot = $this->createBot(12, 'Catalogue', '@catalogue');
		$botMapper = $this->createMock(BotMapper::class);
		$botMapper->method('findById')->with(12)->willReturn($bot);

		$botService = $this->createMock(BotService::class);
		$botService->method('userCanAccessBot')->with($bot, 'alice')->willReturn(true);

		$client = $this->createMock(IClient::class);
		$client->expects($this->exactly(2))
			->method('get')
			->willReturnOnConsecutiveCalls(
				$this->createResponse(['ocs' => ['data' => ['token' => 'shared-room', 'participantType' => 3]]]),
				$this->createResponse(['ocs' => ['data' => [
					['id' => 42, 'name' => Application::APP_DISPLAY_NAME, 'state' => 1],
				]]])
			);
		$client->expects($this->once())
			->method('post')
			->with($this->stringContains('/ocs/v2.php/apps/spreed/api/v1/chat/shared-room'))
			->willReturn($this->createResponse(['ocs' => ['data' => ['id' => 789]]]));

		$controller = $this->createController($client, $this->createRequest([
			'botId' => 12,
			'mode' => 'existing',
			'roomToken' => 'shared-room',
			'message' => 'Hello there',
			'sendMessage' => true,
		]), 'alice', $botMapper, $botService);

		$response = $controller->startBotChat();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('shared-room', $response->getData()['roomToken']);
		$this->assertTrue($response->getData()['botWasAlreadyEnabled']);
		$this->assertTrue($response->getData()['messageSent']);
		$this->assertSame(789, $response->getData()['messageId']);
	}

	public function testStartBotChatReturnsUsefulErrorWhenRoomCreationFails(): void {
		$bot = $this->createBot(12, 'Catalogue', '@catalogue');
		$botMapper = $this->createMock(BotMapper::class);
		$botMapper->method('findById')->with(12)->willReturn($bot);

		$botService = $this->createMock(BotService::class);
		$botService->method('userCanAccessBot')->with($bot, 'alice')->willReturn(true);

		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('get');
		$client->expects($this->once())
			->method('post')
			->with($this->stringContains('/ocs/v2.php/apps/spreed/api/v4/room'))
			->willReturn($this->createResponse(['ocs' => ['data' => []]], 500));

		$controller = $this->createController($client, $this->createRequest([
			'botId' => 12,
			'mode' => 'new',
			'roomName' => 'Chat with Catalogue',
			'sendMessage' => false,
		]), 'alice', $botMapper, $botService);

		$response = $controller->startBotChat();

		$this->assertSame(502, $response->getStatus());
		$this->assertStringContainsString('Talk could not complete this action right now', $response->getData()['error']);
	}

	private function createController(
		IClient $client,
		?IRequest $request = null,
		?string $userId = 'alice',
		?BotMapper $botMapper = null,
		?BotService $botService = null
	): TalkBotController {
		$request ??= $this->createRequest();

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(static function (string $url): string {
			if ($url === '') {
				return 'https://nextcloud.local/';
			}
			return 'https://nextcloud.local/' . ltrim($url, '/');
		});

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		return new TalkBotController(
			'educai',
			$request,
			$clientService,
			$urlGenerator,
			$botMapper ?? $this->createMock(BotMapper::class),
			$botService ?? $this->createMock(BotService::class),
			$this->createMock(LoggerInterface::class),
			$userId
		);
	}

	private function createRequest(array $params = []): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')->willReturn('');
		$request->method('getServerHost')->willReturn('nextcloud.local');
		$request->method('getParam')->willReturnCallback(static function (string $key, $default = null) use ($params) {
			return $params[$key] ?? $default;
		});

		return $request;
	}

	private function createBot(int $id, string $botName, string $mentionName): Bot {
		$bot = new Bot();
		$bot->setId($id);
		$bot->setBotName($botName);
		$bot->setMentionName($mentionName);
		$bot->setIsActive(true);

		return $bot;
	}

	private function createResponse(array $payload, int $statusCode = 200): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(json_encode($payload));
		$response->method('getStatusCode')->willReturn($statusCode);

		return $response;
	}
}
