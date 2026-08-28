<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Controller;

use OCA\EducAI\Controller\BotController;
use OCA\EducAI\Exception\AuthorizationException;
use OCA\EducAI\Service\BotService;
use OCA\EducAI\Service\BuiltInToolUiService;
use OCA\EducAI\Service\PermissionService;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BotControllerTest extends TestCase {
	public function testBotToolsMapsTypedAuthorizationFailureWithoutExposingRawMessage(): void {
		$botService = $this->createMock(BotService::class);
		$botService->method('getBotTools')
			->willThrowException(new AuthorizationException('database details must stay private'));
		$controller = $this->createController($botService);

		$response = $controller->tools(42);

		$this->assertSame(403, $response->getStatus());
		$this->assertSame('forbidden', $response->getData()['errorCode']);
		$this->assertSame('You do not have permission to view this bot', $response->getData()['error']);
		$this->assertStringNotContainsString('database', $response->getData()['error']);
	}

	public function testUpdateMapsTypedAuthorizationFailureToForbidden(): void {
		$botService = $this->createMock(BotService::class);
		$botService->method('updateBot')
			->willThrowException(new AuthorizationException('internal update authorization details'));
		$controller = $this->createController($botService);

		$response = $controller->update(42, 'Test bot', 'Test prompt');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame('forbidden', $response->getData()['errorCode']);
		$this->assertSame('You do not have permission to update this bot', $response->getData()['error']);
		$this->assertStringNotContainsString('internal', $response->getData()['error']);
	}

	public function testDestroyMapsTypedAuthorizationFailureToForbidden(): void {
		$botService = $this->createMock(BotService::class);
		$botService->method('deleteBot')
			->willThrowException(new AuthorizationException('internal delete authorization details'));
		$controller = $this->createController($botService);

		$response = $controller->destroy(42);

		$this->assertSame(403, $response->getStatus());
		$this->assertSame('forbidden', $response->getData()['errorCode']);
		$this->assertSame('You do not have permission to delete this bot', $response->getData()['error']);
		$this->assertStringNotContainsString('internal', $response->getData()['error']);
	}

	public function testSubmitMapsTypedAuthorizationFailureToForbidden(): void {
		$botService = $this->createMock(BotService::class);
		$botService->method('submitForApproval')
			->willThrowException(new AuthorizationException('internal submit authorization details'));
		$controller = $this->createController($botService);

		$response = $controller->submit(42);

		$this->assertSame(403, $response->getStatus());
		$this->assertSame('forbidden', $response->getData()['errorCode']);
		$this->assertSame('You do not have permission to submit this bot for approval', $response->getData()['error']);
		$this->assertStringNotContainsString('internal', $response->getData()['error']);
	}

	private function createController(BotService $botService): BotController {
		return new BotController(
			'educai',
			$this->createMock(IRequest::class),
			$botService,
			$this->createMock(PermissionService::class),
			'alice',
			$this->createMock(LoggerInterface::class),
			$this->createL10n(),
			$this->createMock(BuiltInToolUiService::class),
		);
	}

	private function createL10n(): IL10N {
		$builder = $this->getMockBuilder(IL10N::class);
		if (!method_exists(IL10N::class, 't')) {
			$builder->addMethods(['t']);
		}
		$l10n = $builder->getMock();
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return $l10n;
	}
}
