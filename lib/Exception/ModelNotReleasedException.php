<?php

declare(strict_types=1);

namespace OCA\EducAI\Exception;

class ModelNotReleasedException extends AuthorizationException {
	public function __construct(
		public readonly bool $selectionDisabled,
	) {
		parent::__construct($selectionDisabled
			? 'Individual model selection is disabled. Use the administrator default.'
			: 'This model has not been released by the administrator.');
	}
}
