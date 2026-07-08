<?php

declare(strict_types=1);

namespace OCA\EducAI\Controller;

use Exception;
use OCA\EducAI\Webhook\TalkHandler;
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
		$body = file_get_contents('php://input');
		$signature = $this->request->getHeader('X-Nextcloud-Talk-Signature');
		$random = $this->request->getHeader('X-Nextcloud-Talk-Random');

		$this->logger->info('========== Talk AI Webhook Received ==========', [
			'has_signature' => !empty($signature),
			'has_random' => !empty($random),
			'body_length' => strlen($body),
		]);

		try {
			$this->talkHandler->handleIncoming([
				'body' => $body,
				'signature' => $signature,
				'random' => $random,
			]);
			
			$this->logger->info('========== Webhook Processing Complete ==========');
			
			$response = new Response();
			$response->setStatus(Http::STATUS_OK);
			return $response;
			
		} catch (Exception $e) {
			$this->logger->error('========== Webhook Processing FAILED ==========', [
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
			
			$response = new Response();
			$response->setStatus(Http::STATUS_OK); // Return 200 anyway to not trigger Talk retries
			return $response;
		}
	}
}
