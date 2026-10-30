<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\OpenApi;

require_once __DIR__ . '/Support/OpenApiHarness.php';

use OCA\EducAI\Db\ApiConnection;
use OCA\EducAI\OpenApi\ApiConnectionException;
use OCA\EducAI\OpenApi\ApiInvocation;
use OCA\EducAI\OpenApi\ApiToolAssignmentException;
use OCA\EducAI\OpenApi\OpenApiToolCatalog;
use OCA\EducAI\Tests\Unit\OpenApi\Support\Fixture;
use OCA\EducAI\Tests\Unit\OpenApi\Support\OpenApiHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Assign permission (save/submit/publish) and execute permission (each call). */
final class ApiToolPermissionTest extends TestCase {
	private OpenApiHarness $harness;
	private ApiConnection $connection;
	private int $revisionId;
	private int $search;
	private string $tool;

	protected function setUp(): void {
		$this->harness = new OpenApiHarness($this);
		$this->connection = $this->harness->createCoursesConnection();
		$revision = $this->harness->import($this->connection, Fixture::read('courses-api.json'));
		$this->revisionId = $revision->getId();
		$this->search = $this->harness->approveCourseSearch($this->connection, $revision);
		$this->tool = OpenApiToolCatalog::toolName($this->connection->getId(), $this->search);
	}

	/** @param array<string,mixed> $settings */
	private function configure(array $settings): void {
		$this->connection = $this->harness->service->updateConnection($this->connection->getId(), $settings, OpenApiHarness::ADMIN);
	}

	private function assignmentFailure(callable $assign): ?string {
		try {
			$assign();
			return null;
		} catch (ApiToolAssignmentException $e) {
			return $e->getReason();
		}
	}

	/** @return array<string,mixed> */
	private function call(array $arguments = ['query' => ['q' => 'x']]): array {
		return $this->harness->provider->executeTool($this->tool, $arguments);
	}

	public function testOnlyPermittedUsersMayAssignNewApiTools(): void {
		$assign = fn (string $user) => fn () => $this->harness->access->assertAssignable($user, [], [$this->tool], null, 'groups');
		$this->harness->memberships = ['bob' => ['teachers']];

		self::assertNull($this->assignmentFailure($assign(OpenApiHarness::ADMIN)));
		self::assertSame(ApiToolAssignmentException::NOT_PERMITTED, $this->assignmentFailure($assign('bob')));
		self::assertSame(ApiToolAssignmentException::NOT_PERMITTED, $this->assignmentFailure($assign('')));

		$this->configure(['assign_policy' => ApiConnection::ASSIGN_GROUPS, 'assign_groups' => ['teachers']]);
		self::assertNull($this->assignmentFailure($assign('bob')));
		self::assertSame(ApiToolAssignmentException::NOT_PERMITTED, $this->assignmentFailure($assign('carol')));

		$this->configure(['assign_policy' => ApiConnection::ASSIGN_ALL_USERS]);
		self::assertNull($this->assignmentFailure($assign('carol')));
		self::assertSame([], $this->harness->client->requests);
	}

	public function testToolPickersListOnlyCallableToolsTheUserMayAssign(): void {
		$this->harness->memberships = ['bob' => ['teachers']];
		self::assertSame([], $this->harness->access->assignableTools('bob'));
		self::assertSame([], $this->harness->access->assignableTools(''));
		self::assertSame([$this->tool => ['connection' => 'Courses', 'bot_visibilities' => ApiConnection::BOT_VISIBILITIES]], $this->harness->access->assignableTools(OpenApiHarness::ADMIN));

		$this->configure(['assign_policy' => ApiConnection::ASSIGN_GROUPS, 'assign_groups' => ['teachers'], 'bot_visibilities' => ['personal']]);
		$this->harness->catalog->reset();
		self::assertSame([$this->tool => ['connection' => 'Courses', 'bot_visibilities' => ['personal']]], $this->harness->access->assignableTools('bob'));
		self::assertSame([], $this->harness->access->assignableTools('carol'));

		$this->harness->service->setEnabled($this->connection->getId(), false, OpenApiHarness::ADMIN);
		$this->harness->catalog->reset();
		self::assertSame([], $this->harness->access->assignableTools('bob'));
		self::assertSame([], $this->harness->client->requests);
	}

