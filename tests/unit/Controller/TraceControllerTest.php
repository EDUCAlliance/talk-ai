<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Controller;

use OCA\EducAI\Controller\TraceController;
use OCA\EducAI\Service\TraceService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TraceControllerTest extends TestCase {
	public function testIndexRequiresAuthenticatedUser(): void {
		$traceService = $this->createMock(TraceService::class);
		$traceService->expects($this->never())->method('listRunsForUser');

		$response = $this->createController($traceService, null)->index();

		$this->assertSame(401, $response->getStatus());
		$this->assertSame('Not authenticated', $response->getData()['error']);
		$this->assertSame('not_authenticated', $response->getData()['errorCode']);
	}

	public function testIndexScopesListToCurrentUser(): void {
		$traceService = $this->createMock(TraceService::class);
		$traceService->expects($this->once())
			->method('listRunsForUser')
			->with('alice', $this->callback(static fn (array $filters): bool => $filters['status'] === 'error'))
			->willReturn([
				'traces' => [['id' => 1, 'status' => 'error']],
				'total' => 1,
				'limit' => 25,
				'offset' => 0,
			]);

		$response = $this->createController($traceService, 'alice', ['status' => 'error'])->index();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(1, $response->getData()['total']);
	}

	public function testIndexAcceptsBotMentionNameFilter(): void {
		$traceService = $this->createMock(TraceService::class);
		$traceService->expects($this->once())
			->method('listRunsForUser')
			->with('alice', $this->callback(static fn (array $filters): bool => $filters['botMentionName'] === '@cute-hajs'))
			->willReturn([
				'traces' => [],
				'total' => 0,
				'limit' => 25,
				'offset' => 0,
			]);

		$response = $this->createController($traceService, 'alice', ['botMentionName' => '@cute-hajs'])->index();

		$this->assertSame(200, $response->getStatus());
	}

	public function testShowRejectsForeignOrMissingTrace(): void {
		$traceService = $this->createMock(TraceService::class);
		$traceService->expects($this->once())
			->method('getRunForUser')
			->with(5, 'alice')
			->willThrowException(new DoesNotExistException('not found'));

		$response = $this->createController($traceService, 'alice')->show(5);

		$this->assertSame(404, $response->getStatus());
		$this->assertSame('Trace not found', $response->getData()['error']);
		$this->assertSame('trace_not_found', $response->getData()['errorCode']);
	}

	public function testDeleteScopesToCurrentUser(): void {
		$traceService = $this->createMock(TraceService::class);
		$traceService->expects($this->once())
			->method('deleteRunForUser')
			->with(5, 'alice');

		$response = $this->createController($traceService, 'alice')->destroy(5);

		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	private function createController(TraceService $traceService, ?string $userId, array $params = []): TraceController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => $params[$key] ?? $default);

		return new TraceController(
			'educai',
			$request,
			$traceService,
			$userId,
			$this->createMock(LoggerInterface::class),
			$this->createL10n(),
		);
	}

	private function createL10n(): IL10N {
		$builder = $this->getMockBuilder(IL10N::class);
		if (!method_exists(IL10N::class, 't')) {
			$builder->addMethods(['t']);
		}
		$l10n = $builder->getMock();
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => $text);

		return $l10n;
	}
}
