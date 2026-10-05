<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Controller;

use OCA\EducAI\Controller\WebhookController;
use OCA\EducAI\Exception\InvalidWebhookException;
use OCA\EducAI\Webhook\TalkHandler;
use OCA\EducAI\Webhook\TalkWebhookPayload;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WebhookControllerTest extends TestCase {
	public function testMissingAuthenticationIsRejectedWithoutReadingBody(): void {
		$handler = $this->createMock(TalkHandler::class);
		$handler->expects($this->never())->method('handleIncoming');
		$controller = $this->controller([], $handler);
		$controller->expects($this->never())->method('readRequestBody');
		$this->assertSame(401, $controller->talk()->getStatus());
	}

	public function testDeclaredOversizedBodyIsRejectedWithoutReadingBody(): void {
		$handler = $this->createMock(TalkHandler::class);
		$handler->expects($this->never())->method('handleIncoming');
		$controller = $this->controller($this->authHeaders() + ['Content-Length' => (string)(TalkWebhookPayload::MAX_BODY_BYTES + 1)], $handler);
		$controller->expects($this->never())->method('readRequestBody');
		$this->assertSame(413, $controller->talk()->getStatus());
	}

	public function testFalseSmallContentLengthDoesNotBypassStreamLimit(): void {
		$handler = $this->createMock(TalkHandler::class);
		$handler->expects($this->never())->method('handleIncoming');
		$controller = $this->controller($this->authHeaders() + ['Content-Length' => '1'], $handler);
		$controller->method('readRequestBody')->willReturn(str_repeat('x', TalkWebhookPayload::MAX_BODY_BYTES + 1));
		$this->assertSame(413, $controller->talk()->getStatus());
	}

	public function testNoContentLengthDoesNotBypassStreamLimit(): void {
		$handler = $this->createMock(TalkHandler::class);
		$handler->expects($this->never())->method('handleIncoming');
		$controller = $this->controller($this->authHeaders(), $handler);
		$controller->method('readRequestBody')->willReturn(str_repeat('x', TalkWebhookPayload::MAX_BODY_BYTES + 1));
		$this->assertSame(413, $controller->talk()->getStatus());
	}

	public function testBodyIsPassedUnchangedForSignatureVerification(): void {
		$body = "{\n \"object\": {}\n}";
		$handler = $this->createMock(TalkHandler::class);
		$handler->expects($this->once())->method('handleIncoming')->with([
			'body' => $body,
			'signature' => 'test-signature',
			'random' => 'test-nonce',
		]);
		$controller = $this->controller($this->authHeaders(), $handler);
		$controller->method('readRequestBody')->willReturn($body);
		$this->assertSame(200, $controller->talk()->getStatus());
	}

	public function testInvalidPayloadReturnsItsRejectionStatus(): void {
		$handler = $this->createMock(TalkHandler::class);
		$handler->method('handleIncoming')->willThrowException(new InvalidWebhookException('Invalid shape'));
		$controller = $this->controller($this->authHeaders(), $handler);
		$controller->method('readRequestBody')->willReturn('true');
		$this->assertSame(400, $controller->talk()->getStatus());
	}

	public function testBodyReadFailureDoesNotReachHandler(): void {
		$handler = $this->createMock(TalkHandler::class);
		$handler->expects($this->never())->method('handleIncoming');
		$controller = $this->controller($this->authHeaders(), $handler);
		$controller->method('readRequestBody')->willReturn(false);
		$this->assertSame(200, $controller->talk()->getStatus());
	}

	public function testProcessingFailureRetainsAcknowledgementToAvoidRepeatedSideEffects(): void {
		$handler = $this->createMock(TalkHandler::class);
		$handler->method('handleIncoming')->willThrowException(new \RuntimeException('Processing failed'));
		$controller = $this->controller($this->authHeaders(), $handler);
		$controller->method('readRequestBody')->willReturn('{"object":{}}');
		$this->assertSame(200, $controller->talk()->getStatus());
	}

	private function authHeaders(): array {
		return ['X-Nextcloud-Talk-Signature' => 'test-signature', 'X-Nextcloud-Talk-Random' => 'test-nonce'];
	}

	private function controller(array $headers, TalkHandler $handler): WebhookController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn (string $header): string => $headers[$header] ?? '');
		return $this->getMockBuilder(WebhookController::class)
			->setConstructorArgs(['educai', $request, $handler, $this->createMock(LoggerInterface::class)])
			->onlyMethods(['readRequestBody'])
			->getMock();
	}
}
