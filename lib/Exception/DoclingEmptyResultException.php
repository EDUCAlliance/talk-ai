<?php

declare(strict_types=1);

namespace OCA\EducAI\Exception;

use Exception;

/**
 * Docling converted the document successfully but found no text in it,
 * for example a photo without writing or an empty document.
 */
final class DoclingEmptyResultException extends Exception {
}
