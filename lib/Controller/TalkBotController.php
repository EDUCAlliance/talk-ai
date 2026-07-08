<?php

declare(strict_types=1);

namespace OCA\EducAI\Controller;

use Exception;
use OCA\EducAI\Db\BotMapper;
use OCA\EducAI\Exception\TalkApiException;
use OCA\EducAI\Service\BotService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\DataResponse;
use OCP\Http\Client\IClientService;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Controller to proxy Talk bot API calls for the Smart Picker.
 * 
 * This controller handles checking and enabling the "Talk AI" Talk bot
 * in conversations, which is required for the bot to receive messages.
 */
class TalkBotController extends Controller {
	private const EDUC_AI_BOT_NAME = \OCA\EducAI\AppInfo\Application::APP_DISPLAY_NAME;

	private IClientService $clientService;
	private IURLGenerator $urlGenerator;
	private IRequest $ncRequest;
	private BotMapper $botMapper;
	private BotService $botService;
	private LoggerInterface $logger;
	private ?string $userId;

	public function __construct(
		string $appName,
		IRequest $request,
		IClientService $clientService,
		IURLGenerator $urlGenerator,
		BotMapper $botMapper,
		BotService $botService,
		LoggerInterface $logger,
		?string $userId
	) {
		parent::__construct($appName, $request);
		$this->clientService = $clientService;
		$this->urlGenerator = $urlGenerator;
		$this->ncRequest = $request;
		$this->botMapper = $botMapper;
		$this->botService = $botService;
		$this->logger = $logger;
		$this->userId = $userId;
	}

	/**
	 * @NoAdminRequired
	 * 
	 * Get the status of the Talk AI bot in a Talk room.
	 * 
	 * @param string $roomToken The Talk room token
	 * @return DataResponse Contains:
	 *   - botEnabled: boolean (is Talk AI bot enabled in room)
	 *   - isModerator: boolean (can user enable bots)
	 *   - educAiBotId: int|null (the Talk bot ID for "Talk AI")
	 */
	public function status(string $roomToken): DataResponse {
		try {
			if ($this->userId === null) {
				return new DataResponse(['error' => 'Not authenticated'], 401);
			}

			// Get room info to check if user is moderator
			$roomInfo = $this->getRoomInfo($roomToken);
			$isModerator = $this->isModerator($roomInfo);

			// Get bot list for this room
			$bots = $this->getBotsInRoom($roomToken);
			$educAiBot = $this->findEducAiBot($bots);

			$botEnabled = false;
			$educAiBotId = null;

			if ($educAiBot !== null) {
				$educAiBotId = $educAiBot['id'];
				// state: 0=disabled, 1=enabled
				$botEnabled = ($educAiBot['state'] ?? 0) === 1;
			}

			$this->logger->debug('Talk bot status checked', [
				'roomToken' => $roomToken,
				'userId' => $this->userId,
				'isModerator' => $isModerator,
				'botEnabled' => $botEnabled,
				'educAiBotId' => $educAiBotId,
			]);

			return new DataResponse([
				'botEnabled' => $botEnabled,
				'isModerator' => $isModerator,
				'educAiBotId' => $educAiBotId,
			]);
		} catch (Exception $e) {
			$this->logger->error('Failed to get Talk bot status: ' . $e->getMessage(), [
				'roomToken' => $roomToken,
				'exception' => $e,
			]);
			return new DataResponse(['error' => $e->getMessage()], 500);
		}
	}

