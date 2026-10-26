<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Migration;

use OCA\EducAI\Migration\RegisterTalkBotRepairStep;
use OCA\EducAI\Migration\RepairTalkWebhookUrl;
use OCA\EducAI\Service\TalkBotRegistrationService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RepairTalkWebhookUrlTest extends TestCase {
	#[DataProvider('repairOutcomes')]
	public function testUsesOnlyBoundedRepairAndReportsItsOutcome(string $status, string $level): void {
		$registration = $this->createMock(TalkBotRegistrationService::class);
		$registration->expects($this->once())->method('repairInvalidWebhookUrl')
			->willReturn(['status' => $status, 'message' => 'Safe repair result.']);
		$registration->expects($this->never())->method('syncRegistration');
		$registration->expects($this->never())->method('unregister');
		$output = new TalkWebhookRepairOutput();

		(new RepairTalkWebhookUrl($registration))->run($output);

		$this->assertSame([[$level, 'Safe repair result.']], $output->messages);
	}

	public static function repairOutcomes(): array {
		return [
			'repaired' => ['repaired', 'info'],
			'already correct' => ['unchanged', 'info'],
			'not eligible' => ['skipped', 'info'],
			'repair failed' => ['error', 'warning'],
		];
	}

	public function testUpgradeRunsBoundedRepairAndKeepsRegistrationInstallOnly(): void {
		$info = simplexml_load_file(__DIR__ . '/../../../appinfo/info.xml');
		$this->assertNotFalse($info);
		$this->assertSame(
			[RepairTalkWebhookUrl::class],
			array_map('strval', $info->xpath('/info/repair-steps/post-migration/step')),
		);
		$this->assertSame(
			[RegisterTalkBotRepairStep::class],
			array_map('strval', $info->xpath('/info/repair-steps/install/step')),
		);
	}
}

/** The unit bootstrap provides an empty IOutput; implement the real contract too. */
class TalkWebhookRepairOutput implements IOutput {
	public array $messages = [];

	public function debug(string $message): void {
		$this->messages[] = ['debug', $message];
	}

	public function info($message) {
		$this->messages[] = ['info', $message];
	}

	public function warning($message) {
		$this->messages[] = ['warning', $message];
	}

	public function startProgress($max = 0) {
	}

	public function advance($step = 1, $description = '') {
	}

	public function finishProgress() {
	}
}
