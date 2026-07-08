<?php

declare(strict_types=1);

namespace OCA\EducAI\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Entity representing per-room, per-bot state for onboarding and response mode.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getBotId()
 * @method void setBotId(int $botId)
 * @method string getRoomToken()
 * @method void setRoomToken(string $roomToken)
 * @method ?string getResponseMode()
 * @method void setResponseMode(?string $responseMode)
 * @method string getOnboardingStatus()
 * @method void setOnboardingStatus(string $onboardingStatus)
 * @method ?string getCurrentQuestionId()
 * @method void setCurrentQuestionId(?string $currentQuestionId)
 * @method ?string getOnboardingAnswers()
 * @method void setOnboardingAnswers(?string $onboardingAnswers)
 * @method string getActivatedBy()
 * @method void setActivatedBy(string $activatedBy)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class ChatRoom extends Entity implements JsonSerializable {
	protected int $botId = 0;
	protected string $roomToken = '';
	protected ?string $responseMode = null;
	protected string $onboardingStatus = 'mode_selection';
	protected ?string $currentQuestionId = null;
	protected ?string $onboardingAnswers = null;
	protected string $activatedBy = '';
	protected int $createdAt = 0;
	protected int $updatedAt = 0;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('botId', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'bot_id' => $this->botId,
			'room_token' => $this->roomToken,
			'response_mode' => $this->responseMode,
			'onboarding_status' => $this->onboardingStatus,
			'current_question_id' => $this->currentQuestionId,
			'onboarding_answers' => $this->getOnboardingAnswersArray(),
			'activated_by' => $this->activatedBy,
			'created_at' => $this->createdAt,
			'updated_at' => $this->updatedAt,
		];
	}

	/**
	 * Check if onboarding is complete
	 */
	public function isOnboardingComplete(): bool {
		return $this->onboardingStatus === 'completed';
	}

	/**
	 * Check if in mention-only mode
	 */
	public function isMentionMode(): bool {
		return $this->responseMode === 'mention';
	}

	/**
	 * Check if in always-respond mode
	 */
	public function isAlwaysMode(): bool {
		return $this->responseMode === 'always';
	}

	/**
	 * Get onboarding answers as decoded array
	 *
	 * @return array<int,array{question_id:string,question_text:string,answer_id:string,answer_text:string}>
	 */
	public function getOnboardingAnswersArray(): array {
		if ($this->onboardingAnswers === null || $this->onboardingAnswers === '') {
			return [];
		}
		$decoded = json_decode($this->onboardingAnswers, true);
		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * Set onboarding answers from array
	 *
	 * @param array<int,array{question_id:string,question_text:string,answer_id:string,answer_text:string}> $answers
	 */
	public function setOnboardingAnswersArray(array $answers): void {
		if (count($answers) === 0) {
			$this->onboardingAnswers = null;
		} else {
			$this->onboardingAnswers = json_encode($answers);
		}
		$this->markFieldUpdated('onboardingAnswers');
	}

	/**
	 * Add an answer to the onboarding answers
	 *
	 * @param string $questionId
	 * @param string $questionText
	 * @param string $answerId
	 * @param string $answerText
	 */
	public function addOnboardingAnswer(string $questionId, string $questionText, string $answerId, string $answerText): void {
		$answers = $this->getOnboardingAnswersArray();
		$answers[] = [
			'question_id' => $questionId,
			'question_text' => $questionText,
			'answer_id' => $answerId,
			'answer_text' => $answerText,
		];
		$this->setOnboardingAnswersArray($answers);
	}
}


