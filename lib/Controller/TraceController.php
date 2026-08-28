<?php

declare(strict_types=1);

namespace OCA\EducAI\Controller;

use Exception;
use OCA\EducAI\Service\TraceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class TraceController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private TraceService $traceService,
		private ?string $userId,
		private LoggerInterface $logger,
		private IL10N $l10n,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @NoAdminRequired
	 */
	public function index(): DataResponse {
		if ($this->userId === null || $this->userId === '') {
			return $this->errorResponse('not_authenticated', $this->l10n->t('Not authenticated'), 401);
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
			return $this->errorResponse('traces_load_failed', $this->l10n->t('Failed to load traces'), 500);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function show(int $id): DataResponse {
		if ($this->userId === null || $this->userId === '') {
			return $this->errorResponse('not_authenticated', $this->l10n->t('Not authenticated'), 401);
		}

		try {
			return new DataResponse($this->traceService->getRunForUser($id, $this->userId));
		} catch (DoesNotExistException $e) {
			return $this->errorResponse('trace_not_found', $this->l10n->t('Trace not found'), 404);
		} catch (Exception $e) {
			$this->logger->error('Failed to load Talk AI trace', [
				'trace_id' => $id,
				'user_id' => $this->userId,
				'exception' => $e,
			]);
			return $this->errorResponse('trace_load_failed', $this->l10n->t('Trace details unavailable'), 500);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function destroy(int $id): DataResponse {
		if ($this->userId === null || $this->userId === '') {
			return $this->errorResponse('not_authenticated', $this->l10n->t('Not authenticated'), 401);
		}

		try {
			$this->traceService->deleteRunForUser($id, $this->userId);
			return new DataResponse(['success' => true]);
		} catch (DoesNotExistException $e) {
			return $this->errorResponse('trace_not_found', $this->l10n->t('Trace not found'), 404);
		} catch (Exception $e) {
			$this->logger->error('Failed to delete Talk AI trace', [
				'trace_id' => $id,
				'user_id' => $this->userId,
				'exception' => $e,
			]);
			return $this->errorResponse('trace_delete_failed', $this->l10n->t('Deletion failed'), 500);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function clearMine(): DataResponse {
		if ($this->userId === null || $this->userId === '') {
			return $this->errorResponse('not_authenticated', $this->l10n->t('Not authenticated'), 401);
		}

		try {
			$deleted = $this->traceService->deleteAllForUser($this->userId);
			return new DataResponse(['success' => true, 'deleted' => $deleted]);
		} catch (Exception $e) {
			$this->logger->error('Failed to clear Talk AI traces', [
				'user_id' => $this->userId,
				'exception' => $e,
			]);
			return $this->errorResponse('trace_delete_failed', $this->l10n->t('Deletion failed'), 500);
		}
	}

	private function errorResponse(string $errorCode, string $error, int $status): DataResponse {
		return new DataResponse([
			'error' => $error,
			'errorCode' => $errorCode,
		], $status);
	}
}