	public function testNewToolsMustBeAvailableAndFitTheBotVisibility(): void {
		$this->configure(['assign_policy' => ApiConnection::ASSIGN_ALL_USERS, 'bot_visibilities' => ['personal', 'groups']]);
		$draftOnly = OpenApiToolCatalog::toolName($this->connection->getId(), $this->harness->operationId($this->harness->service->getRevision($this->revisionId), 'GET /courses/{courseId}'));

		self::assertSame(ApiToolAssignmentException::TOOL_NOT_AVAILABLE, $this->assignmentFailure(fn () => $this->harness->access->assertAssignable('bob', [], [$draftOnly], null, 'personal')));
		self::assertSame(ApiToolAssignmentException::TOOL_NOT_AVAILABLE, $this->assignmentFailure(fn () => $this->harness->access->assertAssignable('bob', [], ['api_999_1'], null, 'personal')));
		try {
			$this->harness->access->assertAssignable('bob', [], ['rag_search_documents', $this->tool], null, 'global');
			self::fail('A global bot must not get this API');
		} catch (ApiToolAssignmentException $e) {
			self::assertSame(ApiToolAssignmentException::VISIBILITY_NOT_ALLOWED, $e->getReason());
			self::assertSame('Courses: Search courses', $e->getLabel());
		}
	}

	public function testKeptAssignmentsStayRepairableButFollowVisibilityChanges(): void {
		$this->configure(['bot_visibilities' => ['groups']]);
		$this->harness->service->setEnabled($this->connection->getId(), false, OpenApiHarness::ADMIN);

		self::assertNull($this->assignmentFailure(fn () => $this->harness->access->assertAssignable('bob', [$this->tool], [$this->tool], 'groups', 'groups')));
		self::assertSame(
			ApiToolAssignmentException::VISIBILITY_NOT_ALLOWED,
			$this->assignmentFailure(fn () => $this->harness->access->assertAssignable('bob', [$this->tool], [$this->tool], 'groups', 'global')),
		);
		self::assertSame(
			ApiToolAssignmentException::TOOL_NOT_AVAILABLE,
			$this->assignmentFailure(fn () => $this->harness->access->assertAssignable('bob', [$this->tool], [$this->tool], null, 'groups')),
		);

		$this->harness->service->deleteConnection($this->connection->getId());
		self::assertNull($this->assignmentFailure(fn () => $this->harness->access->assertAssignable('bob', [$this->tool], [$this->tool], 'groups', 'global')));
	}

	/** @return iterable<string,array{array<string,mixed>}> */
	public static function incompleteContexts(): iterable {
		yield 'no actor' => [['actor_id' => '']];
		yield 'actor from a model-shaped value' => [['actor_id' => ['alice']]];
		yield 'no bot' => [['bot_id' => null]];
		yield 'string bot id' => [['bot_id' => '7']];
		yield 'unknown visibility' => [['bot_visibility' => 'public']];
		yield 'no room' => [['room_token' => ' ']];
		yield 'no deadline' => [['deadline' => null]];
	}

	#[DataProvider('incompleteContexts')]
	public function testIncompleteContextsAreAControlledErrorWithoutRequest(array $override): void {
		$this->harness->enterRun($override);
		$result = $this->call();

		self::assertTrue($result['isError']);
		self::assertSame('invocation_context_missing', $result['error']['code']);
		self::assertFalse($result['error']['details']['request_sent']);
		self::assertSame([], $this->harness->client->requests);
	}

	public function testNoContextAtAllNeverFallsBackToAPrivilegedCall(): void {
		$this->harness->provider->setInvocationContext(null);
		self::assertSame('invocation_context_missing', $this->call()['error']['code']);

		$this->harness->enterRun();
		$this->harness->provider->setInvocationContext(null);
		self::assertSame('invocation_context_missing', $this->call()['error']['code']);
		self::assertSame([], $this->harness->client->requests);
	}

	public function testModelArgumentsCannotSupplyIdentityOrTargets(): void {
		$this->harness->enterRun();
		foreach ([['actor_id' => 'admin', 'query' => ['q' => 'x']], ['query' => ['q' => 'x', 'url' => 'https://evil.example']], ['base_url' => 'https://evil.example']] as $arguments) {
			$result = $this->call($arguments);
			self::assertSame('invalid_arguments', $result['error']['code'], json_encode($arguments));
		}
		self::assertSame([], $this->harness->client->requests);
	}

	public function testExecutionRechecksTheBotVisibility(): void {
		$this->configure(['bot_visibilities' => ['personal']]);
		$this->harness->enterRun(['bot_visibility' => 'groups']);
		$result = $this->call();

		self::assertSame('tool_not_available', $result['error']['code']);
		self::assertSame('bot_visibility_not_allowed', $result['error']['details']['reason']);
		self::assertSame([], $this->harness->client->requests);
	}

