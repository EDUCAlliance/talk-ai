<?php

declare(strict_types=1);

namespace OCA\EducAI\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method int getRootNodeId()
 * @method void setRootNodeId(int $rootNodeId)
 * @method string getRootPath()
 * @method void setRootPath(string $rootPath)
 * @method string getLocation()
 * @method void setLocation(string $location)
 * @method ?int getCollectiveId()
 * @method void setCollectiveId(?int $collectiveId)
 * @method bool getActive()
 * @method void setActive(bool $active)
 * @method ?int getLastSyncedAt()
 * @method void setLastSyncedAt(?int $lastSyncedAt)
 * @method ?string getLastError()
 * @method void setLastError(?string $lastError)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class WikiRoot extends Entity implements JsonSerializable {
	protected int $rootNodeId = 0;
	protected string $rootPath = '';
	protected string $location = 'personal_files';
	protected ?int $collectiveId = null;
	protected bool $active = true;
	protected ?int $lastSyncedAt = null;
	protected ?string $lastError = null;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('rootNodeId', 'integer');
		$this->addType('collectiveId', 'integer');
		$this->addType('active', 'boolean');
		$this->addType('lastSyncedAt', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'root_node_id' => $this->rootNodeId,
			'root_path' => $this->rootPath,
			'location' => $this->location,
			'collective_id' => $this->collectiveId,
			'active' => $this->active,
			'last_synced_at' => $this->lastSyncedAt,
			'last_error' => $this->lastError,
			'created_at' => $this->createdAt,
			'updated_at' => $this->updatedAt,
		];
	}
}