	/**
	 * @NoAdminRequired
	 * 
	 * Enable the Talk AI bot in a Talk room.
	 * Only moderators can enable bots.
	 * 
	 * @param string $roomToken The Talk room token
	 * @return DataResponse
	 */
	public function enableBot(string $roomToken): DataResponse {
		try {
			if ($this->userId === null) {
				return new DataResponse(['error' => 'Not authenticated'], 401);
			}

			$roomInfo = $this->getRoomInfo($roomToken);
			$isModerator = $this->isModerator($roomInfo);

			// Get bot list to find Talk AI bot ID
			$bots = $this->getBotsInRoom($roomToken);
			$educAiBot = $this->findEducAiBot($bots);

			if ($educAiBot === null) {
				$this->logger->warning(self::EDUC_AI_BOT_NAME . ' bot not found in Talk', [
					'roomToken' => $roomToken,
				]);
				return new DataResponse([
					'error' => self::EDUC_AI_BOT_NAME . ' bot is not registered in Talk. Please ask an administrator to register it.',
				], 404);
			}

			$botId = $educAiBot['id'];

			// Check if already enabled
			if (($educAiBot['state'] ?? 0) === 1) {
				return new DataResponse([
					'success' => true,
					'message' => 'Bot already enabled',
				]);
			}

			if (!$isModerator) {
				$this->logger->warning('User attempted to enable Talk AI bot without moderator rights', [
					'roomToken' => $roomToken,
					'userId' => $this->userId,
				]);

				return new DataResponse([
					'error' => 'You do not have permission to enable bots in this conversation. Only moderators can enable bots.',
				], 403);
			}

			// Enable the bot
			$this->enableBotInRoom($roomToken, $botId);

			$this->logger->info(self::EDUC_AI_BOT_NAME . ' bot enabled in room', [
				'roomToken' => $roomToken,
				'botId' => $botId,
				'userId' => $this->userId,
			]);

			return new DataResponse([
				'success' => true,
				'message' => 'Bot enabled successfully',
			]);
		} catch (Exception $e) {
			$this->logger->error('Failed to enable Talk bot: ' . $e->getMessage(), [
				'roomToken' => $roomToken,
				'exception' => $e,
			]);
			
			// Check if it's a permission error
			if (strpos($e->getMessage(), '403') !== false || strpos($e->getMessage(), 'Forbidden') !== false) {
				return new DataResponse([
					'error' => 'You do not have permission to enable bots in this conversation. Only moderators can enable bots.',
				], 403);
			}
			
			return new DataResponse(['error' => $e->getMessage()], 500);
		}
	}

	/**
	 * @NoAdminRequired
	 *
	 * List Talk rooms accessible to the current user for the catalogue start flow.
	 */
	public function rooms(): DataResponse {
		try {
			if ($this->userId === null) {
				return new DataResponse(['error' => 'Not authenticated'], 401);
			}

			$rooms = $this->listRooms();

			return new DataResponse(['rooms' => $rooms]);
		} catch (TalkApiException $e) {
			$this->logger->warning('Failed to list Talk rooms: ' . $e->getMessage(), [
				'userId' => $this->userId,
				'statusCode' => $e->getStatusCode(),
			]);

			return new DataResponse([
				'error' => $this->formatTalkAvailabilityError($e),
			], $this->mapTalkFailureStatus($e));
		} catch (Exception $e) {
			$this->logger->error('Failed to list Talk rooms: ' . $e->getMessage(), [
				'userId' => $this->userId,
				'exception' => $e,
			]);

			return new DataResponse(['error' => 'Failed to load Talk conversations'], 500);
		}
	}

