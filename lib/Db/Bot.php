<?php

declare(strict_types=1);

namespace OCA\EducAI\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @psalm-import-type OnboardingQuestion from \OCA\EducAI\TypeDefinitions
 * @psalm-import-type OnboardingQuestionTree from \OCA\EducAI\TypeDefinitions
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getBotName()
 * @method void setBotName(string $botName)
 * @method string getMentionName()
 * @method void setMentionName(string $mentionName)
 * @method string getSystemPrompt()
 * @method void setSystemPrompt(string $systemPrompt)
 * @method ?string getModel()
 * @method void setModel(?string $model)
 * @method ?float getTemperature()
 * @method void setTemperature(?float $temperature)
 * @method bool getIsActive()
 * @method void setIsActive(bool $isActive)
 * @method bool getIsPublic()
 * @method void setIsPublic(bool $isPublic)
 * @method ?string getVisibility()
 * @method void setVisibility(?string $visibility)
 * @method ?string getAllowedGroups()
 * @method void setAllowedGroups(?string $allowedGroups)
 * @method ?string getAllowedTeams()
 * @method void setAllowedTeams(?string $allowedTeams)
 * @method bool getRagEnabled()
 * @method void setRagEnabled(bool $ragEnabled)
 * @method ?string getDescription()
 * @method void setDescription(?string $description)
 * @method ?string getApprovalReason()
 * @method void setApprovalReason(?string $approvalReason)
 * @method ?string getBotCapabilities()
 * @method void setBotCapabilities(?string $botCapabilities)
 * @method ?string getRagSourceDescription()
 * @method void setRagSourceDescription(?string $ragSourceDescription)
 * @method ?string getTestingDescription()
 * @method void setTestingDescription(?string $testingDescription)
 * @method ?string getRejectionReason()
 * @method void setRejectionReason(?string $rejectionReason)
 * @method ?string getTestingEnabledBy()
 * @method void setTestingEnabledBy(?string $testingEnabledBy)
 * @method ?string getApprovalStatus()
 * @method void setApprovalStatus(?string $approvalStatus)
 * @method ?int getSubmittedAt()
 * @method void setSubmittedAt(?int $submittedAt)
 * @method ?string getApprovedBy()
 * @method void setApprovedBy(?string $approvedBy)
 * @method ?int getApprovedAt()
 * @method void setApprovedAt(?int $approvedAt)
 * @method ?string getPendingChanges()
 * @method void setPendingChanges(?string $pendingChanges)
 * @method ?string getOnboardingQuestions()
 * @method void setOnboardingQuestions(?string $onboardingQuestions)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class Bot extends Entity implements JsonSerializable {
	protected string $userId = '';
	protected string $botName = '';
	protected string $mentionName = '';
	protected string $systemPrompt = '';
	protected ?string $model = null;
	protected ?float $temperature = null;
	protected bool $isActive = true;
	protected bool $isPublic = false;
	protected ?string $visibility = 'groups';
	protected ?string $allowedGroups = null;
	protected ?string $allowedTeams = null;
	protected bool $ragEnabled = false;
	protected ?string $description = null;
	protected ?string $approvalReason = null;
	protected ?string $botCapabilities = null;
	protected ?string $ragSourceDescription = null;
	protected ?string $testingDescription = null;
	protected ?string $rejectionReason = null;
	protected ?string $testingEnabledBy = null;
	protected ?string $approvalStatus = 'approved';
	protected ?int $submittedAt = null;
	protected ?string $approvedBy = null;
	protected ?int $approvedAt = null;
	protected ?string $pendingChanges = null;
	protected ?string $onboardingQuestions = null;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;

	public function __construct() {
		// Declare types for proper database serialization
		$this->addType('id', 'integer');
		$this->addType('temperature', 'float');
		$this->addType('isActive', 'bool');
		$this->addType('isPublic', 'bool');
		$this->addType('ragEnabled', 'bool');
		$this->addType('submittedAt', 'integer');
		$this->addType('approvedAt', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'user_id' => $this->userId,
			'bot_name' => $this->botName,
			'mention_name' => $this->mentionName,
			'system_prompt' => $this->systemPrompt,
			'model' => $this->model,
			'temperature' => $this->temperature,
			'is_active' => $this->isActive,
			'is_public' => $this->isPublic,
			'visibility' => $this->visibility,
			'allowed_groups' => $this->normalizeAllowedGroups(),
			'allowed_teams' => $this->normalizeAllowedTeams(),
			'rag_enabled' => (bool)$this->ragEnabled,
			'description' => $this->description,
			'approval_reason' => $this->approvalReason,
			'bot_capabilities' => $this->botCapabilities,
			'rag_source_description' => $this->ragSourceDescription,
			'testing_description' => $this->testingDescription,
			'rejection_reason' => $this->rejectionReason,
			'testing_enabled_by' => $this->testingEnabledBy,
			'approval_status' => $this->approvalStatus ?? 'approved',
			'submitted_at' => $this->submittedAt,
			'approved_by' => $this->approvedBy,
			'approved_at' => $this->approvedAt,
			'pending_changes' => $this->getPendingChangesArray(),
			'has_pending_changes' => $this->hasPendingChanges(),
			'onboarding_questions' => $this->getOnboardingQuestionsArray(),
			'has_onboarding_questions' => $this->hasOnboardingQuestions(),
			'created_at' => $this->createdAt,
			'updated_at' => $this->updatedAt,
		];
	}

	/**
	 * Get pending changes as decoded array
	 * @return array<string,mixed>|null
	 */
	public function getPendingChangesArray(): ?array {
		if ($this->pendingChanges === null || $this->pendingChanges === '') {
			return null;
		}
		$decoded = json_decode($this->pendingChanges, true);
		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * Set pending changes from array
	 * @param array<string,mixed>|null $changes
	 */
	public function setPendingChangesArray(?array $changes): void {
		if ($changes === null || count($changes) === 0) {
			$this->pendingChanges = null;
		} else {
			$this->pendingChanges = json_encode($changes);
		}
		$this->markFieldUpdated('pendingChanges');
	}

	/**
	 * Check if bot has pending changes awaiting approval
	 */
	public function hasPendingChanges(): bool {
		return $this->pendingChanges !== null && $this->pendingChanges !== '';
	}

	/**
	 * Clear pending changes (on approve or reject)
	 */
	public function clearPendingChanges(): void {
		$this->pendingChanges = null;
		$this->markFieldUpdated('pendingChanges');
	}

	/**
	 * Apply pending changes to actual fields (called on approval)
	 */
	public function applyPendingChanges(): void {
		$changes = $this->getPendingChangesArray();
		if ($changes === null) {
			return;
		}

		if (isset($changes['bot_name'])) {
			$this->setBotName($changes['bot_name']);
		}
		if (isset($changes['system_prompt'])) {
			$this->setSystemPrompt($changes['system_prompt']);
		}
		if (isset($changes['description'])) {
			$this->setDescription($changes['description']);
		}
		if (isset($changes['model'])) {
			$this->setModel($changes['model']);
		}
		if (array_key_exists('temperature', $changes)) {
			$this->setTemperature(is_numeric($changes['temperature']) ? (float)$changes['temperature'] : null);
		}
		if (isset($changes['visibility'])) {
			$this->setVisibility($changes['visibility']);
		}
		if (isset($changes['is_public'])) {
			$this->setIsPublic($changes['is_public']);
		}
		if (isset($changes['allowed_groups'])) {
			$this->setAllowedGroups($changes['allowed_groups']);
		}
		if (isset($changes['allowed_teams'])) {
			$this->setAllowedTeams($changes['allowed_teams']);
		}
		if (isset($changes['rag_enabled'])) {
			$this->setRagEnabled($changes['rag_enabled']);
		}
		if (array_key_exists('onboarding_questions', $changes)) {
			$this->setOnboardingQuestionsArray($changes['onboarding_questions']);
		}

		$this->clearPendingChanges();
	}

	/**
	 * @return array<int,string>
	 */
	private function normalizeAllowedGroups(): array {
		$raw = $this->allowedGroups;
		if ($raw === null || $raw === '') {
			return [];
		}
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
	}

	/**
	 * @return array<int,string>
	 */
	private function normalizeAllowedTeams(): array {
		$raw = $this->allowedTeams;
		if ($raw === null || $raw === '') {
			return [];
		}
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
	}

	/**
	 * Get onboarding questions as decoded array
	 *
	 * Structure: {
	 *   "start": "q1",
	 *   "questions": [
	 *     {"id": "q1", "text": "Question?", "answers": [
	 *       {"id": "a", "text": "Answer A", "next": "q2a"},
	 *       {"id": "b", "text": "Answer B", "next": null}
	 *     ]}
	 *   ]
	 * }
	 *
	 * @return OnboardingQuestionTree|null
	 */
	public function getOnboardingQuestionsArray(): ?array {
		if ($this->onboardingQuestions === null || $this->onboardingQuestions === '') {
			return null;
		}
		$decoded = json_decode($this->onboardingQuestions, true);
		if (!is_array($decoded)) {
			return null;
		}
		// Validate structure
		if (!isset($decoded['start']) || !isset($decoded['questions']) || !is_array($decoded['questions'])) {
			return null;
		}
		return $decoded;
	}

	/**
	 * Set onboarding questions from array
	 *
	 * @param OnboardingQuestionTree|null $questions
	 */
	public function setOnboardingQuestionsArray(?array $questions): void {
		if ($questions === null || !isset($questions['questions']) || count($questions['questions']) === 0) {
			$this->onboardingQuestions = null;
		} else {
			$this->onboardingQuestions = json_encode($questions);
		}
		$this->markFieldUpdated('onboardingQuestions');
	}

	/**
	 * Check if bot has onboarding questions configured
	 */
	public function hasOnboardingQuestions(): bool {
		$questions = $this->getOnboardingQuestionsArray();
		return $questions !== null && count($questions['questions'] ?? []) > 0;
	}

	/**
	 * Get a specific question by ID from the question tree
	 *
	 * @param string $questionId
	 * @return OnboardingQuestion|null
	 */
	public function getOnboardingQuestion(string $questionId): ?array {
		$questions = $this->getOnboardingQuestionsArray();
		if ($questions === null) {
			return null;
		}
		foreach ($questions['questions'] as $question) {
			if ($question['id'] === $questionId) {
				return $question;
			}
		}
		return null;
	}

	/**
	 * Get the starting question for onboarding
	 *
	 * @return OnboardingQuestion|null
	 */
	public function getStartingOnboardingQuestion(): ?array {
		$questions = $this->getOnboardingQuestionsArray();
		if ($questions === null) {
			return null;
		}
		return $this->getOnboardingQuestion($questions['start']);
	}
}
