<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\OpenApi\Support;

use OCA\EducAI\Db\ApiConnection;
use OCA\EducAI\Db\ApiConnectionMapper;
use OCA\EducAI\Db\ApiOperation;
use OCA\EducAI\Db\ApiOperationMapper;
use OCA\EducAI\Db\ApiSpecRevision;
use OCA\EducAI\Db\ApiSpecRevisionMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;

/** Rows are cloned on the way in and out, like a database round trip. */
trait InMemoryRows {
	/** @var array<int,object> */
	public array $rows = [];
	/** @var \Closure(object):void|null Failure injection after a row was written. */
	public ?\Closure $afterUpdate = null;
	private int $nextId = 1;

	public function insert($entity) {
		$entity->setId($this->nextId++);
		$this->rows[$entity->getId()] = clone $entity;
		return $entity;
	}

	public function update($entity) {
		$this->rows[$entity->getId()] = clone $entity;
		if ($this->afterUpdate !== null) {
			($this->afterUpdate)($entity);
		}
		return $entity;
	}

	public function delete($entity): void {
		unset($this->rows[$entity->getId()]);
	}

	private function row(int $id): object {
		if (!isset($this->rows[$id])) {
			throw new DoesNotExistException('missing');
		}
		return clone $this->rows[$id];
	}

	/** @return list<object> */
	private function where(callable $filter): array {
		return array_values(array_map(static fn ($row) => clone $row, array_filter($this->rows, $filter)));
	}
}

final class InMemoryConnectionMapper extends ApiConnectionMapper {
	use InMemoryRows;

	public function __construct() {
	}

	public function findById(int $id): ApiConnection {
		return $this->row($id);
	}

	public function findAll(): array {
		return $this->where(static fn (): bool => true);
	}

	public function findEnabled(): array {
		return $this->where(static fn (ApiConnection $row): bool => $row->isEnabled() && $row->getActiveRevisionId() !== null);
	}
}

final class InMemoryRevisionMapper extends ApiSpecRevisionMapper {
	use InMemoryRows;

	public function __construct() {
	}

	public function findById(int $id): ApiSpecRevision {
		return $this->row($id);
	}

	public function findByConnection(int $connectionId): array {
		return array_reverse($this->where(static fn (ApiSpecRevision $row): bool => $row->getConnectionId() === $connectionId && $row->getStatus() !== ApiSpecRevision::STATUS_DISCARDED));
	}

	public function findDrafts(int $connectionId): array {
		return $this->where(static fn (ApiSpecRevision $row): bool => $row->getConnectionId() === $connectionId && $row->isDraft());
	}

	public function deleteByConnection(int $connectionId): void {
		$this->rows = array_filter($this->rows, static fn (ApiSpecRevision $row): bool => $row->getConnectionId() !== $connectionId);
	}
}

final class InMemoryOperationMapper extends ApiOperationMapper {
	use InMemoryRows;

	public function __construct() {
	}

	public function findById(int $id): ApiOperation {
		return $this->row($id);
	}

	public function insert($entity) {
		foreach ($this->rows as $row) {
			if ($entity->getActiveKey() !== null && $row->getActiveKey() === $entity->getActiveKey()) {
				throw new \LogicException('unique active_key violated');
			}
		}
		$entity->setId($this->nextId++);
		$this->rows[$entity->getId()] = clone $entity;
		return $entity;
	}

	public function findActive(int $connectionId, string $matchKey): ?ApiOperation {
		$key = ApiOperation::activeKeyFor($connectionId, $matchKey);
		return $this->where(static fn (ApiOperation $row): bool => $row->getActiveKey() === $key)[0] ?? null;
	}

	public function findByConnection(int $connectionId): array {
		return $this->where(static fn (ApiOperation $row): bool => $row->getConnectionId() === $connectionId);
	}

	public function findByIds(array $ids): array {
		return $this->where(static fn (ApiOperation $row): bool => in_array($row->getId(), $ids, true));
	}

	public function findLabeled(): array {
		return $this->where(static fn (ApiOperation $row): bool => $row->getLastLabel() !== null);
	}

	public function deleteByConnection(int $connectionId): void {
		$this->rows = array_filter($this->rows, static fn (ApiOperation $row): bool => $row->getConnectionId() !== $connectionId);
	}
}

final class FakeResponse implements IResponse {
	/** @param array<string,string> $headers */
	public function __construct(
		private int $status,
		private array $headers = [],
		private string $body = '',
	) {
	}

	/** @return resource */
	public function getBody() {
		$stream = fopen('php://memory', 'r+');
		fwrite($stream, $this->body);
		rewind($stream);
		return $stream;
	}

	public function getHeader(string $key): string {
		foreach ($this->headers as $name => $value) {
			if (strcasecmp($name, $key) === 0) {
				return $value;
			}
		}
		return '';
	}

	public function getStatusCode(): int {
		return $this->status;
	}

	/** @return array<string,list<string>> */
	public function getHeaders(): array {
		return array_map(static fn (string $value): array => [$value], $this->headers);
	}
}

/** Records every request and answers from a queue or a callback. */
final class RecordingClient implements IClient {
	/** @var list<array{method:string,uri:string,options:array<string,mixed>}> */
	public array $requests = [];
	/** @var callable(string,array<string,mixed>):IResponse */
	private $responder;

	/** @param callable(string,array<string,mixed>):IResponse $responder */
	public function __construct(callable $responder) {
		$this->responder = $responder;
	}

	public function get(string $uri, array $options = []): IResponse {
		$this->requests[] = ['method' => 'GET', 'uri' => $uri, 'options' => $options];
		return ($this->responder)($uri, $options);
	}

	public function post(string $uri, array $options = []): IResponse {
		$this->requests[] = ['method' => 'POST', 'uri' => $uri, 'options' => $options];
		throw new \LogicException('API tools must never POST');
	}
}

final class RecordingClientService implements IClientService {
	public function __construct(
		public RecordingClient $client,
	) {
	}

	public function newClient(): IClient {
		return $this->client;
	}
}

final class Fixture {
	public static function path(string $name): string {
		return dirname(__DIR__, 3) . '/fixtures/openapi/' . $name;
	}

	public static function read(string $name): string {
		return (string)file_get_contents(self::path($name));
	}
}