	/**
	 * @NoAdminRequired
	 *
	 * Create or reuse a Talk room, enable the shared Talk AI Talk bot, and
	 * optionally send the selected bot mention as the current user.
	 */
	public function startBotChat(): DataResponse {
		try {
			if ($this->userId === null) {
				return new DataResponse(['error' => 'Not authenticated'], 401);
			}

			$botId = (int)$this->ncRequest->getParam('botId', 0);
			if ($botId <= 0) {
				return new DataResponse(['error' => 'Missing botId'], 400);
			}

			try {
				$bot = $this->botMapper->findById($botId);
			} catch (DoesNotExistException $e) {
				return new DataResponse(['error' => 'Bot not found'], 404);
			}

			if (!$bot->getIsActive() || !$this->botService->userCanAccessBot($bot, $this->userId)) {
				return new DataResponse(['error' => 'You do not have access to this bot'], 403);
			}

			$mode = (string)$this->ncRequest->getParam('mode', 'new');
			$sendMessage = $this->normalizeBoolean($this->ncRequest->getParam('sendMessage', false));
			$requestedMessage = trim((string)$this->ncRequest->getParam('message', ''));
			$message = $this->buildInitialMessage($bot->getMentionName(), $requestedMessage);

			$roomToken = '';
			$roomInfo = [];
			$createdRoom = false;

			if ($mode === 'new') {
				$roomName = trim((string)$this->ncRequest->getParam('roomName', ''));
				if ($roomName === '') {
					$roomName = 'Chat with ' . $bot->getBotName();
				}
				$roomInfo = $this->createGroupRoom($roomName);
				$roomToken = $this->extractRoomToken($roomInfo);
				if (!isset($roomInfo['participantType'])) {
					$roomInfo['participantType'] = 1;
				}
				$createdRoom = true;
			} elseif ($mode === 'existing') {
				$roomToken = trim((string)$this->ncRequest->getParam('roomToken', ''));
				if ($roomToken === '') {
					return new DataResponse(['error' => 'Please select a Talk conversation'], 400);
				}
				$roomInfo = $this->getRoomInfo($roomToken);
			} else {
				return new DataResponse(['error' => 'Unsupported Talk start mode'], 400);
			}

			$enableResult = $this->ensureEducAiBotEnabled($roomToken, $roomInfo);

			$messageResult = null;
			if ($sendMessage) {
				$messageResult = $this->sendChatMessage($roomToken, $message);
			}

			$this->logger->info('Started Talk AI Talk chat from public catalogue', [
				'userId' => $this->userId,
				'botId' => $botId,
				'mode' => $mode,
				'roomToken' => $roomToken,
				'createdRoom' => $createdRoom,
				'botWasAlreadyEnabled' => $enableResult['alreadyEnabled'],
				'messageSent' => $messageResult !== null,
			]);

			return new DataResponse([
				'success' => true,
				'roomToken' => $roomToken,
				'talkUrl' => $this->buildTalkRoomUrl($roomToken),
				'botEnabled' => true,
				'botWasAlreadyEnabled' => $enableResult['alreadyEnabled'],
				'messageSent' => $messageResult !== null,
				'messageId' => $messageResult['id'] ?? null,
			]);
		} catch (TalkApiException $e) {
			$this->logger->warning('Failed to start Talk AI Talk chat: ' . $e->getMessage(), [
				'userId' => $this->userId,
				'statusCode' => $e->getStatusCode(),
			]);

			$status = $this->mapTalkFailureStatus($e);
			return new DataResponse([
				'error' => $this->formatStartChatTalkError($e),
			], $status);
		} catch (Exception $e) {
			$this->logger->error('Failed to start Talk AI Talk chat: ' . $e->getMessage(), [
				'userId' => $this->userId,
				'exception' => $e,
			]);

			return new DataResponse(['error' => $e->getMessage()], 500);
		}
	}

	/**
	 * Get room information from Talk API.
	 * 
	 * @param string $roomToken
	 * @return array Room data
	 * @throws Exception
	 */
	private function getRoomInfo(string $roomToken): array {
		return $this->callTalkApi('GET', 'v4/room/' . rawurlencode($roomToken));
	}

	/**
	 * Get list of bots in a Talk room.
	 * 
	 * @param string $roomToken
	 * @return array List of bots
	 * @throws Exception
	 */
	private function getBotsInRoom(string $roomToken): array {
		return $this->callTalkApi('GET', 'v1/bot/' . rawurlencode($roomToken));
	}

	private function listRooms(): array {
		$rooms = $this->callTalkApi('GET', 'v4/room');
		if (!is_array($rooms)) {
			return [];
		}

		$mapped = [];
		foreach ($rooms as $room) {
			if (!is_array($room) || empty($room['token'])) {
				continue;
			}

			$participantType = (int)($room['participantType'] ?? 3);
			$mapped[] = [
				'token' => (string)$room['token'],
				'displayName' => (string)($room['displayName'] ?? $room['name'] ?? 'Talk conversation'),
				'type' => (int)($room['type'] ?? 0),
				'participantType' => $participantType,
				'isModerator' => $participantType <= 2,
				'readOnly' => (int)($room['readOnly'] ?? 0),
				'lastActivity' => (int)($room['lastActivity'] ?? 0),
			];
		}

		usort($mapped, static function (array $a, array $b): int {
			if ($a['isModerator'] !== $b['isModerator']) {
				return $a['isModerator'] ? -1 : 1;
			}
			return $b['lastActivity'] <=> $a['lastActivity'];
		});

		return $mapped;
	}

