<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Db\Settings;
use OCA\EducAI\Service\AgentTurn;
use OCA\EducAI\Service\LLMClient;
use OCA\EducAI\Service\ProviderResponseNormalizer;
use OCA\EducAI\Service\SettingsService;
use OCA\EducAI\Service\ToolCallIdGenerator;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProviderResponseNormalizerTest extends TestCase {
	#[DataProvider('truncatedEnvelopes')]
	public function testTruncatedLegacyEnvelopeIsNotReleasedBySyncOrStreamingClient(string $content, ?string $mode, string $source): void {
		foreach ([false, true] as $streaming) {
			$llm = $this->client($content, 'length', $streaming);
			$options = $mode === null ? [] : ['legacy_tool_call_compatibility' => $mode];
			$chunks = [];
			$turn = $streaming
				? $llm->streamAgentTurn('system', [], ['wiki_write_page'], static function (array $chunk) use (&$chunks): void {
					$chunks[] = $chunk;
				}, 'primary:minimax-m2', $options)
				: $llm->sendAgentTurn('system', [], ['wiki_write_page'], 'primary:minimax-m2', $options);

			$this->assertSame('length', $turn->getStopReason());
			$this->assertSame('', $turn->getText());
			$this->assertSame([], $turn->getToolCalls(), 'No repaired or invented tool call');
			$this->assertSame($source, $turn->getCompatibilitySource());
			$this->assertSame([], $chunks, 'Truncated arguments must not escape the streaming quarantine');
		}
	}

	public static function truncatedEnvelopes(): array {
		return [
			'implicit MiniMax XML profile' => [
				'<minimax:tool_call><invoke name="wiki_write_page"><parameter name="content">unreleased-argument',
				null, AgentTurn::COMPATIBILITY_LEGACY_XML,
			],
			'XML name and arguments' => [
				" \n<function_call><name>wiki_write_page</name><arguments>{\"content\":\"unreleased-argument",
				'xml', AgentTurn::COMPATIBILITY_LEGACY_XML,
			],
			'XML argument pairs' => [
				'<tool_call>wiki_write_page<arg_key>content</arg_key><arg_value>unreleased-argument',
				'json_xml', AgentTurn::COMPATIBILITY_LEGACY_XML,
			],
			'XML wrapper with JSON' => [
				'<tool_call>{"name":"wiki_write_page","arguments":{"content":"unreleased-argument',
				'xml', AgentTurn::COMPATIBILITY_LEGACY_XML,
			],
			'JSON name and arguments' => [
				'{"name":"wiki_write_page","arguments":{"content":"unreleased-argument',
				'json', AgentTurn::COMPATIBILITY_LEGACY_JSON,
			],
			'JSON tool and parameters' => [
				'{"tool":"wiki_write_page","parameters":{"content":"unreleased-argument',
				'json_xml', AgentTurn::COMPATIBILITY_LEGACY_JSON,
			],
			'JSON nested function with metadata' => [
				'{"id":"call-1","type":"function","function":{"name":"wiki_write_page","arguments":"unreleased-argument',
				'json', AgentTurn::COMPATIBILITY_LEGACY_JSON,
			],
			'JSON array' => [
				'[{"name":"wiki_write_page","arguments":{"content":"unreleased-argument',
				'json', AgentTurn::COMPATIBILITY_LEGACY_JSON,
			],
			'JSON escaped tool name' => [
				'{"name":"wiki_\u0077rite_page","arguments":{"content":"unreleased-argument',
				'json', AgentTurn::COMPATIBILITY_LEGACY_JSON,
			],
			'JSON id between name and arguments' => [
				'{"name":"wiki_write_page","id":"call-1","arguments":{"content":"unreleased-argument',
				'json', AgentTurn::COMPATIBILITY_LEGACY_JSON,
			],
			'JSON type between name and arguments' => [
				'{"name":"wiki_write_page","type":"function","arguments":{"content":"unreleased-argument',
				'json', AgentTurn::COMPATIBILITY_LEGACY_JSON,
			],
			'JSON metadata around tool name' => [
				'{"type":"function","tool":"wiki_write_page","id":"call-1","parameters":{"content":"unreleased-argument',
				'json', AgentTurn::COMPATIBILITY_LEGACY_JSON,
			],
			'JSON arguments before name' => [
				'{"arguments":{"content":"unreleased-argument"},"name":"wiki_write_page"',
				'json', AgentTurn::COMPATIBILITY_LEGACY_JSON,
			],
			'JSON parameters before tool with metadata' => [
				'{"parameters":{"content":"unreleased-argument"},"tool":"wiki_write_page","id":"call-1"',
				'json', AgentTurn::COMPATIBILITY_LEGACY_JSON,
			],
			'JSON array with arguments before name' => [
				'[{"id":"call-1","arguments":{"content":"unreleased-argument"},"name":"wiki_write_page"}',
				'json', AgentTurn::COMPATIBILITY_LEGACY_JSON,
			],
			'JSON nested arguments before name' => [
				'{"function":{"arguments":{"content":"unreleased-argument"},"name":"wiki_write_page"},"id":"call-1"',
				'json', AgentTurn::COMPATIBILITY_LEGACY_JSON,
			],
			'JSON array with nested arguments before name' => [
				'[{"type":"function","function":{"arguments":"{\\"content\\":\\"unreleased-argument\\"}","name":"wiki_write_page"',
				'json', AgentTurn::COMPATIBILITY_LEGACY_JSON,
			],
			'JSON arguments before a truncated name' => [
				'{"arguments":{"content":"unreleased-argument"},"name":"wiki_write_',
				'json', AgentTurn::COMPATIBILITY_LEGACY_JSON,
			],
			'JSON arguments first with truncated id after name' => [
				'{"arguments":{"content":"unreleased-argument"},"name":"wiki_write_page","id":"call-',
				'json', AgentTurn::COMPATIBILITY_LEGACY_JSON,
			],
		];
	}

	#[DataProvider('unchangedText')]
	public function testOnlyTruncatedEnabledToolEnvelopesAreSuppressed(string $content, string $mode, string $finishReason): void {
		$normalizer = new ProviderResponseNormalizer(new ToolCallIdGenerator());
		$turn = $normalizer->normalize(['content' => $content, 'finish_reason' => $finishReason], ['wiki_write_page'], $mode);

		$this->assertSame($content, $turn->getText());
		$this->assertSame([], $turn->getToolCalls());
		$this->assertSame($finishReason, $turn->getStopReason());
		$this->assertSame(AgentTurn::COMPATIBILITY_NATIVE, $turn->getCompatibilitySource());
	}

	public static function unchangedText(): array {
		return [
			'ordinary prose' => ['The document was saved, but the remaining explanation', 'json_xml', 'length'],
			'inline XML explanation' => ['Use <tool_call> before the tool name', 'xml', 'length'],
			'quoted XML example' => ['"<tool_call><name>wiki_write_page</name>', 'xml', 'length'],
			'fenced XML example' => ["```xml\n<tool_call><name>wiki_write_page</name>", 'xml', 'length'],
			'fenced JSON example' => ["```json\n{\"name\":\"wiki_write_page\",\"arguments\":{", 'json', 'length'],
			'ordinary JSON' => ['{"name":"Alice","age":', 'json', 'length'],
			'JSON with envelope nested in data' => ['{"example":{"name":"wiki_write_page","arguments":{', 'json', 'length'],
			'complete unsupported JSON shape' => ['{"name":"Alice","arguments":{},"description":"data"}', 'json', 'length'],
			'complete malformed XML' => ['<tool_call><unsupported>data</tool_call>', 'xml', 'length'],
			'XML non-length termination' => ['<tool_call><name>wiki_write_page</name>', 'xml', 'stop'],
			'JSON non-length termination' => ['{"name":"wiki_write_page","arguments":{', 'json', 'stop'],
			'XML disabled' => ['<tool_call><name>wiki_write_page</name>', 'off', 'length'],
			'JSON disabled' => ['{"name":"wiki_write_page","arguments":{', 'off', 'length'],
			'XML wrong compatibility profile' => ['<tool_call><name>wiki_write_page</name>', 'json', 'length'],
			'JSON wrong compatibility profile' => ['{"name":"wiki_write_page","arguments":{', 'xml', 'length'],
			'arguments with a nested name only' => ['{"arguments":{"name":"wiki_write_page","content":"unreleased', 'json', 'length'],
			'JSON data with arguments before name' => ['{"arguments":{"content":"data"},"name":"Alice","age":', 'json', 'length'],
			'JSON incomplete example containing reversed call' => ['{"example":{"arguments":{"content":"data"},"name":"wiki_write_page"', 'json', 'length'],
			'fenced reverse JSON example' => ["```json\n{\"arguments\":{},\"name\":\"wiki_write_page\"", 'json', 'length'],
			'JSON name alone' => ['{"name":"Alice"', 'json', 'length'],
			'JSON reverse shape disabled' => ['{"arguments":{},"name":"wiki_write_page"', 'off', 'length'],
			'JSON reverse non-length termination' => ['{"arguments":{},"name":"wiki_write_page"', 'json', 'stop'],
			'JSON name-only array' => ['[{"name":"Alice"', 'json', 'length'],
			'JSON reverse extra field before name' => ['{"arguments":{},"description":"data","name":"wiki_write_page"', 'json', 'length'],
			'JSON reverse unsupported type' => ['{"arguments":{},"type":"person","name":"Alice"', 'json', 'length'],
		];
	}

	#[DataProvider('completeEnvelopes')]
	public function testCompleteLegacyCallAtLengthRetainsTerminalReasonAndToolIdentity(string $content, string $mode): void {
		foreach ([false, true] as $streaming) {
			$llm = $this->client($content, 'length', $streaming);
			$options = ['legacy_tool_call_compatibility' => $mode];
			$chunks = [];
			$turn = $streaming
				? $llm->streamAgentTurn('system', [], ['wiki_write_page'], static function (array $chunk) use (&$chunks): void {
					$chunks[] = $chunk;
				}, 'primary:minimax-m2', $options)
				: $llm->sendAgentTurn('system', [], ['wiki_write_page'], 'primary:minimax-m2', $options);

			$this->assertSame('length', $turn->getStopReason());
			$this->assertSame('', $turn->getText());
			$this->assertCount(1, $turn->getToolCalls());
			$this->assertSame('wiki_write_page', $turn->getToolCalls()[0]['function']['name']);
			$this->assertSame([], $chunks);
		}
	}

	public static function completeEnvelopes(): array {
		return [
			'complete XML' => ['<tool_call><name>wiki_write_page</name><arguments>{"content":"value"}</arguments></tool_call>', 'xml'],
			'complete JSON' => ['{"name":"wiki_write_page","arguments":{"content":"value"}}', 'json'],
			'complete JSON metadata between name and arguments' => ['{"name":"wiki_write_page","id":"call-1","type":"function","arguments":{"content":"value"}}', 'json'],
			'complete JSON arguments before name' => ['{"arguments":{"content":"value"},"name":"wiki_write_page"}', 'json'],
			'complete nested JSON arguments before name' => ['{"function":{"arguments":{"content":"value"},"name":"wiki_write_page"},"id":"call-1"}', 'json'],
		];
	}

	private function client(string $content, string $finishReason, bool $streaming): LLMClient {
		$settings = new Settings();
		$settings->setApiProvider('custom');
		$settings->setApiEndpoint('https://fixture.example.invalid/v1/chat/completions');
		$settings->setDefaultModel('primary:minimax-m2');
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getSettings')->willReturn($settings);
		$settingsService->method('getApiKey')->willReturn('fixture-key');
		$settingsService->method('getMaxOutputTokens')->willReturn(32768);
		$settingsService->method('normalizePositiveInteger')->willReturnCallback(
			static fn (?int $value, int $fallback): int => $value !== null && $value > 0 ? $value : $fallback
		);

		if ($streaming) {
			$body = '';
			foreach (str_split($content, 17) as $chunk) {
				$body .= 'data: ' . json_encode(['choices' => [['delta' => ['content' => $chunk], 'finish_reason' => null]]]) . "\n\n";
			}
			$body .= 'data: ' . json_encode(['choices' => [['delta' => [], 'finish_reason' => $finishReason]]]) . "\n\ndata: [DONE]\n\n";
		} else {
			$body = json_encode(['choices' => [['message' => ['content' => $content], 'finish_reason' => $finishReason]]], JSON_THROW_ON_ERROR);
		}
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn($body);
		$response->method('getHeader')->willReturn('');
		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('get');
		$client->expects($this->once())->method('post')->willReturn($response);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		return new LLMClient($clientService, $settingsService, $this->createMock(LoggerInterface::class));
	}
}
