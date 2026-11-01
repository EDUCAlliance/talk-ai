<?php

declare(strict_types=1);

namespace OCA\EducAI\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Admin-managed OpenAPI connection. The credential is stored encrypted and is
 * never serialized; see ApiConnectionService::present().
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getName()
 * @method void setName(string $name)
 * @method ?string getDescription()
 * @method void setDescription(?string $description)
 * @method ?bool getEnabled()
 * @method void setEnabled(?bool $enabled)
 * @method ?string getBaseUrl()
 * @method void setBaseUrl(?string $baseUrl)
 * @method string getAuthMode()
 * @method void setAuthMode(string $authMode)
 * @method ?string getAuthHeader()
 * @method void setAuthHeader(?string $authHeader)
 * @method ?string getCredential()
 * @method void setCredential(?string $credential)
 * @method ?int getActiveRevisionId()
 * @method void setActiveRevisionId(?int $activeRevisionId)
 * @method ?string getDataVisibility()
 * @method void setDataVisibility(?string $dataVisibility)
 * @method string getAssignPolicy()
 * @method void setAssignPolicy(string $assignPolicy)
 * @method ?string getAssignGroups()
 * @method void setAssignGroups(?string $assignGroups)
 * @method ?string getBotVisibilities()
 * @method void setBotVisibilities(?string $botVisibilities)
 * @method ?int getLastCheckAt()
 * @method void setLastCheckAt(?int $lastCheckAt)
 * @method ?string getLastCheckStatus()
 * @method void setLastCheckStatus(?string $lastCheckStatus)
 * @method ?string getLastCheckMessage()
 * @method void setLastCheckMessage(?string $lastCheckMessage)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method ?string getUpdatedBy()
 * @method void setUpdatedBy(?string $updatedBy)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class ApiConnection extends Entity {
	public const AUTH_NONE = 'none';
	public const AUTH_BEARER = 'bearer';
	public const AUTH_API_KEY_HEADER = 'api_key_header';

	public const VISIBILITY_SHARED_FOR_BOT_AUDIENCE = 'shared_for_bot_audience';

	public const ASSIGN_ADMINS = 'admins';
	public const ASSIGN_ALL_USERS = 'all_users';
	public const ASSIGN_GROUPS = 'groups';

	public const BOT_VISIBILITIES = ['personal', 'groups', 'teams', 'global'];

	protected string $name = '';
	protected ?string $description = null;
	protected ?bool $enabled = false;
	protected ?string $baseUrl = null;
	protected string $authMode = self::AUTH_NONE;
	protected ?string $authHeader = null;
	protected ?string $credential = null;
	protected ?int $activeRevisionId = null;
	protected ?string $dataVisibility = null;
	protected string $assignPolicy = self::ASSIGN_ADMINS;
	protected ?string $assignGroups = null;
	protected ?string $botVisibilities = null;
	protected ?int $lastCheckAt = null;
	protected ?string $lastCheckStatus = null;
	protected ?string $lastCheckMessage = null;
	protected string $createdBy = '';
	protected int $createdAt = 0;
	protected ?string $updatedBy = null;
	protected int $updatedAt = 0;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('enabled', 'boolean');
		$this->addType('activeRevisionId', 'integer');
		$this->addType('lastCheckAt', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	public function isEnabled(): bool {
		return $this->enabled === true;
	}

	/** @return list<string> */
	public function getAssignGroupList(): array {
		return self::decodeList($this->assignGroups);
	}

	/** @param list<string> $groups */
	public function setAssignGroupList(array $groups): void {
		$this->setAssignGroups($groups === [] ? null : json_encode(array_values($groups), JSON_THROW_ON_ERROR));
	}

	/**
	 * Bot visibilities this connection may be assigned to; all when unset.
	 *
	 * @return list<string>
	 */
	public function getBotVisibilityList(): array {
		$list = self::decodeList($this->botVisibilities);
		return $list === [] ? self::BOT_VISIBILITIES : array_values(array_intersect(self::BOT_VISIBILITIES, $list));
	}

	/** @param list<string> $visibilities */
	public function setBotVisibilityList(array $visibilities): void {
		$visibilities = array_values(array_intersect(self::BOT_VISIBILITIES, $visibilities));
		$this->setBotVisibilities($visibilities === [] || count($visibilities) === count(self::BOT_VISIBILITIES)
			? null
			: json_encode($visibilities, JSON_THROW_ON_ERROR));
	}

	/** @return list<string> */
	private static function decodeList(?string $raw): array {
		if ($raw === null || $raw === '') {
			return [];
		}
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}
		return array_values(array_unique(array_filter($decoded, static fn ($value): bool => is_string($value) && $value !== '')));
	}
}
