<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

final class AgentTurn {
	public const COMPATIBILITY_NATIVE = 'native';
	public const COMPATIBILITY_LEGACY_JSON = 'legacy_json';
	public const COMPATIBILITY_LEGACY_XML = 'legacy_xml';

	/**
	 * @param array<int,array<string,mixed>> $toolCalls
	 * @param array<string,mixed>|null $usage
	 * @param array<string,int|string> $rateLimitHeaders
	 */
	public function __construct(
		private string $text,
		private array $toolCalls,
		private ?string $stopReason,
		private ?string $model,
		private ?string $modelReference,
		private ?string $modelEndpoint,
		private ?array $usage,
		private array $rateLimitHeaders,
		private string $compatibilitySource = self::COMPATIBILITY_NATIVE,
	) {
	}

	public function getText(): string {
		return $this->text;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function getToolCalls(): array {
		return $this->toolCalls;
	}

	public function getStopReason(): ?string {
		return $this->stopReason;
	}

	public function getModel(): ?string {
		return $this->model;
	}

	public function getModelReference(): ?string {
		return $this->modelReference;
	}

	public function getModelEndpoint(): ?string {
		return $this->modelEndpoint;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function getUsage(): ?array {
		return $this->usage;
	}

	/**
	 * @return array<string,int|string>
	 */
	public function getRateLimitHeaders(): array {
		return $this->rateLimitHeaders;
	}

	public function getCompatibilitySource(): string {
		return $this->compatibilitySource;
	}

	public function isEmpty(): bool {
		return trim($this->text) === '' && $this->toolCalls === [];
	}
}
