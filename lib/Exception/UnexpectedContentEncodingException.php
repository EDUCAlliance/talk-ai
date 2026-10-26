<?php

declare(strict_types=1);

namespace OCA\EducAI\Exception;

use RuntimeException;

final class UnexpectedContentEncodingException extends RuntimeException {
	public function __construct() {
		parent::__construct('The remote server returned compressed content despite an identity request. Configure it to return uncompressed responses.');
	}
}