	private function createGroupRoom(string $roomName): array {
		return $this->callTalkApi('POST', 'v4/room', [
			'roomType' => 2,
			'roomName' => mb_substr($roomName, 0, 255),
		]);
	}

	/**
	 * @return array{alreadyEnabled:bool,botId:int}
	 */
	private function ensureEducAiBotEnabled(string $roomToken, array $roomInfo): array {
		$bots = $this->getBotsInRoom($roomToken);
		$educAiBot = $this->findEducAiBot($bots);

		if ($educAiBot === null) {
			throw new TalkApiException(
				self::EDUC_AI_BOT_NAME . ' bot is not registered in Talk. Please ask an administrator to register it.',
				404
			);
		}

		$botId = (int)$educAiBot['id'];
		if (((int)($educAiBot['state'] ?? 0)) === 1) {
			return ['alreadyEnabled' => true, 'botId' => $botId];
		}

		if (!$this->isModerator($roomInfo)) {
			throw new TalkApiException(
				'You do not have permission to enable bots in this conversation. Only moderators can enable bots.',
				403
			);
		}

		$this->enableBotInRoom($roomToken, $botId);

		return ['alreadyEnabled' => false, 'botId' => $botId];
	}

	private function sendChatMessage(string $roomToken, string $message): array {
		return $this->callTalkApi('POST', 'v1/chat/' . rawurlencode($roomToken), [
			'message' => $message,
			'referenceId' => bin2hex(random_bytes(16)),
		]);
	}

	private function callTalkApi(string $method, string $path, array $data = []): array {
		$baseUrl = $this->urlGenerator->getAbsoluteURL('');
		$endpoint = $baseUrl . 'ocs/v2.php/apps/spreed/api/' . ltrim($path, '/');

		$client = $this->clientService->newClient();
		$options = [
			'headers' => $this->getRequestHeaders(),
			'cookies' => $this->getForwardedCookies(),
			'query' => ['format' => 'json'],
		];

		if ($data !== []) {
			$options['body'] = $data;
		}

		if (strtoupper($method) === 'POST') {
			$response = $client->post($endpoint, $options);
		} else {
			$response = $client->get($endpoint, $options);
		}

		$statusCode = $response->getStatusCode();
		$body = $response->getBody();
		if ($statusCode < 200 || $statusCode >= 300) {
			throw new TalkApiException(
				'Talk API request failed with HTTP ' . $statusCode,
				$statusCode,
				mb_substr($body, 0, 500)
			);
		}

		$decoded = json_decode($body, true);
		if (!is_array($decoded)) {
			throw new TalkApiException('Talk API returned an invalid JSON response', $statusCode, mb_substr($body, 0, 500));
		}

		$ocsStatusCode = (int)($decoded['ocs']['meta']['statuscode'] ?? 200);
		if ($ocsStatusCode >= 400) {
			$message = (string)($decoded['ocs']['meta']['message'] ?? 'Talk API request failed');
			throw new TalkApiException($message, $ocsStatusCode, mb_substr($body, 0, 500));
		}

		$responseData = $decoded['ocs']['data'] ?? [];
		return is_array($responseData) ? $responseData : [];
	}

	/**
	 * Enable a bot in a Talk room.
	 * 
	 * @param string $roomToken
	 * @param int $botId
	 * @throws Exception
	 */
	private function enableBotInRoom(string $roomToken, int $botId): void {
		$this->callTalkApi('POST', 'v1/bot/' . rawurlencode($roomToken) . '/' . $botId);
	}

	private function extractRoomToken(array $roomInfo): string {
		$token = trim((string)($roomInfo['token'] ?? ''));
		if ($token === '') {
			throw new TalkApiException('Talk did not return a room token after creating the conversation', 502);
		}

		return $token;
	}

	private function buildTalkRoomUrl(string $roomToken): string {
		return $this->urlGenerator->getAbsoluteURL(
			'index.php/call/' . rawurlencode($roomToken)
		);
	}

