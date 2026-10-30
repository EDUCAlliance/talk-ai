<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\OpenApi\Support;

require_once __DIR__ . '/OpenApiTestDoubles.php';

use OCA\EducAI\Db\ApiConnection;
use OCA\EducAI\Db\ApiSpecRevision;
use OCA\EducAI\OpenApi\ApiConnectionService;
use OCA\EducAI\OpenApi\ApiHttpTransport;
use OCA\EducAI\OpenApi\ApiNetworkPolicy;
use OCA\EducAI\OpenApi\ApiRequestBuilder;
use OCA\EducAI\OpenApi\ApiResultProjector;
use OCA\EducAI\OpenApi\ApiToolAccess;
use OCA\EducAI\OpenApi\ArgumentValidator;
use OCA\EducAI\OpenApi\OpenApiCompiler;
use OCA\EducAI\OpenApi\OpenApiDocumentLoader;
use OCA\EducAI\OpenApi\OpenApiExecutionService;
use OCA\EducAI\OpenApi\OpenApiToolCatalog;
use OCA\EducAI\Service\CredentialService;
use OCA\EducAI\Service\ToolExecutionPolicyService;
use OCA\EducAI\ToolProvider\OpenApiToolProvider;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The real OpenAPI services on in-memory tables and a recording HTTP client.
 */
final class OpenApiHarness {
	public const ADMIN = 'admin';
	public const SECRET = 'synthetic-test-token';

	public InMemoryConnectionMapper $connections;
	public InMemoryRevisionMapper $revisions;
	public InMemoryOperationMapper $operations;
	public RecordingClient $client;
	public ApiConnectionService $service;
	public OpenApiToolCatalog $catalog;
	public OpenApiExecutionService $execution;
	public OpenApiToolProvider $provider;
	public ApiToolAccess $access;
	/** @var list<string> */
	public array $admins = [self::ADMIN];
	/** @var array<string,list<string>> */
	public array $memberships = [];
	/** @var list<string> */
	public array $transactions = [];
	/** @var list<array{string,string,array<string,mixed>}> */
	public array $logs = [];
	/** @var list<IResponse|\Throwable> */
	public array $responses = [];

	public function __construct(TestCase $test, bool $testTargets = false) {
		$this->connections = new InMemoryConnectionMapper();
		$this->revisions = new InMemoryRevisionMapper();
		$this->operations = new InMemoryOperationMapper();
		$this->client = new RecordingClient(function (): IResponse {
			$next = array_shift($this->responses) ?? throw new \LogicException('Unexpected HTTP request');
			if ($next instanceof \Throwable) {
				throw $next;
			}
			return $next;
		});

		$db = (new MockFactory($test))->mock(IDBConnection::class, ['beginTransaction', 'commit', 'rollBack']);
		$db->method('beginTransaction')->willReturnCallback(function (): void {
			$this->transactions[] = 'begin';
		});
		$db->method('commit')->willReturnCallback(function (): void {
			$this->transactions[] = 'commit';
		});
		$db->method('rollBack')->willReturnCallback(function (): void {
			$this->transactions[] = 'rollback';
		});

		$credentials = (new MockFactory($test))->mock(CredentialService::class, []);
		$credentials->method('encrypt')->willReturnCallback(static fn (string $value): string => '$ENCRYPTED$' . strrev($value));
		$credentials->method('decrypt')->willReturnCallback(static fn (?string $value): string => $value === null || $value === '' ? '' : strrev(substr($value, strlen('$ENCRYPTED$'))));

		$config = (new MockFactory($test))->mock(IConfig::class, []);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === ApiNetworkPolicy::CONFIG_ALLOW_TEST_TARGETS && $testTargets ? 'yes' : $default,
		);
		$config->method('getSystemValueBool')->willReturn(false);
		$policy = new ApiNetworkPolicy($config);

