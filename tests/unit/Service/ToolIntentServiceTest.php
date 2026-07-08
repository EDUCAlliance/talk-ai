<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Db\Bot;
use OCA\EducAI\Db\Tool;
use OCA\EducAI\Service\ToolIntentService;
use PHPUnit\Framework\TestCase;

class ToolIntentServiceTest extends TestCase {
	public function testExistingForceToolKeywordsStillForceToolUse(): void {
		$service = new ToolIntentService();
		$bot = $this->bot('@assistant');

		$this->assertTrue($service->shouldForceToolCall($bot, 'Bitte nutze das tool', null, []));
		$this->assertTrue($service->shouldForceToolCall($bot, 'Bitte suche im internet', null, []));
	}

	public function testSearchMentionAddsGenericSearchKeywords(): void {
		$service = new ToolIntentService();

		$this->assertTrue($service->shouldForceToolCall($this->bot('@webbot'), 'Was ist der Stand?', null, []));
		$this->assertFalse($service->shouldForceToolCall($this->bot('@assistant'), 'Was ist der Stand?', null, []));
	}

	public function testSearchToolDescriptionAddsGenericSearchKeywords(): void {
		$service = new ToolIntentService();
		$tool = new Tool();
		$tool->setName('Knowledge helper');
		$tool->setDescription('Performs internet search for a query');

		$this->assertTrue($service->shouldForceToolCall($this->bot('@assistant'), 'heute bitte', null, [
			['tool' => $tool, 'config' => []],
		]));
	}

	public function testWikiWritePromptDoesNotChangeCurrentForceToolBehavior(): void {
		$service = new ToolIntentService();

		$this->assertFalse($service->shouldForceToolCall(
			$this->bot('@assistant'),
			'Please write this into the wiki.',
			null,
			[]
		));
	}

	private function bot(string $mention): Bot {
		$bot = new Bot();
		$bot->setMentionName($mention);
		return $bot;
	}
}
