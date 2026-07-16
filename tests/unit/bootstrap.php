<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

if (!interface_exists(\Psr\Log\LoggerInterface::class)) {
	eval(<<<'PHP'
namespace Psr\Log;

interface LoggerInterface {
	public function emergency($message, array $context = []);
	public function alert($message, array $context = []);
	public function critical($message, array $context = []);
	public function error($message, array $context = []);
	public function warning($message, array $context = []);
	public function notice($message, array $context = []);
	public function info($message, array $context = []);
	public function debug($message, array $context = []);
	public function log($level, $message, array $context = []);
}
PHP);
}

if (!class_exists(\GuzzleHttp\Cookie\CookieJar::class)) {
	eval(<<<'PHP'
namespace GuzzleHttp\Cookie;

class CookieJar {
	public array $cookies = [];

	public function setCookie($cookie): void {
		$this->cookies[] = $cookie;
	}
}
PHP);
}

if (!class_exists(\GuzzleHttp\Cookie\SetCookie::class)) {
	eval(<<<'PHP'
namespace GuzzleHttp\Cookie;

class SetCookie {
	public function __construct(public array $data = []) {}
}
PHP);
}

spl_autoload_register(static function (string $class): void {
	if (!str_starts_with($class, 'OCP\\')) {
		return;
	}

	if (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false)) {
		return;
	}

	if ($class === 'OCP\\AppFramework\\Db\\Entity') {
		eval(<<<'PHP'
namespace OCP\AppFramework\Db;

class Entity {
	protected ?int $id = null;

	protected function addType(string $name, string $type): void {}

	protected function markFieldUpdated(string $field): void {}

	public function __call(string $name, array $arguments) {
		if (preg_match('/^(get|set)(.+)$/', $name, $matches) !== 1) {
			throw new \BadMethodCallException(sprintf('Unknown method %s', $name));
		}

		$property = lcfirst($matches[2]);
		if ($matches[1] === 'get') {
			return $this->$property ?? null;
		}

		$this->$property = $arguments[0] ?? null;
		$this->markFieldUpdated($property);

		return null;
	}
}
PHP);
		return;
	}

	if ($class === 'OCP\\AppFramework\\Db\\QBMapper') {
		eval(<<<'PHP'
namespace OCP\AppFramework\Db;

class QBMapper {
	protected mixed $db;
	private string $tableName = '';

	public function __construct($db = null, string $tableName = '', string $entityClass = '') {
		$this->db = $db;
		$this->tableName = $tableName;
	}

	protected function getTableName(): string {
		return $this->tableName;
	}

	protected function findEntity($qb) {
		return null;
	}

	protected function findEntities($qb): array {
		return [];
	}

	public function insert($entity) {
		return $entity;
	}

	public function update($entity) {
		return $entity;
	}

	public function delete($entity): void {}
}
PHP);
		return;
	}

	if ($class === 'OCP\\AppFramework\\Db\\DoesNotExistException') {
		eval(<<<'PHP'
namespace OCP\AppFramework\Db;

class DoesNotExistException extends \Exception {}
PHP);
			return;
	}

	if ($class === 'OCP\\AppFramework\\Controller') {
		eval(<<<'PHP'
namespace OCP\AppFramework;

class Controller {
	protected string $appName;
	protected mixed $request;

	public function __construct(string $appName, $request) {
		$this->appName = $appName;
		$this->request = $request;
	}
}
PHP);
			return;
	}

	if ($class === 'OCP\\AppFramework\\Http\\DataResponse') {
		eval(<<<'PHP'
namespace OCP\AppFramework\Http;

class DataResponse {
	private mixed $data;
	private int $status;

	public function __construct($data = [], int $status = 200) {
		$this->data = $data;
		$this->status = $status;
	}

	public function getData() {
		return $this->data;
	}

	public function getStatus(): int {
		return $this->status;
	}
}
PHP);
			return;
	}

	if ($class === 'OCP\\Http\\Client\\IResponse') {
		eval(<<<'PHP'
namespace OCP\Http\Client;

interface IResponse {
	public function getBody(): string;
	public function getHeader(string $key): string;
	public function getStatusCode(): int;
}
PHP);
			return;
	}

	if ($class === 'OCP\\Http\\Client\\IClient') {
		eval(<<<'PHP'
namespace OCP\Http\Client;

interface IClient {
	public function get(string $uri, array $options = []): IResponse;
	public function post(string $uri, array $options = []): IResponse;
}
PHP);
			return;
	}

	if ($class === 'OCP\\Http\\Client\\IClientService') {
		eval(<<<'PHP'
namespace OCP\Http\Client;

interface IClientService {
	public function newClient(): IClient;
}
PHP);
			return;
	}

	if ($class === 'OCP\\Files\\Node') {
		eval(<<<'PHP'
namespace OCP\Files;

interface Node {
	public function getId();
	public function getName();
	public function getPath();
	public function getParent();
}
PHP);
			return;
	}

	if ($class === 'OCP\\Files\\File') {
		eval(<<<'PHP'
namespace OCP\Files;

if (!interface_exists(Node::class)) {
	interface Node {
		public function getId();
		public function getName();
		public function getPath();
		public function getParent();
	}
}

interface File extends Node {
	public function getContent();
	public function putContent($data);
	public function getSize();
	public function getMimeType();
}
PHP);
			return;
	}

	if ($class === 'OCP\\Files\\Folder') {
		eval(<<<'PHP'
namespace OCP\Files;

if (!interface_exists(Node::class)) {
	interface Node {
		public function getId();
		public function getName();
		public function getPath();
		public function getParent();
	}
}

interface Folder extends Node {
	public function get(string $path);
	public function getById($id);
	public function newFolder(string $path);
	public function newFile(string $path);
	public function getDirectoryListing();
}
PHP);
			return;
	}

	if ($class === 'OCP\\Files\\NotFoundException') {
		eval(<<<'PHP'
namespace OCP\Files;

class NotFoundException extends \Exception {}
PHP);
			return;
	}

	if ($class === 'OCP\\Files\\IRootFolder') {
		eval(<<<'PHP'
namespace OCP\Files;

interface IRootFolder {
	public function getById(int $id): array;
	public function getUserFolder(string $userId): Folder;
}
PHP);
			return;
	}

	if ($class === 'OCP\\Files\\SimpleFS\\ISimpleFile') {
		eval(<<<'PHP'
namespace OCP\Files\SimpleFS;

interface ISimpleFile {
	public function getName(): string;
	public function getSize(): int|float;
	public function getETag(): string;
	public function getMTime(): int;
	public function getContent(): string;
	public function putContent($data): void;
	public function delete(): void;
	public function getMimeType(): string;
	public function getExtension(): string;
	public function read();
	public function write();
}
PHP);
			return;
	}

	if ($class === 'OCP\\Files\\SimpleFS\\ISimpleFolder') {
		eval(<<<'PHP'
namespace OCP\Files\SimpleFS;

interface ISimpleFolder {
	public function getDirectoryListing(): array;
	public function fileExists(string $name): bool;
	public function getFile(string $name): ISimpleFile;
	public function newFile(string $name, $content = null): ISimpleFile;
	public function delete(): void;
	public function getName(): string;
	public function getFolder(string $name): ISimpleFolder;
	public function newFolder(string $path): ISimpleFolder;
}
PHP);
			return;
	}

	if ($class === 'OCP\\Files\\SimpleFS\\ISimpleRoot') {
		eval(<<<'PHP'
namespace OCP\Files\SimpleFS;

interface ISimpleRoot {
	public function getFolder(string $name): ISimpleFolder;
	public function getDirectoryListing(): array;
	public function newFolder(string $name): ISimpleFolder;
}
PHP);
			return;
	}

	if ($class === 'OCP\\Files\\IAppData') {
		eval(<<<'PHP'
namespace OCP\Files;

interface IAppData extends \OCP\Files\SimpleFS\ISimpleRoot {}
PHP);
			return;
	}

	if ($class === 'OCP\\IRequest') {
		eval(<<<'PHP'
namespace OCP;

interface IRequest {
	public function getHeader(string $name): string;
	public function getServerHost(): string;
	public function getParam(string $key, $default = null);
	public function getUploadedFile(string $key);
}
PHP);
			return;
	}

	if ($class === 'OCP\\IConfig') {
		eval(<<<'PHP'
namespace OCP;

interface IConfig {
	public function getAppValue(string $appName, string $key, string $default = ''): string;
	public function setAppValue(string $appName, string $key, string $value): void;
	public function deleteAppValue(string $appName, string $key): void;
}
PHP);
			return;
	}

	if ($class === 'OCP\\IURLGenerator') {
		eval(<<<'PHP'
namespace OCP;

interface IURLGenerator {
	public function linkToRoute(string $routeName, array $arguments = []): string;
	public function imagePath(string $appName, string $file): string;
	public function getAbsoluteURL(string $url): string;
}
PHP);
			return;
	}

	if ($class === 'OCP\\DB\\QueryBuilder\\IQueryBuilder') {
		eval(<<<'PHP'
namespace OCP\DB\QueryBuilder;

interface IQueryBuilder {
	public const PARAM_INT = 1;
	public const PARAM_STR = 2;
	public const PARAM_BOOL = 5;
	public const PARAM_INT_ARRAY = 101;
	public const PARAM_STR_ARRAY = 102;
}
PHP);
			return;
	}

	if ($class === 'OCP\\BackgroundJob\\IJobList') {
		eval(<<<'PHP'
namespace OCP\BackgroundJob;

interface IJobList {
	public function add($job, mixed $argument = null): void;
	public function scheduleAfter(string $job, int $runAfter, mixed $argument = null): void;
	public function remove($job, mixed $argument = null): void;
	public function has($job, mixed $argument): bool;
}
PHP);
			return;
	}

	if ($class === 'OCP\\BackgroundJob\\QueuedJob') {
		eval(<<<'PHP'
namespace OCP\BackgroundJob;

abstract class QueuedJob {
	public function __construct(protected mixed $time = null) {}
	abstract public function run($arguments): void;
}
PHP);
			return;
	}

	if ($class === 'OCP\\BackgroundJob\\TimedJob') {
		eval(<<<'PHP'
namespace OCP\BackgroundJob;

abstract class TimedJob {
	public const TIME_INSENSITIVE = 0;
	public const TIME_SENSITIVE = 1;

	protected int $interval = 0;
	protected int $timeSensitivity = self::TIME_SENSITIVE;

	public function __construct(protected mixed $time = null) {}

	public function setInterval(int $seconds): void {
		$this->interval = $seconds;
	}

	public function setTimeSensitivity(int $sensitivity): void {
		$this->timeSensitivity = $sensitivity;
	}

	abstract protected function run($arguments): void;
}
PHP);
		return;
	}

	if ($class === 'OCP\\IUser') {
		eval(<<<'PHP'
namespace OCP;

interface IUser {
	public function getUID(): string;
	public function getDisplayName(): ?string;
}
PHP);
			return;
	}

	if ($class === 'OCP\\IUserManager') {
		eval(<<<'PHP'
namespace OCP;

interface IUserManager {
	public function get(string $uid): ?IUser;
}
PHP);
			return;
	}

	$parts = explode('\\', $class);
	$shortName = array_pop($parts);
	$namespace = implode('\\', $parts);
	$kind = str_starts_with($shortName, 'I') ? 'interface' : 'class';

	eval(sprintf('namespace %s; %s %s {}', $namespace, $kind, $shortName));
});