		$logger = (new MockFactory($test))->mock(LoggerInterface::class, []);
		foreach (['debug', 'info', 'notice', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context = []) use ($level): void {
				$this->logs[] = [$level, $message, $context];
			});
		}

		$transport = new ApiHttpTransport(new RecordingClientService($this->client), $policy, $logger);
		$this->service = new ApiConnectionService(
			$this->connections,
			$this->revisions,
			$this->operations,
			$db,
			$credentials,
			$policy,
			new OpenApiDocumentLoader(),
			new OpenApiCompiler(),
			$transport,
			$logger,
		);
		$this->catalog = new OpenApiToolCatalog($this->connections, $this->operations, $this->service);
		$this->execution = new OpenApiExecutionService(
			$this->catalog,
			new ArgumentValidator(),
			new ApiRequestBuilder(),
			$transport,
			new ApiResultProjector(),
			$credentials,
			$logger,
		);
		$policies = (new MockFactory($test))->mock(ToolExecutionPolicyService::class, []);
		$policies->method('readToolPolicy')->willReturn(['kind' => 'read', 'source' => 'openapi']);
		$this->provider = new OpenApiToolProvider($this->catalog, $this->execution, $this->connections, $this->operations, $policies, $logger);

		$groups = (new MockFactory($test))->mock(IGroupManager::class, ['isAdmin', 'isInGroup']);
		$groups->method('isAdmin')->willReturnCallback(fn (string $userId): bool => in_array($userId, $this->admins, true));
		$groups->method('isInGroup')->willReturnCallback(fn (string $userId, string $group): bool => in_array($group, $this->memberships[$userId] ?? [], true));
		$this->access = new ApiToolAccess($this->catalog, $this->connections, $groups);
	}

	/**
	 * Invocation context as BotService sets it for a bot run.
	 *
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	public static function runContext(array $overrides = []): array {
		return $overrides + [
			'bot_id' => 7,
			'room_token' => 'room1',
			'actor_id' => 'alice',
			'bot_visibility' => 'groups',
			'deadline' => microtime(true) + 300,
			'trace_run_id' => 41,
		];
	}

	/** @param array<string,mixed> $overrides */
	public function enterRun(array $overrides = []): void {
		$this->provider->setInvocationContext(self::runContext($overrides));
	}

	public function createCoursesConnection(): ApiConnection {
		return $this->service->createConnection([
			'name' => 'Courses',
			'base_url' => 'https://api.courses.example/v1',
			'auth_mode' => ApiConnection::AUTH_BEARER,
			'credential' => ['action' => 'replace', 'value' => self::SECRET],
			'data_visibility' => ApiConnection::VISIBILITY_SHARED_FOR_BOT_AUDIENCE,
		], self::ADMIN);
	}

	public function import(ApiConnection $connection, string $document): ApiSpecRevision {
		return $this->service->importDocument($connection->getId(), $document, ApiSpecRevision::SOURCE_FILE, null, self::ADMIN);
	}

	/** Operation ID of a match key within a revision. */
	public function operationId(ApiSpecRevision $revision, string $matchKey): int {
		foreach (ApiConnectionService::operationsById($this->revisions->findById($revision->getId())) as $id => $operation) {
			if ($operation['match_key'] === $matchKey) {
				return (int)$id;
			}
		}
		throw new \LogicException('Missing ' . $matchKey);
	}

	/** The reviewed search operation of the courses fixture. */
	public function approveCourseSearch(ApiConnection $connection, ApiSpecRevision $revision): int {
		$id = $this->operationId($revision, 'GET /courses');
		$this->service->updateDraftOperations($revision->getId(), [$id => [
			'enabled' => true,
			'read_confirmed' => true,
			'label' => 'Search courses',
			'description' => 'Searches open courses by text.',
			'auth' => 'scheme:bearerAuth',
			'fixed' => ['query' => ['state' => 'OPEN']],
			'mapping' => [
				'records' => '/data',
				'id' => '/id',
				'title' => '/title',
				'fields' => [['key' => 'summary', 'pointer' => '/summary']],
				'link' => ['field' => '/permalink', 'template' => 'https://courses.example/c/{id}', 'origins' => []],
				'paging' => ['has_more' => '/has_more', 'next_cursor' => '/next_cursor', 'total' => '/total'],
			],
		]], self::ADMIN);
		$this->service->approve($revision->getId(), self::ADMIN);
		$this->service->setEnabled($connection->getId(), true, self::ADMIN);
		return $id;
	}

	public static function document(string $fixture, callable $mutate): string {
		$document = json_decode(Fixture::read($fixture), true, 512, JSON_THROW_ON_ERROR);
		$document = $mutate($document);
		return json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
	}

	public function respondJson(mixed $data, int $status = 200): void {
		$this->responses[] = new FakeResponse($status, ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
	}
}

/** Exposes PHPUnit's mock builder to the harness. */
final class MockFactory {
	public function __construct(
		private TestCase $test,
	) {
	}

	/**
	 * @param class-string $class
	 * @param list<string> $addMethods Methods missing from bootstrap stubs
	 */
	public function mock(string $class, array $addMethods): object {
		$builder = (new \ReflectionMethod(TestCase::class, 'getMockBuilder'))->invoke($this->test, $class);
		$builder->disableOriginalConstructor();
		if ($addMethods !== []) {
			$builder->addMethods($addMethods);
		}
		return $builder->getMock();
	}
}
