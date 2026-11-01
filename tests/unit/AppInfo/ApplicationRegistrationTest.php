<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Services registered by hand in Application::register() are built with explicit arguments.
 * A service dependency added to a constructor but not to its factory stays null in a real
 * Nextcloud while unit tests that construct the class directly still pass, as happened with
 * BotService and ApiToolAccess.
 */
final class ApplicationRegistrationTest extends TestCase {
	public function testEveryHandWiredServiceReceivesAllServiceDependencies(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../../lib/AppInfo/Application.php');
		preg_match_all('/^use\s+([\\\\A-Za-z0-9_]+);/m', $source, $imports);
		$aliases = [];
		foreach ($imports[1] as $class) {
			$aliases[substr($class, (int)strrpos($class, '\\') + 1)] = $class;
		}
		preg_match_all(
			'/registerService\(\s*[\\\\A-Za-z0-9_]+::class\s*,\s*function\s*\([^)]*\)\s*(?::\s*[\\\\A-Za-z0-9_]+\s*)?\{\s*return\s+new\s+([\\\\A-Za-z0-9_]+)\((.*?)\);\s*\}\s*\);/s',
			$source,
			$factories,
			PREG_SET_ORDER,
		);
		self::assertGreaterThan(50, count($factories), 'The factory pattern no longer matches Application.php');

		$missing = [];
		foreach ($factories as [, $class, $arguments]) {
			$class = ltrim($class, '\\');
			$class = str_contains($class, '\\') ? $class : ($aliases[$class] ?? 'OCA\\EducAI\\AppInfo\\' . $class);
			$constructor = (new ReflectionClass($class))->getConstructor();
			$services = 0;
			foreach ($constructor?->getParameters() ?? [] as $parameter) {
				$type = $parameter->getType();
				if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
					$services++;
				}
			}
			// An explicit null is a deliberate default (RagIngestionService builds its own locator).
			$passed = substr_count($arguments, '$c->get(') + substr_count($arguments, '$c->query(')
				+ preg_match_all('/(?:^|,)\s*null\s*(?=,|$)/', $arguments);
			if ($passed < $services) {
				$missing[] = sprintf('%s: %d of %d service dependencies passed', $class, $passed, $services);
			}
		}
		self::assertSame([], $missing);
	}
}
