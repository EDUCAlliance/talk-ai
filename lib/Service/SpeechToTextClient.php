<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use Exception;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

class SpeechToTextClient {
	private IClientService $clientService;
	private SettingsService $settingsService;
	private LoggerInterface $logger;

	public function __construct(
		IClientService $clientService,
		SettingsService $settingsService,
		LoggerInterface $logger
	) {
		$this->clientService = $clientService;
		$this->settingsService = $settingsService;
		$this->logger = $logger;
	}

	public function isEnabled(): bool {
		return (bool)$this->settingsService->getSpeechConfig()['enabled'];
	}

	/**
	 * @throws Exception
	 */
	public function transcribeAudio(string $tempPath, string $displayName): string {
		$config = $this->settingsService->getSpeechConfig();
		$model = $config['model'] ?? null;
		if ($model === null || $model === '') {
			throw new Exception('Speech model not configured');
		}

		$apiKey = $config['api_key'] ?: $this->settingsService->getApiKey();
		if ($apiKey === null || $apiKey === '') {
			throw new Exception('Speech API key not configured');
		}

		$endpoint = $this->resolveTranscriptionEndpoint($config['endpoint'] ?? null);
		if ($endpoint === null) {
			throw new Exception('Speech endpoint not configured');
		}

		$client = $this->clientService->newClient();
		$boundary = 'educai_boundary_' . bin2hex(random_bytes(8));
		$mimeType = mime_content_type($tempPath) ?: 'audio/wav';
		$content = file_get_contents($tempPath);
		if (!is_string($content)) {
			throw new Exception('Unable to read temporary audio content');
		}

		$response = $client->post($endpoint, [
			'headers' => [
				'Authorization' => 'Bearer ' . $apiKey,
				'Accept' => 'application/json',
				'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
			],
			'body' => $this->buildMultipartBody($boundary, $displayName, $content, $mimeType, $model),
			'timeout' => 180,
		]);

		$payload = json_decode((string)$response->getBody(), true);
		$text = $payload['text'] ?? $payload['transcript'] ?? null;
		if (!is_string($text)) {
			throw new Exception('Speech API returned no transcript');
		}

		return trim($text);
	}

	/**
	 * @return array{success:bool,error?:string}
	 */
	public function testConnection(?string $endpoint = null, ?string $apiKey = null, ?string $model = null): array {
		$config = $this->settingsService->getSpeechConfig();
		$resolvedEndpoint = $this->resolveTranscriptionEndpoint($endpoint ?? $config['endpoint'] ?? null);
		$resolvedKey = $apiKey ?? $config['api_key'] ?? $this->settingsService->getApiKey();
		$resolvedModel = $model ?? $config['model'] ?? null;

		if ($resolvedEndpoint === null || $resolvedKey === null || $resolvedKey === '' || $resolvedModel === null || $resolvedModel === '') {
			return ['success' => false, 'error' => 'Speech endpoint, key or model not configured'];
		}

		$tempPath = tempnam(sys_get_temp_dir(), 'educai_speech_test_');
		if ($tempPath === false) {
			return ['success' => false, 'error' => 'Unable to allocate temporary test audio'];
		}

		try {
			file_put_contents($tempPath, $this->buildSilentWav());
			$this->transcribeWithResolvedConfig($resolvedEndpoint, $resolvedKey, $resolvedModel, $tempPath);
			return ['success' => true];
		} catch (Exception $e) {
			return ['success' => false, 'error' => $e->getMessage()];
		} finally {
			if (is_file($tempPath)) {
				@unlink($tempPath);
			}
		}
	}

	/**
	 * @throws Exception
	 */
	private function transcribeWithResolvedConfig(string $endpoint, string $apiKey, string $model, string $tempPath): void {
		$client = $this->clientService->newClient();
		$boundary = 'educai_boundary_' . bin2hex(random_bytes(8));
		$content = file_get_contents($tempPath);
		if (!is_string($content)) {
			throw new Exception('Unable to read temporary test audio');
		}

		$response = $client->post($endpoint, [
			'headers' => [
				'Authorization' => 'Bearer ' . $apiKey,
				'Accept' => 'application/json',
				'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
			],
			'body' => $this->buildMultipartBody($boundary, 'test.wav', $content, 'audio/wav', $model),
			'timeout' => 60,
		]);

		$payload = json_decode((string)$response->getBody(), true);
		if (!is_array($payload)) {
			throw new Exception('Speech test returned invalid response');
		}
	}

	private function resolveTranscriptionEndpoint(?string $customEndpoint): ?string {
		$endpoint = $customEndpoint;
		if ($endpoint === null || $endpoint === '') {
			$endpoint = $this->settingsService->getSettings()->getApiEndpoint();
		}
		if ($endpoint === null || $endpoint === '') {
			return null;
		}

		$normalized = rtrim($endpoint, '/');
		if (preg_match('#/v1/chat/completions$#', $normalized)) {
			return (string)preg_replace('#/v1/chat/completions$#', '/v1/audio/transcriptions', $normalized);
		}
		if (preg_match('#/chat/completions$#', $normalized)) {
			return (string)preg_replace('#/chat/completions$#', '/audio/transcriptions', $normalized);
		}
		if (preg_match('#/v1$#', $normalized)) {
			return $normalized . '/audio/transcriptions';
		}

		return $normalized;
	}

	private function buildMultipartBody(string $boundary, string $filename, string $content, string $mimeType, string $model): string {
		$body = '';

		$body .= '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="model"' . "\r\n\r\n";
		$body .= $model . "\r\n";

		$body .= '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . "\r\n";
		$body .= 'Content-Type: ' . $mimeType . "\r\n\r\n";
		$body .= $content . "\r\n";

		$body .= '--' . $boundary . "--\r\n";

		return $body;
	}

	private function buildSilentWav(): string {
		$sampleRate = 8000;
		$durationSeconds = 1;
		$samples = $sampleRate * $durationSeconds;
		$data = str_repeat("\x00\x00", $samples);
		$dataSize = strlen($data);
		$byteRate = $sampleRate * 2;
		$blockAlign = 2;

		return 'RIFF'
			. pack('V', 36 + $dataSize)
			. 'WAVEfmt '
			. pack('VvvVVvv', 16, 1, 1, $sampleRate, $byteRate, $blockAlign, 16)
			. 'data'
			. pack('V', $dataSize)
			. $data;
	}
}
