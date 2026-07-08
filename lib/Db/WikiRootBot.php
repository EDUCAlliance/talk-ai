<?php

declare(strict_types=1);

namespace OCA\EducAI\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method int getRootId()
 * @method void setRootId(int $rootId)
 * @method int getBotId()
 * @method void setBotId(int $botId)
 * @method string getConfigHash()
 * @method void setConfigHash(string $configHash)
 * @method bool getActive()
 * @method void setActive(bool $active)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class WikiRootBot extends Entity implements JsonSerializable {
	protected int $rootId = 0;
	protected int $botId = 0;
	protected string $configHash = '';
	protected bool $active = true;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('rootId', 'integer');
		$this->addType('botId', 'integer');
		$this->addType('active', 'boolean');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'root_id' => $this->rootId,
			'bot_id' => $this->botId,
			'config_hash' => $this->configHash,
			'active' => $this->active,
			'created_at' => $this->createdAt,
			'updated_at' => $this->updatedAt,
		];
	}
}
