<?php

declare(strict_types=1);

namespace OCA\EducAI\Controller;

use Exception;
use OCA\EducAI\Service\TraceService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class TraceController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private TraceService $traceService,
		private ?string $userId,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @NoAdminRequired
	 */
	public function index(): DataResponse {
		if ($this->userId === null || $this->userId === '') {
			return new DataResponse(['error' => 'Not authenticated'], 401);
		}

		try {
			return new DataResponse($this->traceService->listRunsForUser($this->userId, [
				'limit' => $this->request->getParam('limit'),
				'offset' => $this->request->getParam('offset'),
				'botId' => $this->request->getParam('botId') ?? $this->request->getParam('bot_id'),
				'botMentionName' => $this->request->getParam('botMentionName') ?? $this->request->getParam('bot_mention_name'),
				'status' => $this->request->getParam('status'),
				'from' => $this->request->getParam('from'),
				'to' => $this->request->getParam('to'),
				'q' => $this->request->getParam('q'),
				'onlyErrors' => $this->request->getParam('onlyErrors') ?? $this->request->getParam('only_errors'),
				'onlyWithTools' => $this->request->getParam('onlyWithTools') ?? $this->request->getParam('only_with_tools'),
			]));
		} catch (Exception $e) {
			$this->logger->error('Failed to list Talk AI traces', [
				'user_id' => $this->userId,
				'exception' => $e,
			]);
			return new DataResponse(['error' => 'Failed to load traces'], 500);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function show(int $id): DataResponse {
		if ($this->userId === null || $this->userId === '') {
			return new DataResponse(['error' => 'Not authenticated'], 401);
		}

		try {
			return new DataResponse($this->traceService->getRunForUser($id, $this->userId));
		} catch (DoesNotExistException $e) {
			return new DataResponse(['error' => 'Trace not found'], 404);
		} catch (Exception $e) {
			$this->logger->error('Failed to load Talk AI trace', [
				'trace_id' => $id,
				'user_id' => $this->userId,
				'exception' => $e,
			]);
			return new DataResponse(['error' => 'Trace details unavailable'], 500);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function destroy(int $id): DataResponse {
		if ($this->userId === null || $this->userId === '') {
			return new DataResponse(['error' => 'Not authenticated'], 401);
		}

		try {
			$this->traceService->deleteRunForUser($id, $this->userId);
			return new DataResponse(['success' => true]);
		} catch (DoesNotExistException $e) {
			return new DataResponse(['error' => 'Trace not found'], 404);
		} catch (Exception $e) {
			$this->logger->error('Failed to delete Talk AI trace', [
				'trace_id' => $id,
				'user_id' => $this->userId,
				'exception' => $e,
			]);
			return new DataResponse(['error' => 'Deletion failed'], 500);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function clearMine(): DataResponse {
		if ($this->userId === null || $this->userId === '') {
			return new DataResponse(['error' => 'Not authenticated'], 401);
		}

		try {
			$deleted = $this->traceService->deleteAllForUser($this->userId);
			return new DataResponse(['success' => true, 'deleted' => $deleted]);
		} catch (Exception $e) {
			$this->logger->error('Failed to clear Talk AI traces', [
				'user_id' => $this->userId,
				'exception' => $e,
			]);
			return new DataResponse(['error' => 'Deletion failed'], 500);
		}
	}
}
