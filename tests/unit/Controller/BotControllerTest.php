<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Controller;

use OCA\EducAI\Controller\BotController;
use OCA\EducAI\Exception\AuthorizationException;
use OCA\EducAI\Exception\ModelNotReleasedException;
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

	public function testModelSelectionRefusalsExplainTheModelInsteadOfClaimingMissingRights(): void {
		foreach ([
			[false, 'This model has not been released for bots. Choose a released model or the administrator default.'],
			[true, 'Choosing a model per bot is turned off. The bot uses the administrator default model.'],
		] as [$selectionDisabled, $message]) {
			$botService = $this->createMock(BotService::class);
			$botService->method('updateBot')->willThrowException(new ModelNotReleasedException($selectionDisabled));
			$botService->method('createBot')->willThrowException(new ModelNotReleasedException($selectionDisabled));
			$controller = $this->createController($botService);
			foreach ([$controller->update(42, 'Test bot', 'Test prompt', model: 'secondary:x'), $controller->create('Test bot', 'test', 'Test prompt', model: 'secondary:x')] as $response) {
				$this->assertSame(400, $response->getStatus());
				$this->assertSame('model_not_released', $response->getData()['errorCode']);
				$this->assertSame($message, $response->getData()['error']);
			}
		}
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
