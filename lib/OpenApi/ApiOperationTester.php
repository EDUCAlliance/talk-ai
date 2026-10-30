<?php

declare(strict_types=1);

namespace OCA\EducAI\OpenApi;

use OCA\EducAI\Db\ApiConnectionMapper;

/**
 * A read call that an administrator starts explicitly while reviewing an
 * operation. It runs exactly the request a bot would send for the reviewed
 * settings, but never for an operation whose read semantics, authentication
 * or base address are not settled yet. Nothing is tested automatically.
 */
class ApiOperationTester {
	public function __construct(
		private ApiConnectionService $connectionService,
		private OpenApiExecutionService $execution,
		private ApiConnectionMapper $connections,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments {"path": {...}, "query": {...}}
	 * @return array{content:list<array{type:string,text:string}>,isError:bool,error?:array<string,mixed>}
	 * @throws ApiConnectionException When the operation cannot be tested at all
	 */
	public function test(int $revisionId, int $operationId, array $arguments): array {
		$revision = $this->connectionService->getRevision($revisionId);
		if (!$revision->isDraft() && !$revision->isApproved()) {
			throw new ApiConnectionException('revision_not_testable', 'Only drafts and the approved revision can be tested.', 409);
		}
		$connection = $this->connectionService->getConnection($revision->getConnectionId());
		$operation = ApiConnectionService::operationsById($revision)[(string)$operationId] ?? null;
		if ($operation === null) {
			throw new ApiConnectionException('operation_unknown', 'The operation is not part of this revision.', 404);
		}
		$config = $revision->getOpConfigArray()[(string)$operationId] ?? ApiOperationReview::defaults($operation);
		$problems = ApiOperationReview::problems($operation, ['enabled' => true] + $config, $connection, $this->connectionService->hasCredential($connection));
		if ($problems !== []) {
			throw new ApiConnectionException('operation_not_testable', 'Settle the review of this operation before testing it.', 409, ['problems' => $problems]);
		}

		try {
			$result = OpenApiExecutionService::success($this->execution->call(
				['connection' => $connection, 'revision' => $revision, 'operation' => $operation, 'config' => $config],
				$arguments,
				OpenApiExecutionService::DEFAULT_TIMEOUT_SECONDS,
				['purpose' => 'admin_test', 'connection_id' => $connection->getId(), 'operation_id' => $operationId],
			));
			$status = 'ok';
			$message = null;
		} catch (ApiExecutionException $e) {
			$result = OpenApiExecutionService::failure($e);
			$status = $e->getErrorCode();
			$message = $e->getMessage();
		}

		$connection->setLastCheckAt(time());
		$connection->setLastCheckStatus(substr($status, 0, 32));
		$connection->setLastCheckMessage($message === null ? null : mb_substr($message, 0, 512));
		$this->connections->update($connection);
		return $result;
	}
}
