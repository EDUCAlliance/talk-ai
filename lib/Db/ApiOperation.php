<?php

declare(strict_types=1);

namespace OCA\EducAI\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Stable identity of one operation within a connection. The definition lives
 * in the revision manifest; this row only owns the ID that tool names and bot
 * assignments refer to. Tombstoned rows are never revived.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getConnectionId()
 * @method void setConnectionId(int $connectionId)
 * @method string getMatchKey()
 * @method void setMatchKey(string $matchKey)
 * @method string getMatchHash()
 * @method void setMatchHash(string $matchHash)
 * @method ?string getActiveKey()
 * @method void setActiveKey(?string $activeKey)
 * @method ?string getOperationIdHint()
 * @method void setOperationIdHint(?string $operationIdHint)
 * @method ?string getLastLabel()
 * @method void setLastLabel(?string $lastLabel)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method ?int getTombstonedAt()
 * @method void setTombstonedAt(?int $tombstonedAt)
 */
class ApiOperation extends Entity {
	protected int $connectionId = 0;
	protected string $matchKey = '';
	protected string $matchHash = '';
	protected ?string $activeKey = null;
	protected ?string $operationIdHint = null;
	protected ?string $lastLabel = null;
	protected int $createdAt = 0;
	protected ?int $tombstonedAt = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('connectionId', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('tombstonedAt', 'integer');
	}

	public static function hashMatchKey(string $matchKey): string {
		return hash('sha256', $matchKey);
	}

	public static function activeKeyFor(int $connectionId, string $matchKey): string {
		return hash('sha256', $connectionId . "\n" . $matchKey);
	}

	public function isTombstoned(): bool {
		return $this->tombstonedAt !== null;
	}
}
