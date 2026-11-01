<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\OpenApi;

require_once __DIR__ . '/Support/OpenApiHarness.php';

use OCA\EducAI\Db\ApiConnection;
use OCA\EducAI\Db\ApiSpecRevision;
use OCA\EducAI\OpenApi\ApiConnectionException;
use OCA\EducAI\OpenApi\OpenApiToolCatalog;
use OCA\EducAI\Tests\Unit\OpenApi\Support\FakeResponse;
use OCA\EducAI\Tests\Unit\OpenApi\Support\Fixture;
use OCA\EducAI\Tests\Unit\OpenApi\Support\OpenApiHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApiConnectionServiceTest extends TestCase {
	private OpenApiHarness $harness;

	protected function setUp(): void {
		$this->harness = new OpenApiHarness($this);
	}

	private function expectConnectionError(string $code, callable $call): ApiConnectionException {
		try {
			$call();
			self::fail('Expected ' . $code);
		} catch (ApiConnectionException $e) {
			self::assertSame($code, $e->getErrorCode());
			return $e;
		}
	}

	/** @return list<string> */
	private function exposedNames(): array {
		$this->harness->catalog->reset();
		return array_column($this->harness->catalog->exposed(), 'name');
	}

	public function testCredentialsAreEncryptedNeverPresentedAndCanBeReplacedOrRemoved(): void {
		$connection = $this->harness->createCoursesConnection();
		$stored = $this->harness->connections->findById($connection->getId());

		self::assertNotSame(OpenApiHarness::SECRET, $stored->getCredential());
		self::assertStringStartsWith('$ENCRYPTED$', (string)$stored->getCredential());
		$presented = json_encode($this->harness->service->presentConnection($stored));
		self::assertStringNotContainsString(OpenApiHarness::SECRET, (string)$presented);
		self::assertStringNotContainsString((string)$stored->getCredential(), (string)$presented);
		self::assertTrue($this->harness->service->presentConnection($stored)['credential_set']);

		$this->harness->service->updateConnection($connection->getId(), ['credential' => ['action' => 'keep', 'value' => 'ignored']], OpenApiHarness::ADMIN);
		self::assertSame($stored->getCredential(), $this->harness->connections->findById($connection->getId())->getCredential());

		$this->harness->service->updateConnection($connection->getId(), ['credential' => ['action' => 'replace', 'value' => 'other-synthetic']], OpenApiHarness::ADMIN);
		self::assertNotSame($stored->getCredential(), $this->harness->connections->findById($connection->getId())->getCredential());

		$this->harness->service->updateConnection($connection->getId(), ['credential' => ['action' => 'remove']], OpenApiHarness::ADMIN);
		self::assertFalse($this->harness->service->presentConnection($this->harness->connections->findById($connection->getId()))['credential_set']);

		$this->expectConnectionError('credential_invalid', fn () => $this->harness->service->updateConnection($connection->getId(), ['credential' => ['action' => 'replace', 'value' => "a\nb"]], OpenApiHarness::ADMIN));
	}

	public function testConnectionSettingsAreValidated(): void {
		$service = $this->harness->service;
		$this->expectConnectionError('name_missing', static fn () => $service->createConnection(['name' => "\u{202E} "], OpenApiHarness::ADMIN));
		$this->expectConnectionError('base_url_https_required', static fn () => $service->createConnection(['name' => 'X', 'base_url' => 'http://api.example'], OpenApiHarness::ADMIN));
		$this->expectConnectionError('base_url_private_host', static fn () => $service->createConnection(['name' => 'X', 'base_url' => 'https://192.168.1.10/api'], OpenApiHarness::ADMIN));
		$this->expectConnectionError('base_url_invalid', static fn () => $service->createConnection(['name' => 'X', 'base_url' => 'https://user@api.example/'], OpenApiHarness::ADMIN));
		$this->expectConnectionError('auth_header_invalid', static fn () => $service->createConnection(['name' => 'X', 'auth_mode' => 'api_key_header', 'auth_header' => 'Cookie'], OpenApiHarness::ADMIN));
		$this->expectConnectionError('auth_mode_invalid', static fn () => $service->createConnection(['name' => 'X', 'auth_mode' => 'basic'], OpenApiHarness::ADMIN));
		$this->expectConnectionError('data_visibility_invalid', static fn () => $service->createConnection(['name' => 'X', 'data_visibility' => 'per_user'], OpenApiHarness::ADMIN));

		$connection = $service->createConnection(['name' => 'X', 'base_url' => 'HTTPS://API.Example:443/v1/'], OpenApiHarness::ADMIN);
		self::assertSame('https://api.example/v1', $connection->getBaseUrl());
	}

	public function testImportCreatesADraftOnlyWithDefaultsThatOfferNothing(): void {
		$connection = $this->harness->createCoursesConnection();
		$revision = $this->harness->import($connection, Fixture::read('courses-api.json'));

		self::assertTrue($revision->isDraft());
		self::assertNull($this->harness->connections->findById($connection->getId())->getActiveRevisionId());
		foreach ($revision->getOpConfigArray() as $config) {
			self::assertFalse($config['enabled']);
			self::assertFalse($config['read_confirmed']);
			self::assertNull($config['auth']);
		}
		self::assertSame([], $this->exposedNames());
		self::assertSame([], $this->harness->client->requests);
	}

	public function testApprovalRequiresExplicitReviewOfEveryEnabledOperation(): void {
		$connection = $this->harness->createCoursesConnection();
		$revision = $this->harness->import($connection, Fixture::read('courses-api.json'));
		$search = $this->harness->operationId($revision, 'GET /courses');
		$details = $this->harness->operationId($revision, 'GET /courses/{courseId}');
		$legacy = $this->harness->operationId($revision, 'GET /legacy');
		$post = $this->harness->operationId($revision, 'POST /courses');
		$service = $this->harness->service;

		$this->expectConnectionError('no_operation_enabled', static fn () => $service->approve($revision->getId(), OpenApiHarness::ADMIN));
		$this->expectConnectionError('operation_not_supported', static fn () => $service->updateDraftOperations($revision->getId(), [$post => ['enabled' => true]], OpenApiHarness::ADMIN));

		$service->updateDraftOperations($revision->getId(), [
			$search => ['enabled' => true],
			$details => ['enabled' => true, 'read_confirmed' => true, 'auth' => 'none'],
			$legacy => ['enabled' => true, 'read_confirmed' => true, 'auth' => 'scheme:bearerAuth'],
		], OpenApiHarness::ADMIN);
		$e = $this->expectConnectionError('operations_not_approvable', static fn () => $service->approve($revision->getId(), OpenApiHarness::ADMIN));

		self::assertEqualsCanonicalizing([
			$search . ':read_not_confirmed',
			$search . ':auth_not_chosen',
			$details . ':auth_not_chosen',
			$legacy . ':server_override_not_approved',
		], $e->getDetails()['problems']);
		self::assertTrue($this->harness->revisions->findById($revision->getId())->isDraft());
	}

	public function testAuthenticationIsExactlyTheChosenAlternative(): void {
		$connection = $this->harness->createCoursesConnection();
		$revision = $this->harness->import($connection, Fixture::read('courses-api.json'));
		$details = $this->harness->operationId($revision, 'GET /courses/{courseId}');
		$service = $this->harness->service;
		$problems = function (array $edit) use ($service, $revision, $details, $connection): array {
			$service->updateDraftOperations($revision->getId(), [$details => ['enabled' => true, 'read_confirmed' => true] + $edit], OpenApiHarness::ADMIN);
			return $service->approvalProblems($this->harness->revisions->findById($revision->getId()), $this->harness->connections->findById($connection->getId()))[(string)$details] ?? [];
		};

		self::assertSame([], $problems(['auth' => 'anonymous']));
		self::assertSame([], $problems(['auth' => 'scheme:bearerAuth']));
		self::assertSame(['auth_not_chosen'], $problems(['auth' => 'scheme:apiKeyAuth']));
		self::assertSame(['auth_not_chosen'], $problems(['auth' => 'none']));

		$service->updateConnection($connection->getId(), ['auth_mode' => ApiConnection::AUTH_API_KEY_HEADER, 'auth_header' => 'X-Api-Key'], OpenApiHarness::ADMIN);
		self::assertSame(['auth_mode_mismatch'], $problems(['auth' => 'scheme:bearerAuth']));

		$service->updateConnection($connection->getId(), ['auth_mode' => ApiConnection::AUTH_BEARER, 'credential' => ['action' => 'remove']], OpenApiHarness::ADMIN);
		self::assertSame(['credential_missing'], $problems(['auth' => 'scheme:bearerAuth']));
		self::assertSame([], $problems(['auth' => 'anonymous']));
	}

	public function testApprovalActivatesOneRevisionInATransaction(): void {
		$connection = $this->harness->createCoursesConnection();
		$first = $this->harness->import($connection, Fixture::read('courses-api.json'));
		$search = $this->harness->approveCourseSearch($connection, $first);

		self::assertSame(['begin', 'commit', 'begin', 'commit'], $this->harness->transactions);
		$stored = $this->harness->connections->findById($connection->getId());
		self::assertSame($first->getId(), $stored->getActiveRevisionId());
		self::assertSame(['api_' . $connection->getId() . '_' . $search], $this->exposedNames());

		$second = $this->harness->import($connection, OpenApiHarness::document('courses-api.json', static function (array $document): array {
			$document['info']['version'] = '1.5.0';
			return $document;
		}));
		self::assertSame(['api_' . $connection->getId() . '_' . $search], $this->exposedNames(), 'The approved revision stays active while a draft exists');
		self::assertSame(true, $second->getOpConfigArray()[(string)$search]['enabled'], 'Unchanged operations keep their review');

		$this->harness->service->approve($second->getId(), OpenApiHarness::ADMIN);
		self::assertSame(ApiSpecRevision::STATUS_SUPERSEDED, $this->harness->revisions->findById($first->getId())->getStatus());
		self::assertSame($second->getId(), $this->harness->connections->findById($connection->getId())->getActiveRevisionId());
		$this->expectConnectionError('document_unchanged', fn () => $this->harness->import($connection, OpenApiHarness::document('courses-api.json', static function (array $document): array {
			$document['info']['version'] = '1.5.0';
			return $document;
		})));
	}

	public function testOperationIdentityIsMethodAndPathAndSurvivesOperationIdOrLabelChanges(): void {
		$connection = $this->harness->createCoursesConnection();
		$first = $this->harness->import($connection, Fixture::read('courses-api.json'));
		$search = $this->harness->approveCourseSearch($connection, $first);

		$renamed = $this->harness->import($connection, OpenApiHarness::document('courses-api.json', static function (array $document): array {
			$document['paths']['/courses']['get']['operationId'] = 'searchCourses';
			$document['paths']['/courses']['get']['summary'] = 'Find courses';
			return $document;
		}));

		self::assertSame($search, $this->harness->operationId($renamed, 'GET /courses'));
		self::assertFalse($renamed->getOpConfigArray()[(string)$search]['enabled'], 'A changed definition needs a new review');
		$diff = $this->harness->service->diff($renamed, $this->harness->connections->findById($connection->getId()));
		$change = array_values(array_filter($diff['changed'], static fn (array $c): bool => $c['id'] === $search))[0];
		self::assertContains('summary', $change['aspects']);
		self::assertSame(['before' => 'Search courses', 'after' => 'Find courses'], $change['changes']['summary']);
		self::assertSame(['before' => true, 'after' => false], $change['changes']['review_enabled']);
	}

	public function testRemovedOperationsAreTombstonedAndNeverRevived(): void {
		$connection = $this->harness->createCoursesConnection();
		$first = $this->harness->import($connection, Fixture::read('courses-api.json'));
		$search = $this->harness->approveCourseSearch($connection, $first);
		$oldTool = OpenApiToolCatalog::toolName($connection->getId(), $search);

		$staleDraft = $this->harness->import($connection, OpenApiHarness::document('courses-api.json', static function (array $document): array {
			$document['info']['version'] = 'stale';
			return $document;
		}));
		$withoutSearch = $this->harness->import($connection, OpenApiHarness::document('courses-api.json', static function (array $document): array {
			unset($document['paths']['/courses']['get']);
			return $document;
		}));
		$diff = $this->harness->service->diff($withoutSearch, $this->harness->connections->findById($connection->getId()));
		self::assertContains(['id' => $search, 'match_key' => 'GET /courses'], $diff['removed']);

		$details = $this->harness->operationId($withoutSearch, 'GET /open-data');
		$this->harness->service->updateDraftOperations($withoutSearch->getId(), [$details => ['enabled' => true, 'read_confirmed' => true, 'auth' => 'none']], OpenApiHarness::ADMIN);
		$this->harness->service->approve($withoutSearch->getId(), OpenApiHarness::ADMIN);

		self::assertTrue($this->harness->operations->findById($search)->isTombstoned());
		self::assertNotContains($oldTool, $this->exposedNames());
		self::assertSame(ApiSpecRevision::STATUS_DISCARDED, $this->harness->revisions->findById($staleDraft->getId())->getStatus());

		$reimported = $this->harness->import($connection, Fixture::read('courses-api.json'));
		$newSearch = $this->harness->operationId($reimported, 'GET /courses');
		self::assertNotSame($search, $newSearch, 'A returning operation gets a new identity');
		self::assertFalse($reimported->getOpConfigArray()[(string)$newSearch]['enabled']);
		self::assertContains($newSearch, $this->harness->service->diff($reimported, $this->harness->connections->findById($connection->getId()))['added']);
	}

	public function testDraftsReferringToTombstonedOperationsCannotBeApproved(): void {
		$connection = $this->harness->createCoursesConnection();
		$first = $this->harness->import($connection, Fixture::read('courses-api.json'));
		$search = $this->harness->approveCourseSearch($connection, $first);

		$revision = $this->harness->revisions->findById($first->getId());
		$manifest = $revision->getManifestArray();
		$identity = $this->harness->operations->findById($search);
		$identity->setActiveKey(null);
		$identity->setTombstonedAt(time());
		$this->harness->operations->update($identity);
		$draft = clone $revision;
		$draft->setStatus(ApiSpecRevision::STATUS_DRAFT);
		$draft->setSha256(str_repeat('0', 64));
		$draft->setManifestArray($manifest);
		$draft = $this->harness->revisions->insert($draft);

		$this->expectConnectionError('revision_outdated', fn () => $this->harness->service->approve($draft->getId(), OpenApiHarness::ADMIN));
	}

	public function testRevocationAndDisableApplyImmediately(): void {
		$connection = $this->harness->createCoursesConnection();
		$revision = $this->harness->import($connection, Fixture::read('courses-api.json'));
		$search = $this->harness->approveCourseSearch($connection, $revision);
		$tool = OpenApiToolCatalog::toolName($connection->getId(), $search);

		$this->harness->service->setEnabled($connection->getId(), false, OpenApiHarness::ADMIN);
		self::assertSame([], $this->exposedNames());
		$this->harness->service->setEnabled($connection->getId(), true, OpenApiHarness::ADMIN);
		self::assertSame([$tool], $this->exposedNames());

		$this->harness->service->revokeOperation($revision->getId(), $search, OpenApiHarness::ADMIN);
		self::assertSame([], $this->exposedNames());
		$this->expectConnectionError('revision_not_editable', fn () => $this->harness->service->updateDraftOperations($revision->getId(), [$search => ['enabled' => true]], OpenApiHarness::ADMIN));

		$next = $this->harness->import($connection, OpenApiHarness::document('courses-api.json', static function (array $document): array {
			$document['info']['version'] = '2';
			return $document;
		}));
		self::assertFalse($next->getOpConfigArray()[(string)$search]['enabled'], 'A revoked review is not carried over');
	}

	/** @return iterable<string,array{bool}> */
	public static function revocationOrders(): iterable {
		yield 'import then revoke' => [false];
		yield 'revoke then import' => [true];
	}

	#[DataProvider('revocationOrders')]
	public function testDraftActivationCannotUndoRevocationWithoutFreshConfirmation(bool $revokeFirst): void {
		$connection = $this->harness->createCoursesConnection();
		$first = $this->harness->import($connection, Fixture::read('courses-api.json'));
		$id = $this->harness->approveCourseSearch($connection, $first);
		$tool = OpenApiToolCatalog::toolName($connection->getId(), $id);
		if ($revokeFirst) {
			$this->harness->service->revokeOperation($first->getId(), $id, 'revoker');
		}
		$draft = $this->harness->import($connection, OpenApiHarness::document('courses-api.json', static function (array $document): array {
			$document['info']['version'] = '2';
			return $document;
		}));
		if (!$revokeFirst) {
			$this->harness->service->revokeOperation($first->getId(), $id, 'revoker');
		} else {
			// Enabling alone must not clear the revocation or reuse the old confirmation.
			$this->harness->service->updateDraftOperations($draft->getId(), [$id => ['enabled' => true, 'auth' => 'scheme:bearerAuth']], 'reviewer');
		}
		$draft = $this->harness->service->getRevision($draft->getId());
		self::assertFalse($draft->getOpConfigArray()[$id]['read_confirmed']);
		self::assertSame('revoker', $draft->getOpConfigArray()[$id]['revoked_by']);
		$problems = $this->harness->service->approvalProblems($draft, $connection)[$id];
		self::assertContains('read_not_confirmed', $problems);
		self::assertContains('operation_revoked', $problems);
		$this->expectConnectionError('operations_not_approvable', fn () => $this->harness->service->approve($draft->getId(), 'activator'));
		self::assertNotContains($tool, $this->exposedNames());
		$this->harness->enterRun();
		$result = $this->harness->provider->executeTool($tool, ['query' => ['q' => 'Algebra']]);
		self::assertSame('tool_not_available', $result['error']['code']);
		self::assertFalse($result['error']['details']['request_sent']);
		self::assertSame([], $this->harness->client->requests);

		$draft = $this->harness->service->updateDraftOperations($draft->getId(), [$id => ['read_confirmed' => true]], 'reviewer');
		self::assertSame('reviewer', $draft->getOpConfigArray()[$id]['confirmed_by']);
		self::assertArrayNotHasKey('revoked_at', $draft->getOpConfigArray()[$id]);
		$this->harness->service->approve($draft->getId(), 'activator');
		self::assertContains($tool, $this->exposedNames());
		$this->harness->respondJson(['data' => []]);
		$this->harness->enterRun();
		self::assertFalse($this->harness->provider->executeTool($tool, ['query' => ['q' => 'Algebra']])['isError']);
		self::assertCount(1, $this->harness->client->requests);
		$logs = array_values(array_filter($this->harness->logs, static fn (array $log): bool => $log[1] === 'API operation re-released'));
		self::assertCount(1, $logs);
		self::assertSame('activator', $logs[0][2]['actor_id']);
		self::assertSame($id, $logs[0][2]['operation_id']);
	}

	public function testSemanticDiffIncludesEveryReviewedConfigurationAspectWithOldAndNewValues(): void {
		$connection = $this->harness->createCoursesConnection();
		$first = $this->harness->import($connection, Fixture::read('courses-api.json'));
		$id = $this->harness->approveCourseSearch($connection, $first);
		$before = $this->harness->service->getRevision($first->getId())->getOpConfigArray()[$id];
		$draft = $this->harness->import($connection, OpenApiHarness::document('courses-api.json', static function (array $document): array {
			$document['info']['version'] = '2';
			return $document;
		}));
		foreach ([
			['mapping', 'records', '/private'],
			['mapping', 'id', '/private_id'],
			['mapping', 'title', '/name'],
			['mapping', 'fields', [['key' => 'language', 'pointer' => '/language']]],
			['mapping', 'link', ['field' => null, 'template' => 'https://courses.example/new/{id}', 'origins' => []]],
			['mapping', 'paging', ['next_offset' => '/offset']],
			['auth', null, 'anonymous'],
			['fixed', null, ['query' => ['state' => 'CLOSED']]],
			['enabled', null, false],
			['read_confirmed', null, false],
			['label', null, 'Reviewed search'],
			['description', null, 'Reviewed description'],
		] as [$key, $mappingKey, $value]) {
			$edit = $before;
			if ($mappingKey === null) {
				$edit[$key] = $value;
			} else {
				$edit[$key][$mappingKey] = $value;
			}
			$draft = $this->harness->service->updateDraftOperations($draft->getId(), [$id => $edit], 'reviewer');
			$diff = $this->harness->service->diff($draft, $this->harness->service->getConnection($connection->getId()));
			self::assertCount(1, $diff['changed'], $key . ':' . $mappingKey);
			$aspect = 'review_' . $key;
			self::assertSame([$aspect], $diff['changed'][0]['aspects']);
			self::assertSame(['before' => $before[$key], 'after' => $draft->getOpConfigArray()[$id][$key]], $diff['changed'][0]['changes'][$aspect]);
		}
	}

	public function testOriginalDocumentBytesAndHashSurviveImportAndReopening(): void {
		$connection = $this->harness->createCoursesConnection();
		$document = Fixture::read('courses-api.json');
		// The extension deliberately includes formatting and shapes lost by decoding/re-encoding.
		$document = substr_replace($document, '"x-original": { "float": 1.00, "exponent": 1e+02, "object": {}, "list": [], "text": "\\u00e4" },', strpos($document, '{') + 1, 0);
		foreach (['', "\xEF\xBB\xBF"] as $bom) {
			$bytes = $bom . " \r\n" . $document . "\r\n  ";
			$revision = $this->harness->import($connection, $bytes);
			$stored = $this->harness->service->getRevision($revision->getId());
			self::assertSame($bytes, $stored->getDocument());
			self::assertSame(hash('sha256', $bytes), $stored->getSha256());
		}
		$this->harness->responses[] = new FakeResponse(200, ['Content-Type' => 'application/json'], $bytes);
		$revision = $this->harness->service->importFromUrl($connection->getId(), 'https://api.courses.example/openapi.json', 'admin');
		self::assertSame(ApiSpecRevision::SOURCE_URL, $revision->getSourceKind());
		self::assertSame($bytes, $revision->getDocument());
		self::assertSame(hash('sha256', $bytes), $revision->getSha256());
		$this->harness->approveCourseSearch($connection, $revision);
		$this->harness->service->updateConnection($connection->getId(), ['base_url' => 'https://api.courses.example/v2'], 'admin');
		$reopened = $this->harness->service->reopenActive($connection->getId(), 'admin');
		self::assertSame($bytes, $reopened->getDocument());
		self::assertSame(hash('sha256', $reopened->getDocument()), $reopened->getSha256());
	}

	public function testChangingBaseOrAuthenticationSuspendsTheApproval(): void {
		$connection = $this->harness->createCoursesConnection();
		$revision = $this->harness->import($connection, Fixture::read('courses-api.json'));
		$search = $this->harness->approveCourseSearch($connection, $revision);
		$tool = OpenApiToolCatalog::toolName($connection->getId(), $search);

		$this->harness->service->updateConnection($connection->getId(), ['base_url' => 'https://other.courses.example/v1'], OpenApiHarness::ADMIN);
		self::assertSame([], $this->exposedNames());
		self::assertFalse($this->harness->service->presentConnection($this->harness->connections->findById($connection->getId()))['approval_current']);

		$this->harness->service->updateConnection($connection->getId(), ['base_url' => 'https://api.courses.example/v1'], OpenApiHarness::ADMIN);
		self::assertSame([$tool], $this->exposedNames());

		$this->harness->service->updateConnection($connection->getId(), ['auth_mode' => ApiConnection::AUTH_API_KEY_HEADER, 'auth_header' => 'X-Api-Key', 'credential' => ['action' => 'replace', 'value' => 'k']], OpenApiHarness::ADMIN);
		self::assertSame([], $this->exposedNames());
	}

	public function testASuspendedApprovalCanBeReviewedAgainWithoutANewDocument(): void {
		$connection = $this->harness->createCoursesConnection();
		$revision = $this->harness->import($connection, Fixture::read('courses-api.json'));
		$search = $this->harness->approveCourseSearch($connection, $revision);
		$this->expectConnectionError('document_unchanged', fn () => $this->harness->service->reopenActive($connection->getId(), OpenApiHarness::ADMIN));
		$this->expectConnectionError('document_unchanged', fn () => $this->harness->import($connection, Fixture::read('courses-api.json')));

		$this->harness->service->updateConnection($connection->getId(), ['base_url' => 'https://other.courses.example/v1'], OpenApiHarness::ADMIN);
		$draft = $this->harness->service->reopenActive($connection->getId(), OpenApiHarness::ADMIN);

		self::assertSame(ApiSpecRevision::STATUS_DRAFT, $draft->getStatus());
		self::assertSame($revision->getSha256(), $draft->getSha256());
		self::assertSame($revision->getSourceKind(), $draft->getSourceKind());
		self::assertTrue($draft->getOpConfigArray()[(string)$search]['enabled'], 'The approved review is carried over for confirmation');
		self::assertSame([], $this->exposedNames(), 'Reopening approves nothing');

		$this->harness->service->approve($draft->getId(), OpenApiHarness::ADMIN);
		$this->harness->service->setEnabled($connection->getId(), true, OpenApiHarness::ADMIN);
		self::assertSame([OpenApiToolCatalog::toolName($connection->getId(), $search)], $this->exposedNames());
	}

	public function testReopeningRequiresAnApprovedRevision(): void {
		$connection = $this->harness->createCoursesConnection();
		$this->harness->import($connection, Fixture::read('courses-api.json'));
		$this->expectConnectionError('no_approved_revision', fn () => $this->harness->service->reopenActive($connection->getId(), OpenApiHarness::ADMIN));
	}

	public function testEnablingRequiresAnApprovedRevisionAndDeclaredDataVisibility(): void {
		$connection = $this->harness->service->createConnection(['name' => 'Bare', 'base_url' => 'https://api.courses.example/v1'], OpenApiHarness::ADMIN);
		$this->expectConnectionError('no_approved_revision', fn () => $this->harness->service->setEnabled($connection->getId(), true, OpenApiHarness::ADMIN));

		$revision = $this->harness->import($connection, Fixture::read('courses-api.json'));
		$open = $this->harness->operationId($revision, 'GET /open-data');
		$this->harness->service->updateDraftOperations($revision->getId(), [$open => ['enabled' => true, 'read_confirmed' => true, 'auth' => 'none']], OpenApiHarness::ADMIN);
		$this->expectConnectionError('data_visibility_missing', fn () => $this->harness->service->approve($revision->getId(), OpenApiHarness::ADMIN));
	}

	public function testDeletingAConnectionRemovesItsRevisionsAndIdentities(): void {
		$connection = $this->harness->createCoursesConnection();
		$this->harness->approveCourseSearch($connection, $this->harness->import($connection, Fixture::read('courses-api.json')));

		$this->harness->service->deleteConnection($connection->getId());

		self::assertSame([], $this->harness->connections->rows);
		self::assertSame([], $this->harness->revisions->rows);
		self::assertSame([], $this->harness->operations->rows);
		self::assertSame([], $this->exposedNames());
	}
}
