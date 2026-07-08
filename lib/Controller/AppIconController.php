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
use OCP\IRequest;
use OCP\IURLGenerator;

class AppIconController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private AppIconService $appIconService,
		private IURLGenerator $urlGenerator,
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
			return new DataResponse(['error' => 'No SVG file uploaded'], Http::STATUS_BAD_REQUEST);
		}

		$fileName = (string)($upload['name'] ?? '');
		$tmpPath = (string)($upload['tmp_name'] ?? '');
		if (!str_ends_with(strtolower($fileName), '.svg') || $tmpPath === '' || !is_readable($tmpPath)) {
			return new DataResponse(['error' => 'App icon upload must be an SVG file'], Http::STATUS_BAD_REQUEST);
		}

		$content = file_get_contents($tmpPath);
		if (!is_string($content)) {
			return new DataResponse(['error' => 'Unable to read uploaded SVG file'], Http::STATUS_BAD_REQUEST);
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
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
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
