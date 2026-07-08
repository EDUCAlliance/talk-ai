<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Jobs;

use OCA\EducAI\Db\Bot;
use OCA\EducAI\Db\BotMapper;
use OCA\EducAI\Db\QueuedRequest;
use OCA\EducAI\Jobs\ProcessQueuedRequestsJob;
use OCA\EducAI\Service\BotService;
use OCA\EducAI\Service\RateLimitService;
use OCA\EducAI\Webhook\TalkHandler;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProcessQueuedRequestsJobTest extends TestCase {
	public function testPermanentQueueFailureDoesNotExposeProviderEndpointInTalk(): void {
		$request = new QueuedRequest();
		$request->setId(99);
		$request->setBotId(7);
		$request->setRoomToken('room-token');
		$request->setUserId('owner');
		$request->setMessage('Hello');
		$request->setOriginalMessage('@bot Hello');
		$request->setReplyToMessageId(123);
		$request->setAttempts(3);

		$bot = new Bot();
		$bot->setId(7);
		$bot->setIsActive(true);

		$rateLimitService = $this->createMock(RateLimitService::class);
		$rateLimitService->expects($this->once())
			->method('markProcessing')
			->with($request);
		$rateLimitService->expects($this->once())
			->method('recordUsage');
		$rateLimitService->expects($this->once())
			->method('markFailed')
			->with(
				$request,
				$this->stringContains('Max retries exceeded')
			);

		$botMapper = $this->createMock(BotMapper::class);
		$botMapper->expects($this->once())
			->method('findById')
			->with(7)
			->willReturn($bot);

		$botService = $this->createMock(BotService::class);
		$botService->expects($this->once())
			->method('processMessage')
			->willThrowException(new \Exception('Failed to get response from AI: cURL error 7: Failed to connect to https://secret.example.invalid/v1/chat/completions'));

		$talkHandler = $this->createMock(TalkHandler::class);
		$talkHandler->expects($this->once())
			->method('sendReplyToTalk')
			->with(
				'room-token',
				$this->callback(function (string $message): bool {
					$this->assertStringContainsString('Your request could not be processed after multiple attempts. Please try again later.', $message);
					$this->assertStringNotContainsString('https://secret.example.invalid', $message);
					$this->assertStringNotContainsString('Error:', $message);
					return true;
				}),
				123
			)
			->willReturn(true);

		$job = new ProcessQueuedRequestsJob(
			$this->createMock(ITimeFactory::class),
			$rateLimitService,
			$botService,
			$botMapper,
			$talkHandler,
			$this->createMock(LoggerInterface::class)
		);

		$method = new \ReflectionMethod(ProcessQueuedRequestsJob::class, 'processQueuedRequest');
		$method->setAccessible(true);
		$method->invoke($job, $request);
	}
}
