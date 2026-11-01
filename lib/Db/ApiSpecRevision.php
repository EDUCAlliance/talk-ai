<?php

declare(strict_types=1);

namespace OCA\EducAI\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One imported OpenAPI document with its compiled manifest and the admin's
 * per-operation review. Approved revisions are immutable except for revoking
 * operations.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getConnectionId()
 * @method void setConnectionId(int $connectionId)
 * @method string getSha256()
 * @method void setSha256(string $sha256)
 * @method string getSourceKind()
 * @method void setSourceKind(string $sourceKind)
 * @method ?string getSourceUrl()
 * @method void setSourceUrl(?string $sourceUrl)
 * @method string getDocument()
 * @method void setDocument(string $document)
 * @method string getOasVersion()
 * @method void setOasVersion(string $oasVersion)
 * @method string getManifest()
 * @method void setManifest(string $manifest)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method ?string getOpConfig()
 * @method void setOpConfig(?string $opConfig)
 * @method ?string getApprovedBaseUrl()
 * @method void setApprovedBaseUrl(?string $approvedBaseUrl)
 * @method ?string getApprovedAuth()
 * @method void setApprovedAuth(?string $approvedAuth)
 * @method ?string getApprovedBy()
 * @method void setApprovedBy(?string $approvedBy)
 * @method ?int getApprovedAt()
 * @method void setApprovedAt(?int $approvedAt)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method ?string getUpdatedBy()
 * @method void setUpdatedBy(?string $updatedBy)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class ApiSpecRevision extends Entity {
	public const STATUS_DRAFT = 'draft';
	public const STATUS_APPROVED = 'approved';
	public const STATUS_SUPERSEDED = 'superseded';
	public const STATUS_DISCARDED = 'discarded';

	public const SOURCE_FILE = 'file';
	public const SOURCE_URL = 'url';
	public const SOURCE_PRESET = 'preset';

	protected int $connectionId = 0;
	protected string $sha256 = '';
	protected string $sourceKind = self::SOURCE_FILE;
	protected ?string $sourceUrl = null;
	protected string $document = '';
	protected string $oasVersion = '';
	protected string $manifest = '{}';
	protected string $status = self::STATUS_DRAFT;
	protected ?string $opConfig = null;
	protected ?string $approvedBaseUrl = null;
	protected ?string $approvedAuth = null;
	protected ?string $approvedBy = null;
	protected ?int $approvedAt = null;
	protected string $createdBy = '';
	protected int $createdAt = 0;
	protected ?string $updatedBy = null;
	protected int $updatedAt = 0;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('connectionId', 'integer');
		$this->addType('approvedAt', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	/** @return array<string,mixed> */
	public function getManifestArray(): array {
		$decoded = json_decode($this->manifest, true);
		return is_array($decoded) ? $decoded : [];
	}

	/** @param array<string,mixed> $manifest */
	public function setManifestArray(array $manifest): void {
		$this->setManifest(json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}

	/** @return array<string,array<string,mixed>> Keyed by operation ID */
	public function getOpConfigArray(): array {
		$decoded = $this->opConfig !== null ? json_decode($this->opConfig, true) : null;
		if (!is_array($decoded)) {
			return [];
		}
		$result = [];
		foreach ($decoded as $id => $config) {
			if (is_array($config)) {
				$result[(string)$id] = $config;
			}
		}
		return $result;
	}

	/** @param array<string,array<string,mixed>> $config */
	public function setOpConfigArray(array $config): void {
		$this->setOpConfig($config === [] ? null : json_encode((object)$config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}

	/** @return array<string,mixed> */
	public function getApprovedAuthArray(): array {
		$decoded = $this->approvedAuth !== null ? json_decode($this->approvedAuth, true) : null;
		return is_array($decoded) ? $decoded : [];
	}

	public function isApproved(): bool {
		return $this->status === self::STATUS_APPROVED;
	}

	public function isDraft(): bool {
		return $this->status === self::STATUS_DRAFT;
	}
}
