<?php

declare(strict_types=1);

namespace OCA\EducAI\Controller;

use OCA\EducAI\Exception\InvalidWebhookException;
use OCA\EducAI\Webhook\TalkHandler;
use OCA\EducAI\Webhook\TalkWebhookPayload;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class WebhookController extends Controller {
	private TalkHandler $talkHandler;
	private LoggerInterface $logger;

	public function __construct(
		string $appName,
		IRequest $request,
		TalkHandler $talkHandler,
		LoggerInterface $logger
	) {
		parent::__construct($appName, $request);
		$this->talkHandler = $talkHandler;
		$this->logger = $logger;
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 * Handle incoming webhook from Nextcloud Talk
	 */
	public function talk(): Response {
		$signature = $this->request->getHeader('X-Nextcloud-Talk-Signature');
		$random = $this->request->getHeader('X-Nextcloud-Talk-Random');
		if ($signature === '' || $random === '') {
			return new Response(Http::STATUS_UNAUTHORIZED);
		}
		$contentLength = $this->request->getHeader('Content-Length');
		if (ctype_digit($contentLength) && (int)$contentLength > TalkWebhookPayload::MAX_BODY_BYTES) {
			return new Response(Http::STATUS_REQUEST_ENTITY_TOO_LARGE);
		}

		try {
			$body = $this->readRequestBody();
			if ($body === false) {
				throw new \RuntimeException('Could not read Talk webhook body');
			}
			TalkWebhookPayload::assertBodySize($body);
			$this->logger->info('========== Talk AI Webhook Received ==========', [
				'body_length' => strlen($body),
			]);
			$this->talkHandler->handleIncoming([
				'body' => $body,
				'signature' => $signature,
				'random' => $random,
			]);
			
			$this->logger->info('========== Webhook Processing Complete ==========');
			
			$response = new Response();
			$response->setStatus(Http::STATUS_OK);
			return $response;
			
		} catch (InvalidWebhookException $e) {
			$this->logger->warning('Rejected Talk webhook request', [
				'error' => $e->getMessage(),
				'status' => $e->getStatusCode(),
			]);
			return new Response($e->getStatusCode());
		} catch (\Throwable $e) {
			$this->logger->error('========== Webhook Processing FAILED ==========', [
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
			
			$response = new Response();
			$response->setStatus(Http::STATUS_OK); // Return 200 anyway to not trigger Talk retries
			return $response;
		}
	}

	protected function readRequestBody(): string|false {
		// Do not trust Content-Length: chunked requests still get a bounded read.
		return file_get_contents('php://input', false, null, 0, TalkWebhookPayload::MAX_BODY_BYTES + 1);
	}
}
