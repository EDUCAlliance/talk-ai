<?php

declare(strict_types=1);

namespace OCA\EducAI\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method int getBotId()
 * @method void setBotId(int $botId)
 * @method ?int getToolId()
 * @method void setToolId(?int $toolId)
 * @method ?string getBuiltInToolName()
 * @method void setBuiltInToolName(?string $builtInToolName)
 * @method ?string getConfigOverride()
 * @method void setConfigOverride(?string $configOverride)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class BotTool extends Entity implements JsonSerializable {
    protected int $botId = 0;
    protected ?int $toolId = null;
    protected ?string $builtInToolName = null;
    protected ?string $configOverride = null;
    protected int $createdAt = 0;
    protected int $updatedAt = 0;

    /**
     * Check if this assignment is for a built-in tool
     */
    public function isBuiltIn(): bool {
        return $this->builtInToolName !== null && $this->builtInToolName !== '';
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'bot_id' => $this->botId,
            'tool_id' => $this->toolId,
            'built_in_tool_name' => $this->builtInToolName,
            'is_builtin' => $this->isBuiltIn(),
            'config_override' => $this->configOverride !== null ? json_decode($this->configOverride, true) : null,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
