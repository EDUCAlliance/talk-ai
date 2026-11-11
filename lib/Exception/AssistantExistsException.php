<?php

declare(strict_types=1);

namespace OCA\EducAI\Exception;

use Exception;

class AssistantExistsException extends Exception {
	public function __construct() {
		parent::__construct('This user already has a personal assistant.');
	}
}
