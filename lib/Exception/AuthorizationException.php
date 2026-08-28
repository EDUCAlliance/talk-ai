<?php

declare(strict_types=1);

namespace OCA\EducAI\Exception;

/**
 * Domain exception for denied bot-management operations.
 *
 * The exception message is intended for logs only. HTTP controllers map this
 * type to a stable error code and a localized, safe response message.
 */
class AuthorizationException extends \Exception {
}
