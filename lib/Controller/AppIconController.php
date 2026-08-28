<?php

declare(strict_types=1);

namespace OCA\EducAI\Controller;

use OCA\EducAI\Service\AppIconService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class AppIconController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private AppIconService $appIconService,
		private IURLGenerator $urlGenerator,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function show(string $variant): Response {
		return $this->buildIconResponse($this->appIconService->getConfiguredIconFile($variant))
			?? $this->buildBundledIconRedirect($variant);
	}

	/**
	 * @AdminRequired
	 * @NoCSRFRequired
	 */
	public function preview(string $variant, string $source = ''): Response {
		return $this->buildIconResponse($this->appIconService->resolveUploadedIconReference($source))
			?? new NotFoundResponse();
	}

	/**
	 * @AdminRequired
	 */
	public function upload(string $variant): DataResponse {
		$upload = $this->request->getUploadedFile('icon');
		if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			return $this->errorResponse(
				'icon_upload_missing',
				$this->l10n->t('No SVG file was uploaded.'),
				400,
			);
		}

		$fileName = (string)($upload['name'] ?? '');
		$tmpPath = (string)($upload['tmp_name'] ?? '');
		if (!str_ends_with(strtolower($fileName), '.svg') || $tmpPath === '' || !is_readable($tmpPath)) {
			return $this->errorResponse(
				'icon_upload_not_svg',
				$this->l10n->t('The app icon must be an SVG file.'),
				400,
			);
		}

		$content = file_get_contents($tmpPath);
		if (!is_string($content)) {
			return $this->errorResponse(
				'icon_upload_read_failed',
				$this->l10n->t('The uploaded SVG file could not be read.'),
				400,
			);
		}

		try {
			$this->appIconService->storeUploadedIcon($variant, $content);
			$reference = $this->appIconService->getUploadedIconReference($variant);
			return new DataResponse([
				'value' => $reference,
				'preview_url' => $this->urlGenerator->linkToRoute('educai.app_icon.preview', [
					'variant' => $variant,
					'source' => $reference,
					'v' => (string)time(),
				]),
			]);
		} catch (\InvalidArgumentException $e) {
			$this->logger->warning('Invalid app icon upload', [
				'variant' => $variant,
				'exception' => $e,
			]);
			return $this->errorResponse(
				'icon_upload_invalid',
				$this->l10n->t('The uploaded app icon is invalid.'),
				400,
			);
		} catch (\Throwable $e) {
			$this->logger->error('Failed to upload app icon', [
				'variant' => $variant,
				'exception' => $e,
			]);
			return $this->errorResponse(
				'icon_upload_failed',
				$this->l10n->t('Failed to upload the app icon'),
				500,
			);
		}
	}

	private function errorResponse(string $errorCode, string $error, int $status): DataResponse {
		return new DataResponse([
			'error' => $error,
			'errorCode' => $errorCode,
		], $status);
	}

	private function buildIconResponse(?ISimpleFile $file): ?Response {
		if ($file === null) {
			return null;
		}

		$response = new FileDisplayResponse($file, Http::STATUS_OK, [
			'Content-Type' => 'image/svg+xml',
		]);
		$response->cacheFor(300, false);

		return $response;
	}

	private function buildBundledIconRedirect(string $variant): RedirectResponse {
		return new RedirectResponse($this->urlGenerator->imagePath('educai', $variant === 'white' ? 'app.svg' : 'app-dark.svg'));
	}
}
