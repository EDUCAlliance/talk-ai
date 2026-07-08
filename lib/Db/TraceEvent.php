<?php

declare(strict_types=1);

namespace OCA\EducAI\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method int getRunId()
 * @method void setRunId(int $runId)
 * @method int getSequence()
 * @method void setSequence(int $sequence)
 * @method string getEventType()
 * @method void setEventType(string $eventType)
 * @method ?string getStatus()
 * @method void setStatus(?string $status)
 * @method ?string getToolName()
 * @method void setToolName(?string $toolName)
 * @method ?int getDurationMs()
 * @method void setDurationMs(?int $durationMs)
 * @method ?string getPayloadJson()
 * @method void setPayloadJson(?string $payloadJson)
 * @method ?string getPayloadPreview()
 * @method void setPayloadPreview(?string $payloadPreview)
 * @method ?string getResultJson()
 * @method void setResultJson(?string $resultJson)
 * @method ?string getResultPreview()
 * @method void setResultPreview(?string $resultPreview)
 * @method ?string getErrorMessage()
 * @method void setErrorMessage(?string $errorMessage)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class TraceEvent extends Entity implements JsonSerializable {
	protected int $runId = 0;
	protected int $sequence = 0;
	protected string $eventType = '';
	protected ?string $status = null;
	protected ?string $toolName = null;
	protected ?int $durationMs = null;
	protected ?string $payloadJson = null;
	protected ?string $payloadPreview = null;
	protected ?string $resultJson = null;
	protected ?string $resultPreview = null;
	protected ?string $errorMessage = null;
	protected int $createdAt = 0;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('runId', 'integer');
		$this->addType('sequence', 'integer');
		$this->addType('durationMs', 'integer');
		$this->addType('createdAt', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'run_id' => $this->runId,
			'sequence' => $this->sequence,
			'event_type' => $this->eventType,
			'status' => $this->status,
			'tool_name' => $this->toolName,
			'duration_ms' => $this->durationMs,
			'payload_json' => $this->decodeJson($this->payloadJson),
			'payload_preview' => $this->payloadPreview,
			'result_json' => $this->decodeJson($this->resultJson),
			'result_preview' => $this->resultPreview,
			'error_message' => $this->errorMessage,
			'created_at' => $this->createdAt,
		];
	}

	/**
	 * @return mixed
	 */
	private function decodeJson(?string $json) {
		if ($json === null || $json === '') {
			return null;
		}

		$decoded = json_decode($json, true);
		return json_last_error() === JSON_ERROR_NONE ? $decoded : $json;
	}
}
