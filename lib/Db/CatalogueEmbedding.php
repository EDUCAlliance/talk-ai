<?php

declare(strict_types=1);

namespace OCA\EducAI\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Entity for catalogue course embeddings used in semantic search
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getCourseId()
 * @method void setCourseId(int $courseId)
 * @method string getSearchableText()
 * @method void setSearchableText(string $searchableText)
 * @method string getEmbedding()
 * @method void setEmbedding(string $embedding)
 * @method ?string getEmbeddingModel()
 * @method void setEmbeddingModel(?string $embeddingModel)
 * @method string getCourseData()
 * @method void setCourseData(string $courseData)
 * @method int getIsPast()
 * @method void setIsPast(int $isPast)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class CatalogueEmbedding extends Entity implements JsonSerializable {
	protected int $courseId = 0;
	protected string $searchableText = '';
	protected string $embedding = '';
	protected ?string $embeddingModel = null;
	protected string $courseData = '';
	/** @var int 0 = current, 1 = past */
	protected int $isPast = 0;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;

	public function __construct() {
		$this->addType('courseId', 'integer');
		$this->addType('isPast', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'course_id' => $this->courseId,
			'searchable_text' => $this->searchableText,
			'embedding_model' => $this->embeddingModel,
			'course_data' => $this->courseData,
			'is_past' => $this->isPast,
			'created_at' => $this->createdAt,
			'updated_at' => $this->updatedAt,
		];
	}

	/**
	 * Get decoded course data as array
	 *
	 * @return array<string,mixed>
	 */
	public function getCourseDataArray(): array {
		$data = json_decode($this->courseData, true);
		return is_array($data) ? $data : [];
	}

	/**
	 * Get decoded embedding vector
	 *
	 * @return array<int,float>|null
	 */
	public function getEmbeddingVector(): ?array {
		$vector = json_decode($this->embedding, true);
		if (!is_array($vector)) {
			return null;
		}
		return array_map('floatval', $vector);
	}
}
