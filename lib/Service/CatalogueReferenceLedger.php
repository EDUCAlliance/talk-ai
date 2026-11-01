<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

/**
 * Catalogue records actually shown to the model during the current agent run.
 *
 * Only these records may be expanded from reference markers; they are the
 * ground truth when the final answer is checked for contradicting links.
 *
 * @psalm-type CatalogueReference = array{id:string,title:string,canonical_url:?string,application_url:?string,is_past:bool}
 */
final class CatalogueReferenceLedger {
	public const MAX_RECORDS = 200;

	private bool $active = false;
	private bool $used = false;
	/** @var array<string,CatalogueReference> */
	private array $records = [];

	public function begin(): void {
		$this->active = true;
		$this->used = false;
		$this->records = [];
	}

	public function end(): void {
		$this->active = false;
	}

	public function markUsed(): void {
		if ($this->active) {
			$this->used = true;
		}
	}

	/** @param CatalogueReference $reference */
	public function record(array $reference): void {
		if (!$this->active) {
			return;
		}
		$this->used = true;
		if (!isset($this->records[$reference['id']]) && count($this->records) >= self::MAX_RECORDS) {
			return;
		}
		$this->records[$reference['id']] = $reference;
	}

	public function wasUsed(): bool {
		return $this->used;
	}

	/** @return array<string,CatalogueReference> In first-seen order */
	public function records(): array {
		return $this->records;
	}
}
