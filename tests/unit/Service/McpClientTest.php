<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Db\Tool;
use OCA\EducAI\Exception\AgentRunInterruptedException;
use OCA\EducAI\Service\AgentRunControl;
use OCA\EducAI\Service\CredentialService;
use OCA\EducAI\Service\McpClient;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class McpClientTest extends TestCase {
	public function testListToolsWithoutRunControlKeepsAdministrativeTimeout(): void {
		$response = $this->response([
			'result' => ['tools' => []],
		]);
		$httpClient = $this->createMock(IClient::class);
		$httpClient->expects($this->once())
			->method('post')
			->with(
				'https://mcp.example.test',
				$this->callback(function (array $options): bool {
					$this->assertSame(60, $options['timeout']);
					return true;
				})
			)
			->willReturn($response);

		$this->assertSame([], $this->client($httpClient)->listTools($this->tool()));
	}

	public function testListToolsClampsHttpTimeoutToSharedRunDeadline(): void {
		$now = 100.0;
		$runControl = new AgentRunControl(10, null, static function () use (&$now): float {
			return $now;
		});
		$now = 104.0;
		$response = $this->response([
			'result' => ['tools' => [['name' => 'remote_search']]],
		]);
		$httpClient = $this->createMock(IClient::class);
		$httpClient->expects($this->once())
			->method('post')
			->with(
				'https://mcp.example.test',
				$this->callback(function (array $options): bool {
					$this->assertSame(6.0, $options['timeout']);
					$this->assertSame('tools/list', $options['json']['method']);
					return true;
				})
			)
			->willReturn($response);

		$result = $this->client($httpClient)->listTools($this->tool(), [], $runControl);

		$this->assertSame([['name' => 'remote_search']], $result);
	}

	public function testCallToolClampsHttpTimeoutAndPreservesPayload(): void {
		$now = 200.0;
		$runControl = new AgentRunControl(10, null, static function () use (&$now): float {
			return $now;
		});
		$now = 204.25;
		$response = $this->response([
			'result' => ['text' => 'remote result'],
		]);
		$httpClient = $this->createMock(IClient::class);
		$httpClient->expects($this->once())
			->method('post')
			->with(
				'https://mcp.example.test',
				$this->callback(function (array $options): bool {
					$this->assertSame(5.75, $options['timeout']);
					$this->assertSame('tools/call', $options['json']['method']);
					$this->assertSame('remote_search', $options['json']['params']['name']);
					$this->assertEquals((object)['query' => 'Berlin'], $options['json']['params']['arguments']);
					$this->assertEquals((object)['tenant' => 'one'], $options['json']['params']['config']);
					return true;
				})
			)
			->willReturn($response);

		$result = $this->client($httpClient)->callTool(
			$this->tool(),
			'remote_search',
			['query' => 'Berlin'],
			['tenant' => 'one'],
			$runControl
		);

		$this->assertSame(['text' => 'remote result'], $result);
	}

	public function testAbortBeforeListToolsPropagatesTypedInterruptionWithoutRequest(): void {
		$clientService = $this->createMock(IClientService::class);
		$clientService->expects($this->never())->method('newClient');
		$mcpClient = $this->clientFromService($clientService);
		$runControl = new AgentRunControl(10, static fn (): bool => true);

		try {
			$mcpClient->listTools($this->tool(), [], $runControl);
			$this->fail('An aborted run must not perform MCP discovery.');
		} catch (AgentRunInterruptedException $e) {
			$this->assertSame(AgentRunInterruptedException::REASON_ABORTED, $e->getReason());
		}
	}

	public function testExpiredDeadlineBeforeCallToolPropagatesTypedInterruptionWithoutRequest(): void {
		$now = 300.0;
		$runControl = new AgentRunControl(1, null, static function () use (&$now): float {
			return $now;
		});
		$now = 301.0;
		$clientService = $this->createMock(IClientService::class);
		$clientService->expects($this->never())->method('newClient');
		$mcpClient = $this->clientFromService($clientService);

		try {
			$mcpClient->callTool($this->tool(), 'remote_search', [], [], $runControl);
			$this->fail('An expired run must not perform an MCP tool request.');
		} catch (AgentRunInterruptedException $e) {
			$this->assertSame(AgentRunInterruptedException::REASON_WALL_CLOCK, $e->getReason());
		}
	}

	private function client(IClient $httpClient): McpClient {
		$clientService = $this->createMock(IClientService::class);
		$clientService->expects($this->once())
			->method('newClient')
			->willReturn($httpClient);
		return $this->clientFromService($clientService);
	}

	private function clientFromService(IClientService $clientService): McpClient {
		return new McpClient(
			$clientService,
			$this->createMock(CredentialService::class),
			$this->createMock(LoggerInterface::class)
		);
	}

	private function tool(): Tool {
		$tool = new Tool();
		$tool->setId(1);
		$tool->setName('Remote');
		$tool->setMcpEndpointUrl('https://mcp.example.test');
		$tool->setEnabled(true);
		return $tool;
	}

	/** @param array<string,mixed> $payload */
	private function response(array $payload): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn((string)json_encode($payload, JSON_THROW_ON_ERROR));
		return $response;
	}
}
