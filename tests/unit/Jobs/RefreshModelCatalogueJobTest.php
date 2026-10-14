<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Jobs;

use OCA\EducAI\Jobs\RefreshModelCatalogueJob;
use OCA\EducAI\Service\LLMClient;
use OCA\EducAI\Service\ModelReferenceMigrationService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RefreshModelCatalogueJobTest extends TestCase {
	public function testCronRefreshRespectsCacheAndMigratesWithoutInference(): void {
		$llm = $this->createMock(LLMClient::class);
		$llm->expects($this->once())->method('getModelCatalogue')->with(false)->willReturn(['models' => []]);
		$llm->expects($this->never())->method('testModelConnection');
		$migration = $this->createMock(ModelReferenceMigrationService::class);
		$migration->expects($this->once())->method('migrate');
		$job = new RefreshModelCatalogueJob($this->createMock(ITimeFactory::class), $llm, $migration, $this->createMock(LoggerInterface::class));
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}

	public function testFailedDiscoveryDoesNotBreakNextcloudCronOrExposeProviderDetails(): void {
		$llm = $this->createMock(LLMClient::class);
		$llm->method('getModelCatalogue')->willThrowException(new \RuntimeException('private provider payload'));
		$migration = $this->createMock(ModelReferenceMigrationService::class);
		$migration->expects($this->never())->method('migrate');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('Automatic model catalogue refresh failed');
		$job = new RefreshModelCatalogueJob($this->createMock(ITimeFactory::class), $llm, $migration, $logger);
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}
}
