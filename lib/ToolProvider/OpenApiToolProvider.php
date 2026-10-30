<?php

declare(strict_types=1);

namespace OCA\EducAI\ToolProvider;

use OCA\EducAI\Db\ApiConnectionMapper;
use OCA\EducAI\Db\ApiOperationMapper;
use OCA\EducAI\OpenApi\OpenApiExecutionService;
use OCA\EducAI\OpenApi\OpenApiToolCatalog;
use OCA\EducAI\Service\ToolExecutionPolicyService;
use Psr\Log\LoggerInterface;

/**
 * Admin-approved read operations of OpenAPI connections as name-based tools
 * ("api_<connection>_<operation>"). Assignments survive a disabled connection
 * and show up as unavailable; they never execute until approved again.
 */
class OpenApiToolProvider implements IToolProvider {
	/** @var array<string,mixed>|null */
	private ?array $context = null;
	/** @var array<string,array{label?:string,summary?:string}>|null */
	private ?array $metadata = null;

	public function __construct(
		private OpenApiToolCatalog $catalog,
		private OpenApiExecutionService $execution,
		private ApiConnectionMapper $connections,
		private ApiOperationMapper $operations,
		private ToolExecutionPolicyService $policies,
		private LoggerInterface $logger,
	) {
	}

	/** @return array<int,array<string,mixed>> */
	public function getTools(): array {
		$tools = [];
		foreach ($this->catalog->exposed() as $entry) {
			$definition = OpenApiToolCatalog::definition($entry['connection'], $entry['operation'], $entry['config']);
			$tools[] = [
				'name' => $entry['name'],
				'description' => $definition['description'],
				'schema' => $definition['schema'],
				'policy' => $this->policies->readToolPolicy('openapi'),
				'label' => $entry['connection']->getName() . ': ' . $entry['config']['label'],
				'summary' => mb_substr((string)($entry['config']['description'] ?? ''), 0, 200),
			];
		}
		return $tools;
	}

	public function providesTool(string $toolName): bool {
		return OpenApiToolCatalog::parseToolName($toolName) !== null;
	}

	/**
	 * @param array<string,mixed> $arguments
	 * @param array<string,mixed> $config Ignored: everything comes from the approved revision
	 * @return array{content:array<int,array{type:string,text:string}>,isError:bool}
	 */
	public function executeTool(string $toolName, array $arguments, array $config = []): array {
		$botId = $this->context['bot_id'] ?? null;
		return $this->execution->execute($toolName, $arguments, OpenApiExecutionService::DEFAULT_TIMEOUT_SECONDS, [
			'bot_id' => is_int($botId) ? $botId : null,
		]);
	}

	/**
	 * Labels of every operation identity ever approved, including disabled
	 * and removed ones, so existing assignments stay recognizable.
	 *
	 * @return array<string,array{label?:string,summary?:string}>
	 */
	public function getToolMetadata(): array {
		if ($this->metadata !== null) {
			return $this->metadata;
		}
		$metadata = [];
		try {
			$names = [];
			foreach ($this->connections->findAll() as $connection) {
				$names[$connection->getId()] = $connection->getName();
			}
			foreach ($this->operations->findLabeled() as $identity) {
				$connectionName = $names[$identity->getConnectionId()] ?? null;
				if ($connectionName !== null) {
					$metadata[OpenApiToolCatalog::toolName($identity->getConnectionId(), $identity->getId())] = [
						'label' => $connectionName . ': ' . $identity->getLastLabel(),
					];
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('EducAI: OpenAPI tool metadata unavailable', ['exception_class' => get_class($e)]);
		}
		return $this->metadata = $metadata;
	}

	public function setInvocationContext(?array $context): void {
		$this->context = $context;
		$this->metadata = null;
		$this->catalog->reset();
	}
}