	public function testRevocationBlocksStoredAssignmentsBeforeAnyRequest(): void {
		$this->harness->enterRun();
		$this->harness->service->revokeOperation($this->revisionId, $this->search, OpenApiHarness::ADMIN);

		$result = $this->call();
		self::assertSame('tool_not_available', $result['error']['code']);
		self::assertSame('operation_revoked', $result['error']['details']['reason']);
		self::assertSame([], $this->harness->client->requests);
	}

	public function testTheRunDeadlineBoundsEachCall(): void {
		$this->harness->enterRun(['deadline' => microtime(true) + ApiInvocation::DEADLINE_RESERVE_SECONDS + 0.5]);
		self::assertSame('deadline_exceeded', $this->call()['error']['code']);
		self::assertSame([], $this->harness->client->requests);

		$this->harness->enterRun(['deadline' => microtime(true) + ApiInvocation::DEADLINE_RESERVE_SECONDS + 4.0]);
		$this->harness->respondJson(['data' => []]);
		self::assertFalse($this->call()['isError']);
		$timeout = $this->harness->client->requests[0]['options']['timeout'];
		self::assertGreaterThan(3.0, $timeout);
		self::assertLessThanOrEqual(4.0, $timeout);
	}

	public function testEachRunHasABoundedNumberOfApiCalls(): void {
		$this->harness->enterRun();
		for ($i = 0; $i < ApiInvocation::MAX_CALLS_PER_RUN; $i++) {
			$this->harness->respondJson(['data' => []]);
			self::assertFalse($this->call()['isError']);
		}
		$exhausted = $this->call();
		self::assertSame('api_call_budget_exhausted', $exhausted['error']['code']);
		self::assertFalse($exhausted['error']['details']['request_sent']);
		self::assertCount(ApiInvocation::MAX_CALLS_PER_RUN, $this->harness->client->requests);

		$this->harness->enterRun();
		$this->harness->respondJson(['data' => []]);
		self::assertFalse($this->call()['isError']);
	}

	public function testLogsCarryCorrelationButNoActorOrSecret(): void {
		$this->harness->enterRun(['actor_id' => 'alice.private@example.org']);
		$this->harness->respondJson(['data' => [['id' => 1, 'title' => 'A']]]);
		$this->call();

		$completed = array_values(array_filter($this->harness->logs, static fn (array $entry): bool => $entry[1] === 'EducAI: OpenAPI request completed'));
		self::assertCount(1, $completed);
		self::assertSame(7, $completed[0][2]['bot_id']);
		self::assertSame(41, $completed[0][2]['trace_run_id']);
		self::assertSame($this->connection->getId(), $completed[0][2]['connection_id']);
		$logs = json_encode($this->harness->logs);
		self::assertStringNotContainsString('alice.private', $logs);
		self::assertStringNotContainsString(OpenApiHarness::SECRET, $logs);
	}

	public function testAssignmentSettingsAreValidated(): void {
		foreach ([
			[['assign_policy' => 'everyone'], 'assign_policy_invalid'],
			[['assign_policy' => ApiConnection::ASSIGN_GROUPS], 'assign_groups_missing'],
			[['assign_policy' => ApiConnection::ASSIGN_GROUPS, 'assign_groups' => ['ok', "bad\n"]], 'assign_groups_invalid'],
			[['assign_policy' => ApiConnection::ASSIGN_GROUPS, 'assign_groups' => 'teachers'], 'assign_groups_invalid'],
			[['bot_visibilities' => []], 'bot_visibilities_invalid'],
			[['bot_visibilities' => ['personal', 'public']], 'bot_visibilities_invalid'],
		] as [$input, $code]) {
			try {
				$this->configure($input);
				self::fail($code);
			} catch (ApiConnectionException $e) {
				self::assertSame($code, $e->getErrorCode());
			}
		}

		$this->configure(['assign_policy' => ApiConnection::ASSIGN_GROUPS, 'assign_groups' => ['teachers', 'teachers'], 'bot_visibilities' => ['groups', 'personal']]);
		$presented = $this->harness->service->presentConnection($this->connection);
		self::assertSame(['teachers'], $presented['assign_groups']);
		self::assertSame(['personal', 'groups'], $presented['bot_visibilities']);
		self::assertTrue($presented['approval_current']);

		$this->configure(['assign_policy' => ApiConnection::ASSIGN_ADMINS, 'bot_visibilities' => ApiConnection::BOT_VISIBILITIES]);
		$presented = $this->harness->service->presentConnection($this->connection);
		self::assertSame([], $presented['assign_groups']);
		self::assertSame(ApiConnection::BOT_VISIBILITIES, $presented['bot_visibilities']);
		self::assertNull($this->connection->getBotVisibilities());
	}
}
