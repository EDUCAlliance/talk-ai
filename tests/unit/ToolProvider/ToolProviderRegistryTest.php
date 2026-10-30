<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\ToolProvider;

use OCA\EducAI\Service\BuiltInToolProvider;
use OCA\EducAI\ToolProvider\CollectToolProvidersEvent;
use OCA\EducAI\ToolProvider\IToolProvider;
use OCA\EducAI\ToolProvider\ToolProviderRegistry;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ToolProviderRegistryTest extends TestCase {
	/** @param list<IToolProvider> $providers */
	private function registry(array $providers): ToolProviderRegistry {
		$builtIns = $this->createMock(BuiltInToolProvider::class);
		$builtIns->method('getAvailableTools')->willReturn([['name' => 'rag_search_documents']]);
		$builtIns->method('isBuiltInTool')->willReturnCallback(static fn (string $name): bool => $name === 'rag_search_documents');
		$dispatcherBuilder = $this->getMockBuilder(IEventDispatcher::class);
		if (!method_exists(IEventDispatcher::class, 'dispatchTyped')) {
			$dispatcherBuilder->addMethods(['dispatchTyped']);
		}
		$dispatcher = $dispatcherBuilder->getMock();
		$dispatcher->method('dispatchTyped')->willReturnCallback(static function (CollectToolProvidersEvent $event) use ($providers): void {
			foreach ($providers as $provider) {
				$event->registerProvider($provider);
			}
		});
		return new ToolProviderRegistry($builtIns, $dispatcher, $this->createMock(LoggerInterface::class));
	}

	/** @param list<string> $listed */
	private function provider(array $listed, array $claimed, string $answer): IToolProvider {
		$provider = $this->createMock(IToolProvider::class);
		$provider->method('getTools')->willReturn(array_map(static fn (string $name): array => ['name' => $name], $listed));
		$provider->method('providesTool')->willReturnCallback(static fn (string $name): bool => in_array($name, $claimed, true));
		$provider->method('executeTool')->willReturn(['content' => [['type' => 'text', 'text' => $answer]], 'isError' => false]);
		return $provider;
	}

	public function testToolClaimedByTwoProvidersIsNeitherListedNorExecuted(): void {
		$first = $this->provider([], ['shared_tool'], 'first');
		$second = $this->provider(['shared_tool', 'own_tool'], ['shared_tool', 'own_tool'], 'second');
		$first->expects($this->never())->method('executeTool');
		$registry = $this->registry([$first, $second]);

		$this->assertSame(['rag_search_documents', 'own_tool'], array_column($registry->getAvailableTools(), 'name'));
		$result = $registry->executeTool('shared_tool', []);
		$this->assertTrue($result['isError']);
		$this->assertSame('tool_provider_conflict', $result['error']['code']);
		$this->assertSame('second', $registry->executeTool('own_tool', [])['content'][0]['text']);
	}

	public function testProviderCannotShadowCoreTool(): void {
		$provider = $this->provider(['rag_search_documents'], ['rag_search_documents'], 'shadow');
		$provider->expects($this->never())->method('executeTool');
		$registry = $this->registry([$provider]);

		$this->assertSame(['rag_search_documents'], array_column($registry->getAvailableTools(), 'name'));
	}
}
