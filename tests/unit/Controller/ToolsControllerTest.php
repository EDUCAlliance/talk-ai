<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Controller;

use OCA\EducAI\Controller\ToolsController;
use OCA\EducAI\Db\ToolMapper;
use OCA\EducAI\Service\BuiltInToolProvider;
use OCA\EducAI\Service\BuiltInToolUiService;
use OCA\EducAI\Service\CredentialService;
use OCA\EducAI\Service\DoclingClient;
use OCA\EducAI\Service\McpClient;
use OCA\EducAI\Service\SpeechToTextClient;
use OCA\EducAI\Service\ToolRegistry;
use OCA\EducAI\Service\VisionClient;
use OCA\EducAI\Service\WikiLocationService;
use OCA\EducAI\ToolProvider\ToolProviderRegistry;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ToolsControllerTest extends TestCase {
	public function testAvailableLocalizesOnlyBuiltInUiMetadata(): void {
		$toolRegistry = $this->createMock(ToolRegistry::class);
		$toolRegistry->method('getEnabledTools')->willReturn([]);
		$providerRegistry = $this->createMock(ToolProviderRegistry::class);
		$providerRegistry->method('getAvailableTools')->willReturn([[
			'name' => BuiltInToolProvider::TOOL_RAG_SEARCH,
			'label' => 'Provider label',
			'description' => 'Model-facing schema description',
		]]);
		$l10n = $this->createL10n();
		$controller = $this->createController($toolRegistry, $providerRegistry, $l10n);

		$response = $controller->available();
		$tool = $response->getData()['tools'][0];

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(BuiltInToolProvider::TOOL_RAG_SEARCH, $tool['builtin_name']);
		$this->assertSame('translated:Document Search (RAG)', $tool['name']);
		$this->assertSame('translated:Search through indexed documents attached to this bot.', $tool['description']);
	}

	public function testAvailableDoesNotExposeRegistryFailure(): void {
		$toolRegistry = $this->createMock(ToolRegistry::class);
		$toolRegistry->method('getEnabledTools')->willThrowException(new \RuntimeException('provider credential secret'));
		$providerRegistry = $this->createMock(ToolProviderRegistry::class);
		$controller = $this->createController($toolRegistry, $providerRegistry, $this->createL10n());

		$response = $controller->available();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame('tools_load_failed', $response->getData()['errorCode']);
		$this->assertSame('translated:Failed to load available tools', $response->getData()['error']);
		$this->assertStringNotContainsString('secret', $response->getData()['error']);
	}

	private function createController(
		ToolRegistry $toolRegistry,
		ToolProviderRegistry $providerRegistry,
		IL10N $l10n,
	): ToolsController {
		return new ToolsController(
			'educai',
			$this->createMock(IRequest::class),
			$this->createMock(ToolMapper::class),
			$toolRegistry,
			$this->createMock(McpClient::class),
			$this->createMock(DoclingClient::class),
			$this->createMock(VisionClient::class),
			$this->createMock(SpeechToTextClient::class),
			$providerRegistry,
			$this->createMock(CredentialService::class),
			$this->createMock(WikiLocationService::class),
			'alice',
			$this->createMock(LoggerInterface::class),
			$l10n,
			new BuiltInToolUiService($l10n),
		);
	}

	private function createL10n(): IL10N {
		$l10nBuilder = $this->getMockBuilder(IL10N::class);
		if (!method_exists(IL10N::class, 't')) {
			$l10nBuilder->addMethods(['t']);
		}
		$l10n = $l10nBuilder->getMock();
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'translated:' . $text);

		return $l10n;
	}
}
