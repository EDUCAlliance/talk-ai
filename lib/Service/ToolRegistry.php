<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use OCA\EducAI\Db\BotTool;
use OCA\EducAI\Db\BotToolMapper;
use OCA\EducAI\Db\Tool;
use OCA\EducAI\Db\ToolMapper;

/**
 * @psalm-import-type BuiltInToolLoadoutEntry from \OCA\EducAI\TypeDefinitions
 * @psalm-import-type McpToolLoadoutEntry from \OCA\EducAI\TypeDefinitions
 */
class ToolRegistry {
    private ToolMapper $toolMapper;
    private BotToolMapper $botToolMapper;
    /**
     * @var array<int,Tool>|null
     */
    private ?array $enabledCache = null;

    public function __construct(ToolMapper $toolMapper, BotToolMapper $botToolMapper) {
        $this->toolMapper = $toolMapper;
        $this->botToolMapper = $botToolMapper;
    }

    /**
     * @return array<int,Tool>
     */
    public function getEnabledTools(): array {
        if ($this->enabledCache === null) {
            $this->enabledCache = $this->toolMapper->findAllEnabled();
        }
        return $this->enabledCache;
    }

    public function refresh(): void {
        $this->enabledCache = null;
    }

    /**
     * Get MCP tools assigned to a bot
     * 
     * @return array<int,McpToolLoadoutEntry>
     */
    public function getToolsForBot(int $botId): array {
        $botTools = $this->botToolMapper->findByBot($botId);
        $lookup = [];
        foreach ($this->getEnabledTools() as $tool) {
            $lookup[$tool->getId()] = $tool;
        }

        $result = [];
        foreach ($botTools as $botTool) {
            // Skip built-in tools here - they are handled separately
            if ($botTool->isBuiltIn()) {
                continue;
            }
            
            $toolId = $botTool->getToolId();
            if ($toolId === null) {
                continue;
            }
            
            $tool = $lookup[$toolId] ?? null;
            if ($tool === null) {
                continue;
            }
            $result[] = [
                'tool' => $tool,
                'config' => $this->decodeConfig($botTool),
            ];
        }

        return $result;
    }

    /**
     * Get built-in tool names assigned to a bot
     * 
     * @return array<int,BuiltInToolLoadoutEntry>
     */
    public function getBuiltInToolsForBot(int $botId): array {
        $botTools = $this->botToolMapper->findByBot($botId);
        
        $result = [];
        foreach ($botTools as $botTool) {
            if (!$botTool->isBuiltIn()) {
                continue;
            }
            
            $builtInName = $botTool->getBuiltInToolName();
            if ($builtInName === null || $builtInName === '') {
                continue;
            }
            
            $result[] = [
                'name' => $builtInName,
                'config' => $this->decodeConfig($botTool),
            ];
        }

        return $result;
    }

    /**
     * Get all tool assignments for a bot (both MCP and built-in)
     * 
     * @return array<int,BotTool>
     */
    public function getAllAssignmentsForBot(int $botId): array {
        return $this->botToolMapper->findByBot($botId);
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeConfig(BotTool $botTool): array {
        $raw = $botTool->getConfigOverride();
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