	private function normalizeMentionName(string $mentionName): string {
		$name = ltrim(trim($mentionName), '@');
		return '@' . $name;
	}

	private function buildInitialMessage(string $mentionName, string $message): string {
		$mention = $this->normalizeMentionName($mentionName);
		if ($message === '') {
			return $mention;
		}

		$normalizedMessage = trim($message);
		if (preg_match('/(^|\s)' . preg_quote($mention, '/') . '(\s|$)/i', $normalizedMessage) === 1) {
			return $normalizedMessage;
		}

		return $mention . ' ' . $normalizedMessage;
	}

	private function normalizeBoolean($value): bool {
		if (is_bool($value)) {
			return $value;
		}

		if (is_int($value)) {
			return $value === 1;
		}

		if (is_string($value)) {
			$result = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
			return $result ?? false;
		}

		return false;
	}

	/**
	 * Find the Talk AI bot in a list of bots.
	 * 
	 * @param array $bots
	 * @return array|null Bot data or null if not found
	 */
	private function findEducAiBot(array $bots): ?array {
		foreach ($bots as $bot) {
			if (($bot['name'] ?? '') === self::EDUC_AI_BOT_NAME) {
				return $bot;
			}
		}
		return null;
	}

	/**
	 * Determine whether the current participant can manage bots in the room.
	 *
	 * participantType: 1 = owner, 2 = moderator, 3+ = regular user.
	 */
	private function isModerator(array $roomInfo): bool {
		return ((int)($roomInfo['participantType'] ?? 3)) <= 2;
	}

	private function mapTalkFailureStatus(TalkApiException $e): int {
		$status = $e->getStatusCode();
		if ($status === 401 || $status === 403 || $status === 404) {
			return $status;
		}

		if ($status >= 400 && $status < 500) {
			return 400;
		}

		return 502;
	}

	private function formatTalkAvailabilityError(TalkApiException $e): string {
		if ($e->getStatusCode() === 404) {
			return 'Nextcloud Talk is not available. Please ask an administrator to enable the Talk app.';
		}

		return 'Failed to load Talk conversations: ' . $e->getMessage();
	}

	private function formatStartChatTalkError(TalkApiException $e): string {
		$status = $e->getStatusCode();
		if ($status === 403) {
			return $e->getMessage();
		}
		if ($status === 404 && str_contains($e->getMessage(), self::EDUC_AI_BOT_NAME . ' bot')) {
			return $e->getMessage();
		}
		if ($status === 404) {
			return 'Nextcloud Talk is not available or the selected conversation could not be found.';
		}
		if ($status >= 500 || $status === 0) {
			return 'Talk could not complete this action right now: ' . $e->getMessage();
		}

		return $e->getMessage();
	}

	/**
	 * Get headers for internal API calls, including auth forwarding.
	 * 
	 * @return array
	 */
	private function getRequestHeaders(): array {
		$headers = [
			'OCS-APIRequest' => 'true',
			'Accept' => 'application/json',
		];
		
		// Forward Authorization header if present (for basic auth)
		$authHeader = $this->ncRequest->getHeader('Authorization');
		if (!empty($authHeader)) {
			$headers['Authorization'] = $authHeader;
		}
		
		return $headers;
	}

	/**
	 * Get cookies from the current request to forward to internal API calls.
	 * This enables session-based authentication for the Talk API.
	 * 
	 * @return \GuzzleHttp\Cookie\CookieJar
	 */
	private function getForwardedCookies(): \GuzzleHttp\Cookie\CookieJar {
		$jar = new \GuzzleHttp\Cookie\CookieJar();
		
		// Get cookies from $_COOKIE superglobal
		$cookies = $_COOKIE;
		
		// Parse the host for the cookie domain
		$host = $this->ncRequest->getServerHost();
		
		foreach ($cookies as $name => $value) {
			$jar->setCookie(new \GuzzleHttp\Cookie\SetCookie([
				'Name' => $name,
				'Value' => $value,
				'Domain' => $host,
				'Path' => '/',
			]));
		}
		
		return $jar;
	}
}
