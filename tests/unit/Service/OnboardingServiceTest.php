<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Db\Bot;
use OCA\EducAI\Db\BotMapper;
use OCA\EducAI\Db\ChatRoom;
use OCA\EducAI\Db\ChatRoomMapper;
use OCA\EducAI\Db\ConversationMapper;
use OCA\EducAI\Service\OnboardingService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OnboardingServiceTest extends TestCase {
	public function testInitializeRoomDeactivatesExistingAlwaysModeBots(): void {
		$chatRoomMapper = $this->createMock(ChatRoomMapper::class);
		$existingAlwaysRoom = $this->createAlwaysModeRoom(1, 'room-token');

		$chatRoomMapper->expects($this->once())
			->method('findAlwaysModeByRoom')
			->with('room-token')
			->willReturn([$existingAlwaysRoom]);

		$chatRoomMapper->expects($this->once())
			->method('update')
			->with($this->callback(static function (ChatRoom $room): bool {
				return $room->getBotId() === 1
					&& $room->getResponseMode() === 'mention'
					&& $room->getOnboardingStatus() === 'completed';
			}));

		$chatRoomMapper->expects($this->once())
			->method('insert')
			->with($this->callback(static function (ChatRoom $room): bool {
				return $room->getBotId() === 2
					&& $room->getRoomToken() === 'room-token'
					&& $room->getActivatedBy() === 'users/pkienast'
					&& $room->getOnboardingStatus() === 'mode_selection';
			}))
			->willReturnCallback(static fn (ChatRoom $room): ChatRoom => $room);

		$service = $this->createService($chatRoomMapper);

		$room = $service->initializeRoom(2, 'room-token', 'users/pkienast');

		$this->assertSame(2, $room->getBotId());
		$this->assertSame('room-token', $room->getRoomToken());
		$this->assertSame('mode_selection', $room->getOnboardingStatus());
	}

	public function testSelectingAlwaysModeDemotesOtherAlwaysModeBotsInSameRoom(): void {
		$chatRoomMapper = $this->createMock(ChatRoomMapper::class);
		$existingAlwaysRoom = $this->createAlwaysModeRoom(1, 'room-token');
		$currentRoom = new ChatRoom();
		$currentRoom->setBotId(2);
		$currentRoom->setRoomToken('room-token');
		$currentRoom->setOnboardingStatus('mode_selection');

		$chatRoomMapper->expects($this->once())
			->method('findAlwaysModeByRoom')
			->with('room-token')
			->willReturn([$existingAlwaysRoom]);

		$updatedRooms = [];
		$chatRoomMapper->expects($this->exactly(2))
			->method('update')
			->willReturnCallback(static function (ChatRoom $room) use (&$updatedRooms): ChatRoom {
				$updatedRooms[$room->getBotId()] = clone $room;
				return $room;
			});

		$service = $this->createService($chatRoomMapper);
		$bot = new Bot();
		$bot->setId(2);
		$bot->setMentionName('@educ-call-bot');

		$result = $service->handleOnboardingResponse($currentRoom, $bot, 'B');

		$this->assertTrue($result['completed']);
		$this->assertStringContainsString('every message in this chat', (string)$result['message']);
		$this->assertSame('mention', $updatedRooms[1]->getResponseMode());
		$this->assertSame('always', $updatedRooms[2]->getResponseMode());
		$this->assertSame('completed', $updatedRooms[2]->getOnboardingStatus());
	}

	private function createService(ChatRoomMapper $chatRoomMapper): OnboardingService {
		return new OnboardingService(
			$chatRoomMapper,
			$this->createMock(BotMapper::class),
			$this->createMock(ConversationMapper::class),
			$this->createMock(LoggerInterface::class)
		);
	}

	private function createAlwaysModeRoom(int $botId, string $roomToken): ChatRoom {
		$room = new ChatRoom();
		$room->setBotId($botId);
		$room->setRoomToken($roomToken);
		$room->setResponseMode('always');
		$room->setOnboardingStatus('completed');

		return $room;
	}
}
