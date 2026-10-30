<?php

declare(strict_types=1);

namespace OCA\EducAI\Exception;

/** A course-link setting failed server-side validation; the reason is a stable code for the UI. */
class CatalogueLinkConfigurationException extends \InvalidArgumentException {
	public function __construct(
		private string $reason,
		private string $field,
	) {
		parent::__construct('Invalid catalogue link configuration: ' . $reason);
	}

	public function getReason(): string {
		return $this->reason;
	}

	/** One of link_field, link_template or link_origins. */
	public function getField(): string {
		return $this->field;
	}
}
