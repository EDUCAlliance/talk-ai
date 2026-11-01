<?php

declare(strict_types=1);

namespace OCA\EducAI\OpenApi;

use OCA\EducAI\Db\ApiConnection;
use OCA\EducAI\Service\CredentialService;
use Psr\Log\LoggerInterface;

/**
 * Executes one approved read operation: fresh approval check, recursive
 * argument validation, request construction below the approved base, a
 * bounded HTTP call and the declarative projection. Every failure becomes a
 * structured tool error; nothing is retried or paginated implicitly.
 */
class OpenApiExecutionService {
	public const DEFAULT_TIMEOUT_SECONDS = 15.0;

	public function __construct(
		private OpenApiToolCatalog $catalog,
		private ArgumentValidator $validator,
		private ApiRequestBuilder $requestBuilder,
		private ApiHttpTransport $transport,
		private ApiResultProjector $projector,
		private CredentialService $credentials,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Runs an API tool for the bot run described by $invocation. Without a
	 * verified invocation nothing is resolved or sent.
	 *
	 * @param array<string,mixed> $arguments
	 * @return array{content:list<array{type:string,text:string}>,isError:bool,error?:array<string,mixed>}
	 */
	public function execute(string $toolName, array $arguments, ?ApiInvocation $invocation): array {
		try {
			if ($invocation === null) {
				throw new ApiExecutionException('invocation_context_missing', 'API tools can only run inside a verified bot conversation.');
			}
			$ids = OpenApiToolCatalog::parseToolName($toolName);
			if ($ids === null) {
				throw new ApiExecutionException('tool_not_available', 'This API tool is currently not available.', ['reason' => 'tool_name_invalid']);
			}
			$resolved = $this->catalog->resolve($ids[0], $ids[1]);
			if (!ApiToolAccess::allowsVisibility($resolved['connection'], $invocation->botVisibility)) {
				throw new ApiExecutionException('tool_not_available', 'This API tool is currently not available.', ['reason' => 'bot_visibility_not_allowed']);
			}
			$timeout = $invocation->beginCall(self::DEFAULT_TIMEOUT_SECONDS, microtime(true));
			return self::success($this->call($resolved, $arguments, $timeout, $invocation->logContext() + [
				'connection_id' => $ids[0],
				'operation_id' => $ids[1],
			]));
		} catch (ApiExecutionException $e) {
			return self::failure($e);
		}
	}

	/**
	 * @param array{connection:ApiConnection,revision:\OCA\EducAI\Db\ApiSpecRevision,operation:array<string,mixed>,config:array<string,mixed>} $resolved
	 * @param array<string,mixed> $arguments
	 * @param array<string,scalar|null> $logContext
	 * @return array<string,mixed> Projected result
	 * @throws ApiExecutionException
	 */
	public function call(array $resolved, array $arguments, float $timeout, array $logContext = []): array {
		$config = $resolved['config'];
		try {
			$mapping = ApiResultProjector::normalizeMapping(is_array($config['mapping'] ?? null) ? $config['mapping'] : []);
		} catch (\InvalidArgumentException $e) {
			throw new ApiExecutionException('tool_not_available', 'This API tool is currently not available.', ['reason' => $e->getMessage()]);
		}
		$data = $this->fetch($resolved, $arguments, $timeout, $logContext);
		return $this->projector->project($data, $mapping, [
			'api' => $resolved['connection']->getName(),
			'operation' => (string)($config['label'] ?? ''),
		], time());
	}

	/**
	 * The validated, authenticated request of call() without the projection,
	 * for app-internal consumers that interpret the decoded JSON themselves.
	 *
	 * @param array{connection:ApiConnection,revision:\OCA\EducAI\Db\ApiSpecRevision,operation:array<string,mixed>,config:array<string,mixed>} $resolved
	 * @param array<string,mixed> $arguments
	 * @param array<string,scalar|null> $logContext
	 * @throws ApiExecutionException
	 */
	public function fetch(array $resolved, array $arguments, float $timeout, array $logContext = [], int $maxBytes = ApiHttpTransport::MAX_RESPONSE_BYTES): mixed {
		$connection = $resolved['connection'];
		$operation = $resolved['operation'];
		$config = $resolved['config'];

		try {
			$fixed = ApiOperationReview::normalizeFixed($config['fixed'] ?? [], $operation);
		} catch (\InvalidArgumentException $e) {
			throw new ApiExecutionException('tool_not_available', 'This API tool is currently not available.', ['reason' => $e->getMessage()]);
		}
		$values = $this->validator->validate($arguments, $operation, $fixed);
		$base = (string)$connection->getBaseUrl();
		$url = $this->requestBuilder->build($base, $operation, $values);
		$headers = $this->authHeaders($connection, $operation, $config);

		return $this->transport->getJson($url, $base, $headers, $timeout, $logContext + ['path_template' => mb_substr($operation['path'], 0, 200)], $maxBytes)['data'];
	}

	/**
	 * Exactly the chosen alternative; there is no fallback to another scheme
	 * or to an anonymous call.
	 *
	 * @param array<string,mixed> $operation
	 * @param array<string,mixed> $config
	 * @return array<string,string>
	 * @throws ApiExecutionException
	 */
	private function authHeaders(ApiConnection $connection, array $operation, array $config): array {
		$alternative = ApiOperationReview::chosenAlternative($operation, $config['auth'] ?? null);
		if ($alternative === null) {
			throw new ApiExecutionException('tool_not_available', 'This API tool is currently not available.', ['reason' => 'auth_not_chosen']);
		}
		if ($alternative['mode'] === 'none') {
			return [];
		}
		try {
			$secret = $this->credentials->decrypt($connection->getCredential());
		} catch (\Throwable $e) {
			$this->logger->error('EducAI: API credential could not be decrypted', ['connection_id' => $connection->getId(), 'exception_class' => get_class($e)]);
			throw new ApiExecutionException('credential_unreadable', 'The stored API credential cannot be read. Ask an administrator to replace it.');
		}
		if ($secret === '') {
			throw new ApiExecutionException('credential_missing', 'No API credential is configured.');
		}
		return $alternative['mode'] === ApiConnection::AUTH_BEARER
			? ['Authorization' => 'Bearer ' . $secret]
			: [(string)$connection->getAuthHeader() => $secret];
	}

	/**
	 * @param array<string,mixed> $result
	 * @return array{content:list<array{type:string,text:string}>,isError:bool}
	 */
	public static function success(array $result): array {
		return ['content' => [['type' => 'text', 'text' => ApiResultProjector::encode($result)]], 'isError' => false];
	}

	/** @return array{content:list<array{type:string,text:string}>,isError:bool,error:array<string,mixed>} */
	public static function failure(ApiExecutionException $e): array {
		$details = array_filter($e->getDetails(), static fn ($value): bool => is_scalar($value));
		$details['request_sent'] = $e->wasRequestSent();
		return [
			'content' => [['type' => 'text', 'text' => $e->getMessage()]],
			'isError' => true,
			'error' => ['code' => $e->getErrorCode(), 'message' => $e->getMessage(), 'details' => $details],
		];
	}
}
