<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use Exception;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

class VisionClient {
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
		return (bool)$this->settingsService->getVisionConfig()['enabled'];
	}

	/**
	 * @throws Exception
	 */
	public function analyzeImage(string $tempPath, string $displayName, string $prompt = 'Describe this image in detail.'): string {
		$config = $this->settingsService->getVisionConfig();
		$model = $config['model'] ?? null;
		if ($model === null || $model === '') {
			throw new Exception('Vision model not configured');
		}

		$apiKey = $config['api_key'] ?: $this->settingsService->getApiKey();
		if ($apiKey === null || $apiKey === '') {
			throw new Exception('Vision API key not configured');
		}

		$endpoint = $this->resolveChatEndpoint($config['endpoint'] ?? null);
		if ($endpoint === null) {
			throw new Exception('Vision endpoint not configured');
		}

		$dataUri = $this->buildDataUri($tempPath);
		$client = $this->clientService->newClient();

		$response = $client->post($endpoint, [
			'headers' => [
				'Authorization' => 'Bearer ' . $apiKey,
				'Content-Type' => 'application/json',
				'Accept' => 'application/json',
			],
			'json' => [
				'model' => $model,
				'messages' => [
					[
						'role' => 'user',
						'content' => [
							[
								'type' => 'text',
								'text' => $prompt . "\n\nFilename: " . $displayName,
							],
							[
								'type' => 'image_url',
								'image_url' => [
									'url' => $dataUri,
								],
							],
						],
					],
				],
				'temperature' => 0.2,
				'max_tokens' => 1200,
			],
			'timeout' => 120,
		]);

		$payload = json_decode((string)$response->getBody(), true);
		$content = $payload['choices'][0]['message']['content'] ?? null;
		if (!is_string($content) || trim($content) === '') {
			throw new Exception('Vision API returned no content');
		}

		return trim($content);
	}

	/**
	 * @return array{success:bool,error?:string}
	 */
	public function testConnection(?string $endpoint = null, ?string $apiKey = null, ?string $model = null): array {
		$config = $this->settingsService->getVisionConfig();
		$resolvedEndpoint = $this->resolveChatEndpoint($endpoint ?? $config['endpoint'] ?? null);
		$resolvedKey = $apiKey ?? $config['api_key'] ?? $this->settingsService->getApiKey();
		$resolvedModel = $model ?? $config['model'] ?? null;

		if ($resolvedEndpoint === null || $resolvedKey === null || $resolvedKey === '' || $resolvedModel === null || $resolvedModel === '') {
			return ['success' => false, 'error' => 'Vision endpoint, key or model not configured'];
		}

		try {
			$this->analyzeInlinePixel($resolvedEndpoint, $resolvedKey, $resolvedModel);
			return ['success' => true];
		} catch (Exception $e) {
			return ['success' => false, 'error' => $e->getMessage()];
		}
	}

	/**
	 * @throws Exception
	 */
	private function analyzeInlinePixel(string $endpoint, string $apiKey, string $model): void {
		$client = $this->clientService->newClient();
		$response = $client->post($endpoint, [
			'headers' => [
				'Authorization' => 'Bearer ' . $apiKey,
				'Content-Type' => 'application/json',
				'Accept' => 'application/json',
			],
			'json' => [
				'model' => $model,
				'messages' => [
					[
						'role' => 'user',
						'content' => [
							['type' => 'text', 'text' => 'Reply with OK'],
							[
								'type' => 'image_url',
								'image_url' => [
									'url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAIAAAD8GO2jAAAAP0lEQVR4nGOUizrBQEvARFPTRy0YtYAqgAWXxKVpGiQZpJd1A6v40A+iUQtGLRi1YNSCEWEB42i7aNSCUQsYAEK1BcHARuLOAAAAAElFTkSuQmCC',
								],
							],
						],
					],
				],
				'temperature' => 0,
				'max_tokens' => 16,
			],
			'timeout' => 60,
		]);

		$payload = json_decode((string)$response->getBody(), true);
		if (!isset($payload['choices'][0]['message']['content'])) {
			throw new Exception('Vision test returned invalid response');
		}
	}

	/**
	 * @throws Exception
	 */
	private function buildDataUri(string $tempPath): string {
		$content = file_get_contents($tempPath);
		if (!is_string($content) || $content === '') {
			throw new Exception('Unable to read temporary image content');
		}

		$mimeType = mime_content_type($tempPath);
		if (!is_string($mimeType) || $mimeType === '') {
			$mimeType = 'application/octet-stream';
		}

		// Vision providers (Azure OpenAI etc.) only accept PNG/JPEG/GIF/WEBP.
		// Transcode anything else (HEIC from iPhones, TIFF, BMP, ...) to PNG so
		// those uploads still work; fail with a clear message if we cannot.
		$supported = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
		if (!in_array($mimeType, $supported, true)) {
			$converted = $this->transcodeImageToPng($content);
			if ($converted === null) {
				throw new Exception('Unsupported image format "' . $mimeType . '". Supported formats: PNG, JPEG, GIF, WEBP.');
			}
			$content = $converted;
			$mimeType = 'image/png';
		}

		return 'data:' . $mimeType . ';base64,' . base64_encode($content);
	}

	/**
	 * Convert an image (e.g. HEIC/TIFF/BMP) to PNG bytes so vision providers
	 * that only accept PNG/JPEG/GIF/WEBP can read it. Returns null when no
	 * usable image library is available or the input cannot be decoded.
	 */
	private function transcodeImageToPng(string $content): ?string {
		if (class_exists('\\Imagick')) {
			try {
				$image = new \Imagick();
				$image->readImageBlob($content);
				$image->setImageFormat('png');
				$png = $image->getImageBlob();
				$image->clear();
				if (is_string($png) && $png !== '') {
					return $png;
				}
			} catch (\Throwable $e) {
				$this->logger->warning('Vision image transcode via Imagick failed: ' . $e->getMessage());
			}
		}

		if (function_exists('imagecreatefromstring')) {
			$gd = @imagecreatefromstring($content);
			if ($gd !== false) {
				ob_start();
				$ok = imagepng($gd);
				$png = ob_get_clean();
				imagedestroy($gd);
				if ($ok && is_string($png) && $png !== '') {
					return $png;
				}
			}
		}

		return null;
	}

	private function resolveChatEndpoint(?string $customEndpoint): ?string {
		$endpoint = $customEndpoint;
		if ($endpoint === null || $endpoint === '') {
			$endpoint = $this->settingsService->getSettings()->getApiEndpoint();
		}
		if ($endpoint === null || $endpoint === '') {
			return null;
		}

		return rtrim($endpoint, '/');
	}
}
