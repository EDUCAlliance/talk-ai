<?php

declare(strict_types=1);

namespace OCA\EducAI\OpenApi;

use OCA\EducAI\Exception\AuthorizationException;

/** An API tool may not be given to a bot by this user or with this visibility. */
final class ApiToolAssignmentException extends AuthorizationException {
	public const TOOL_NOT_AVAILABLE = 'tool_not_available';
	public const NOT_PERMITTED = 'assign_not_permitted';
	public const VISIBILITY_NOT_ALLOWED = 'bot_visibility_not_allowed';

	public function __construct(
		private string $toolName,
		private string $reason,
		private ?string $label = null,
	) {
		parent::__construct('API tool ' . $toolName . ' cannot be assigned: ' . $reason);
	}

	public function getToolName(): string {
		return $this->toolName;
	}

	public function getReason(): string {
		return $this->reason;
	}

	/** Admin-reviewed "<connection>: <operation>" label, when known. */
	public function getLabel(): ?string {
		return $this->label;
	}
}
