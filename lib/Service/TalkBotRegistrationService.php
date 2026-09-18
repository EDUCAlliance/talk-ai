<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use OC\Console\Application as ConsoleApplication;
use OCA\EducAI\AppInfo\Application;
use OCA\EducAI\Db\SettingsMapper;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;

class TalkBotRegistrationService {
	public const TALK_APP_ID = 'spreed';

	private const BOT_DESCRIPTION = 'Multi-bot AI manager for Nextcloud Talk';
	private const BOT_FEATURES = 3;
	private const CONFIG_BOT_ID = 'talk_bot_id';
	private const CONFIG_BOT_URL = 'talk_bot_url';

	private SettingsMapper $settingsMapper;
	private CredentialService $credentialService;
	private IAppManager $appManager;
	private IConfig $config;
	private IRequest $request;
	private IURLGenerator $urlGenerator;
	private IClientService $clientService;
	private LoggerInterface $logger;
	private BrandingService $brandingService;
	private bool $consoleCommandsLoaded = false;

	public function __construct(
		SettingsMapper $settingsMapper,
		CredentialService $credentialService,
		IAppManager $appManager,
		IConfig $config,
		IRequest $request,
		IURLGenerator $urlGenerator,
		IClientService $clientService,
		LoggerInterface $logger,
		?BrandingService $brandingService = null
	) {
		$this->settingsMapper = $settingsMapper;
		$this->credentialService = $credentialService;
		$this->appManager = $appManager;
		$this->config = $config;
		$this->request = $request;
		$this->urlGenerator = $urlGenerator;
		$this->clientService = $clientService;
		$this->logger = $logger;
		$this->brandingService = $brandingService ?? new BrandingService($config);
	}

	/**
	 * Rename only the app-managed Talk registration, preserving its ID, state,
	 * secret, features and room memberships. Never rename users' bot definitions.
	 *
	 * @return array{status:string,message:string}
	 */
	public function updateManagedDisplayName(): array {
		if (!$this->isTalkAvailable()) {
			return ['status' => 'skipped', 'message' => 'Branding saved. Talk is not enabled; its registration will use this name when enabled.'];
		}
		try {
			$mapper = $this->getTalkBotServerMapper();
			if ($mapper === null || !method_exists($mapper, 'findById') || !method_exists($mapper, 'update')) {
				throw new \RuntimeException('Talk does not support updating the managed registration in place.');
			}
			$existing = $this->findRegisteredBotViaTalkMapper($this->getStoredBotUrl() ?: $this->getWebhookUrl());
			$id = $this->extractBotId($existing);
			if ($id === null) {
				return ['status' => 'skipped', 'message' => 'Branding saved. No existing managed Talk registration found; new registrations will use this name.'];
			}
			$bot = $mapper->findById($id);
			if ($bot->getName() !== $this->brandingService->getDisplayName()) {
				$bot->setName($this->brandingService->getDisplayName());
				$mapper->update($bot);
			}
			return ['status' => 'updated', 'message' => 'Updated the managed Talk display name in place; existing room activations are unchanged.'];
		} catch (\Throwable $e) {
			$this->logger->warning('EducAI: Failed to update the managed Talk display name', ['exception' => $e]);
			return ['status' => 'error', 'message' => 'Branding saved, but the managed Talk name could not be updated. Check the Nextcloud log and rerun this command after resolving the problem.'];
		}
	}

	/**
	 * @return array{status:string,message:string,bot_id?:int|null}
	 */
	public function syncRegistration(?string $secret = null, bool $forceRefresh = false, bool $generateSecretIfMissing = false): array {
		try {
			$resolvedSecret = $this->resolveWebhookSecret($secret);
			if ($resolvedSecret === '' && $generateSecretIfMissing) {
				$resolvedSecret = $this->generateAndStoreWebhookSecret();
			}

			if (!$this->isTalkAvailable()) {
				return [
					'status' => 'skipped',
					'message' => 'Nextcloud Talk is not enabled. Skipping managed Talk bot registration.',
				];
			}

			if ($resolvedSecret === '') {
				return [
					'status' => 'skipped',
					'message' => 'Webhook secret is not configured yet. Managed Talk bot registration is deferred.',
				];
			}

			$webhookUrl = $this->getWebhookUrl();

			if (\OC::$CLI) {
				return $this->syncViaOcc($resolvedSecret, $webhookUrl, $forceRefresh);
			}

			return $this->syncViaOcs($resolvedSecret, $webhookUrl, $forceRefresh);
		} catch (\Throwable $e) {
			$this->logger->error('EducAI: Failed to sync Talk bot registration', [
				'exception' => $e,
			]);

			return [
				'status' => 'error',
				'message' => 'Failed to sync managed Talk bot registration: ' . $e->getMessage(),
			];
		}
	}

	/**
	 * @return array{status:string,message:string}
	 */
	public function unregister(): array {
		if (!$this->isTalkAvailable()) {
			$this->clearStoredBotMetadata();
			return [
				'status' => 'skipped',
				'message' => 'Nextcloud Talk is not enabled. Cleared stored managed Talk bot metadata only.',
			];
		}

		try {
			if (\OC::$CLI) {
				return $this->unregisterViaOcc();
			}

			return $this->unregisterViaOcs();
		} catch (\Throwable $e) {
			$this->logger->error('EducAI: Failed to unregister Talk bot', [
				'exception' => $e,
			]);

			return [
				'status' => 'error',
				'message' => 'Failed to unregister managed Talk bot: ' . $e->getMessage(),
			];
		}
	}

	public function isTalkAvailable(): bool {
		try {
			return (bool)$this->appManager->isInstalled(self::TALK_APP_ID);
		} catch (\Throwable $e) {
			$this->logger->debug('EducAI: Failed to check Talk availability', [
				'exception' => $e->getMessage(),
			]);
			return false;
		}
	}

	private function resolveWebhookSecret(?string $secret): string {
		$normalized = trim((string)$secret);
		if ($normalized !== '') {
			return $normalized;
		}

		try {
			$encrypted = $this->settingsMapper->getSettings()->getWebhookSecret();
			if ($encrypted === null || trim($encrypted) === '') {
				return '';
			}

			return trim($this->credentialService->decrypt($encrypted));
		} catch (\Throwable $e) {
			$this->logger->warning('EducAI: Failed to resolve webhook secret for Talk registration', [
				'exception' => $e->getMessage(),
			]);
			return '';
		}
	}

	private function generateAndStoreWebhookSecret(): string {
		$secret = bin2hex(random_bytes(32));
		$settings = $this->settingsMapper->getSettings();
		$settings->setWebhookSecret($this->credentialService->encrypt($secret));
		$settings->setUpdatedAt(time());
		$this->settingsMapper->update($settings);

		return $secret;
	}

	private function getWebhookUrl(): string {
		return $this->urlGenerator->linkToRouteAbsolute('educai.webhook.talk');
	}

	/**
	 * @return array{status:string,message:string,bot_id?:int|null}
	 */
	private function syncViaOcc(string $secret, string $webhookUrl, bool $forceRefresh): array {
		$existingBot = $this->findRegisteredBotViaTalkMapper($webhookUrl);
		if ($existingBot === null) {
			$bots = $this->listBotsViaOcc();
			$existingBot = $this->findRegisteredBot($bots, $webhookUrl);
		}

		if ($existingBot !== null) {
			$botId = $this->extractBotId($existingBot);
			$updatedInPlace = $botId !== null && $this->updateExistingBotInPlace($botId, $secret, $webhookUrl);
			if (!$updatedInPlace && $botId !== null && ($existingBot['state'] ?? 1) !== 1) {
				$this->setBotStateViaOcc($botId);
			}

			if ($botId !== null) {
				$this->storeBotMetadata($botId, $webhookUrl);
			}

			return [
				'status' => $updatedInPlace ? 'updated' : ($forceRefresh ? 'refreshed' : 'reused'),
				'message' => $updatedInPlace
					? 'Updated existing ' . $this->brandingService->getDisplayName() . ' Talk bot registration in place.'
					: ($forceRefresh
						? 'Reused existing ' . $this->brandingService->getDisplayName() . ' Talk bot registration without reinstalling it.'
						: $this->brandingService->getDisplayName() . ' bot is already registered in Talk.'),
				'bot_id' => $botId,
			];
		}

		$installedBot = $this->installBotViaOcc($secret, $webhookUrl);

		$botId = $this->extractBotId($installedBot);
		if ($botId === null) {
			$installedBot = $this->findRegisteredBot($this->listBotsViaOcc(), $webhookUrl);
			$botId = $this->extractBotId($installedBot);
		}

		if ($botId === null) {
			return [
				'status' => 'error',
				'message' => 'Talk bot installation finished without a detectable bot ID.',
			];
		}

		if ($botId !== null) {
			$this->storeBotMetadata($botId, $webhookUrl);
		}

		return [
			'status' => 'registered',
			'message' => 'Registered the managed bot with Nextcloud Talk.',
			'bot_id' => $botId,
		];
	}

	/**
	 * @return array{status:string,message:string,bot_id?:int|null}
	 */
	private function syncViaOcs(string $secret, string $webhookUrl, bool $forceRefresh): array {
		if (!$this->hasOcsSessionContext()) {
			return [
				'status' => 'skipped',
				'message' => 'Skipped managed Talk bot registration because the current request has no usable Talk admin session.',
			];
		}

		$existingBot = $this->findRegisteredBotViaTalkMapper($webhookUrl);
		if ($existingBot === null) {
			$bots = $this->listBotsViaOcs();
			$existingBot = $this->findRegisteredBot($bots, $webhookUrl);
		}

		if ($existingBot !== null) {
			$botId = $this->extractBotId($existingBot);
			$updatedInPlace = $botId !== null && $this->updateExistingBotInPlace($botId, $secret, $webhookUrl);
			if ($botId !== null) {
				$this->storeBotMetadata($botId, $webhookUrl);
			}

			return [
				'status' => $updatedInPlace ? 'updated' : ($forceRefresh ? 'refreshed' : 'reused'),
				'message' => $updatedInPlace
					? 'Updated existing ' . $this->brandingService->getDisplayName() . ' Talk bot registration in place.'
					: ($forceRefresh
						? 'Reused existing ' . $this->brandingService->getDisplayName() . ' Talk bot registration without reinstalling it.'
						: $this->brandingService->getDisplayName() . ' bot is already registered in Talk.'),
				'bot_id' => $botId,
			];
		}

		if ($existingBot === null) {
			$createdBot = $this->createBotInPlace($secret, $webhookUrl)
				?? $this->createBotViaOcs($secret, $webhookUrl);
			$botId = $this->extractBotId($createdBot);

			if ($botId === null) {
				$createdBot = $this->findRegisteredBot($this->listBotsViaOcs(), $webhookUrl);
				$botId = $this->extractBotId($createdBot);
			}

			if ($botId === null) {
				return [
					'status' => 'error',
					'message' => 'Talk bot registration request completed without a detectable bot ID.',
				];
			}

			$this->storeBotMetadata($botId, $webhookUrl);

			return [
				'status' => 'registered',
				'message' => 'Registered the managed bot with Nextcloud Talk.',
				'bot_id' => $botId,
			];
		}

		return [
			'status' => 'skipped',
			'message' => 'Skipped managed Talk bot registration because the current request has no usable Talk admin session.',
		];
	}

	/**
	 * @return array{status:string,message:string}
	 */
	private function unregisterViaOcc(): array {
		$botId = $this->getStoredBotId();
		$webhookUrl = $this->getStoredBotUrl();

		if ($botId === null && $webhookUrl === '') {
			$this->clearStoredBotMetadata();
			return [
				'status' => 'skipped',
				'message' => 'No stored managed Talk bot metadata found.',
			];
		}

		$this->uninstallBotViaOcc($botId, $webhookUrl !== '' ? $webhookUrl : $this->getWebhookUrl());
		$this->clearStoredBotMetadata();

		return [
			'status' => 'unregistered',
			'message' => 'Unregistered managed Talk bot from Nextcloud Talk.',
		];
	}

	/**
	 * @return array{status:string,message:string}
	 */
	private function unregisterViaOcs(): array {
		if (!$this->hasOcsSessionContext()) {
			$this->clearStoredBotMetadata();
			return [
				'status' => 'skipped',
				'message' => 'Skipped managed Talk bot removal because the current request has no usable Talk admin session.',
			];
		}

		$bots = $this->listBotsViaOcs();
		$webhookUrl = $this->getStoredBotUrl();
		$existingBot = $this->findRegisteredBot($bots, $webhookUrl !== '' ? $webhookUrl : $this->getWebhookUrl());
		$botId = $this->extractBotId($existingBot);

		if ($botId === null) {
			$this->clearStoredBotMetadata();
			return [
				'status' => 'skipped',
				'message' => 'No managed Talk bot registration found to remove.',
			];
		}

		$this->deleteBotViaOcs($botId);
		$this->clearStoredBotMetadata();

		return [
			'status' => 'unregistered',
			'message' => 'Unregistered managed Talk bot from Nextcloud Talk.',
		];
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function listBotsViaOcc(): array {
		$output = $this->runOccCommand([
			'command' => 'talk:bot:list',
			'--output' => 'json',
		]);

		return $this->extractBotList($this->decodeJsonOutput($output));
	}

	/**
	 * @return array<string,mixed>
	 */
	private function installBotViaOcc(string $secret, string $webhookUrl): array {
		$output = $this->runOccCommand([
			'command' => 'talk:bot:install',
			'--output' => 'json',
			'--feature' => ['webhook', 'response'],
			'name' => $this->brandingService->getDisplayName(),
			'secret' => $secret,
			'url' => $webhookUrl,
			'description' => self::BOT_DESCRIPTION,
		]);

		return $this->extractBotPayload($this->decodeJsonOutput($output));
	}

	private function uninstallBotViaOcc(?int $botId, string $webhookUrl): void {
		$arguments = [
			'command' => 'talk:bot:uninstall',
			'--output' => 'json',
		];

		if ($botId !== null) {
			$arguments['id'] = (string)$botId;
		} else {
			$arguments['--url'] = $webhookUrl;
		}

		$this->runOccCommand($arguments);
	}

	private function setBotStateViaOcc(int $botId): void {
		$this->runOccCommand([
			'command' => 'talk:bot:state',
			'--output' => 'json',
			'--feature' => ['webhook', 'response'],
			'bot-id' => (string)$botId,
			'state' => '1',
		]);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function findRegisteredBotViaTalkMapper(string $webhookUrl): ?array {
		$mapper = $this->getTalkBotServerMapper();
		if ($mapper === null) {
			return null;
		}

		$storedBotId = $this->getStoredBotId();
		if ($storedBotId !== null && method_exists($mapper, 'findById')) {
			try {
				$bot = $mapper->findById($storedBotId);
				return is_object($bot) && method_exists($bot, 'jsonSerialize') ? $bot->jsonSerialize() : null;
			} catch (\Throwable) {
			}
		}

		if (method_exists($mapper, 'findByUrl')) {
			try {
				$bot = $mapper->findByUrl($webhookUrl);
				return is_object($bot) && method_exists($bot, 'jsonSerialize') ? $bot->jsonSerialize() : null;
			} catch (\Throwable) {
			}
		}

		return null;
	}

	private function updateExistingBotInPlace(int $botId, string $secret, string $webhookUrl): bool {
		try {
			if (!$this->hasValidTalkBotParameters($secret, $webhookUrl)) {
				return false;
			}

			$mapper = $this->getTalkBotServerMapper();
			if ($mapper === null || !method_exists($mapper, 'findById') || !method_exists($mapper, 'update')) {
				return false;
			}

			$bot = $mapper->findById($botId);
			$changed = false;

			if ((string)$bot->getName() !== $this->brandingService->getDisplayName()) {
				$bot->setName($this->brandingService->getDisplayName());
				$changed = true;
			}

			if ((string)$bot->getDescription() !== self::BOT_DESCRIPTION) {
				$bot->setDescription(self::BOT_DESCRIPTION);
				$changed = true;
			}

			if ((string)$bot->getSecret() !== $secret) {
				$bot->setSecret($secret);
				$changed = true;
			}

			if ((string)$bot->getUrl() !== $webhookUrl) {
				$bot->setUrl($webhookUrl);
				$bot->setUrlHash(sha1($webhookUrl));
				$changed = true;
			}

			if ((int)$bot->getState() !== 1) {
				$bot->setState(1);
				$changed = true;
			}

			if ((int)$bot->getFeatures() !== self::BOT_FEATURES) {
				$bot->setFeatures(self::BOT_FEATURES);
				$changed = true;
			}

			if (!$changed) {
				return false;
			}

			$mapper->update($bot);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('EducAI: Failed to update Talk bot registration in place', [
				'exception' => $e,
				'bot_id' => $botId,
			]);

			return false;
		}
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function createBotInPlace(string $secret, string $webhookUrl): ?array {
		try {
			if (!$this->hasValidTalkBotParameters($secret, $webhookUrl)) {
				return null;
			}

			$mapper = $this->getTalkBotServerMapper();
			$botClass = 'OCA\\Talk\\Model\\BotServer';
			if ($mapper === null || !method_exists($mapper, 'insert') || !class_exists($botClass)) {
				return null;
			}

			$bot = new $botClass();
			$bot->setName($this->brandingService->getDisplayName());
			$bot->setDescription(self::BOT_DESCRIPTION);
			$bot->setSecret($secret);
			$bot->setUrl($webhookUrl);
			$bot->setUrlHash(sha1($webhookUrl));
			$bot->setState(1);
			$bot->setFeatures(self::BOT_FEATURES);

			$createdBot = $mapper->insert($bot);
			return is_object($createdBot) && method_exists($createdBot, 'jsonSerialize') ? $createdBot->jsonSerialize() : null;
		} catch (\Throwable $e) {
			$this->logger->warning('EducAI: Failed to create Talk bot registration in place', [
				'exception' => $e,
			]);

			return null;
		}
	}

	private function hasValidTalkBotParameters(string $secret, string $webhookUrl): bool {
		$secretLength = strlen($secret);

		return strlen($this->brandingService->getDisplayName()) > 0
			&& strlen($this->brandingService->getDisplayName()) <= 64
			&& $secretLength >= 40
			&& $secretLength <= 128
			&& strlen($webhookUrl) <= 4000
			&& (str_starts_with($webhookUrl, 'http://') || str_starts_with($webhookUrl, 'https://'))
			&& strlen(self::BOT_DESCRIPTION) <= 4000;
	}

	protected function getTalkBotServerMapper(): ?object {
		$mapperClass = 'OCA\\Talk\\Model\\BotServerMapper';

		try {
			if (class_exists('\OC_App')) {
				\OC_App::loadApp(self::TALK_APP_ID);
			}

			if (!class_exists($mapperClass)) {
				return null;
			}

			$mapper = \OCP\Server::get($mapperClass);
			return is_object($mapper) ? $mapper : null;
		} catch (\Throwable $e) {
			$this->logger->debug('EducAI: Failed to access Talk bot mapper', [
				'exception' => $e,
			]);

			return null;
		}
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function listBotsViaOcs(): array {
		if (!$this->hasOcsSessionContext()) {
			return [];
		}

		$client = $this->clientService->newClient();
		$response = $client->get($this->getTalkAdminEndpoint(), [
			'headers' => $this->getOcsHeaders(),
			'cookies' => $this->getForwardedCookies(),
			'query' => ['format' => 'json'],
		]);
		if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
			throw new \RuntimeException('Failed to list Talk bots via OCS: HTTP ' . $response->getStatusCode());
		}

		return $this->extractBotList($this->decodeJsonOutput((string)$response->getBody()));
	}

	/**
	 * @return array<string,mixed>
	 */
	private function createBotViaOcs(string $secret, string $webhookUrl): array {
		if (!$this->hasOcsSessionContext()) {
			return [];
		}

		$client = $this->clientService->newClient();
		$response = $client->post($this->getTalkAdminEndpoint(), [
			'headers' => $this->getOcsHeaders(true),
			'cookies' => $this->getForwardedCookies(),
			'query' => ['format' => 'json'],
			'json' => [
				'name' => $this->brandingService->getDisplayName(),
				'secret' => $secret,
				'url' => $webhookUrl,
				'description' => self::BOT_DESCRIPTION,
				'features' => self::BOT_FEATURES,
			],
		]);
		if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
			throw new \RuntimeException('Failed to create Talk bot via OCS: HTTP ' . $response->getStatusCode());
		}

		return $this->extractBotPayload($this->decodeJsonOutput((string)$response->getBody()));
	}

	private function deleteBotViaOcs(int $botId): void {
		if (!$this->hasOcsSessionContext()) {
			return;
		}

		$client = $this->clientService->newClient();
		$response = $client->delete($this->getTalkAdminEndpoint() . '/' . $botId, [
			'headers' => $this->getOcsHeaders(),
			'cookies' => $this->getForwardedCookies(),
			'query' => ['format' => 'json'],
		]);
		if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
			throw new \RuntimeException('Failed to delete Talk bot via OCS: HTTP ' . $response->getStatusCode());
		}
	}

	private function getTalkAdminEndpoint(): string {
		return $this->urlGenerator->getAbsoluteURL('') . 'ocs/v2.php/apps/spreed/api/v1/bot/admin';
	}

	private function hasOcsSessionContext(): bool {
		if (\OC::$CLI) {
			return false;
		}

		$authorization = trim((string)$this->request->getHeader('Authorization'));
		return $authorization !== '' || count($_COOKIE) > 0;
	}

	/**
	 * @return array<string,string>
	 */
	private function getOcsHeaders(bool $withJsonBody = false): array {
		$headers = [
			'OCS-APIRequest' => 'true',
			'Accept' => 'application/json',
		];

		if ($withJsonBody) {
			$headers['Content-Type'] = 'application/json';
		}

		$authorization = trim((string)$this->request->getHeader('Authorization'));
		if ($authorization !== '') {
			$headers['Authorization'] = $authorization;
		}

		return $headers;
	}

	private function getForwardedCookies(): \GuzzleHttp\Cookie\CookieJar {
		$jar = new \GuzzleHttp\Cookie\CookieJar();
		$host = $this->request->getServerHost();

		foreach ($_COOKIE as $name => $value) {
			$jar->setCookie(new \GuzzleHttp\Cookie\SetCookie([
				'Name' => $name,
				'Value' => $value,
				'Domain' => $host,
				'Path' => '/',
			]));
		}

		return $jar;
	}

	private function runOccCommand(array $arguments): string {
		$application = \OCP\Server::get(ConsoleApplication::class);

		if (!$this->consoleCommandsLoaded) {
			// Only pass a minimal bootstrap input when loading commands.
			// Newer Nextcloud/Symfony combinations can reject command-specific options
			// here before the target command definition is available.
			$application->loadCommands(new ArrayInput(['command' => 'list']), new ConsoleOutput());
			$application->setAutoExit(false);
			$this->consoleCommandsLoaded = true;
		}

		$input = new ArrayInput($arguments);
		$output = new BufferedOutput();
		$exitCode = $application->run($input, $output);
		$content = trim($output->fetch());

		if ($exitCode !== 0) {
			throw new \RuntimeException($content !== '' ? $content : 'Talk OCC command failed with exit code ' . $exitCode);
		}

		return $content;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function decodeJsonOutput(string $output): array {
		$trimmed = trim($output);
		if ($trimmed === '') {
			return [];
		}

		$decoded = json_decode($trimmed, true);
		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * @param array<string,mixed> $decoded
	 * @return array<int,array<string,mixed>>
	 */
	private function extractBotList(array $decoded): array {
		if (array_is_list($decoded)) {
			return $decoded;
		}

		foreach (['bots', 'data'] as $key) {
			if (isset($decoded[$key]) && is_array($decoded[$key])) {
				$value = $decoded[$key];
				if (array_is_list($value)) {
					return $value;
				}
			}
		}

		if (isset($decoded['ocs']['data']) && is_array($decoded['ocs']['data'])) {
			$value = $decoded['ocs']['data'];
			return array_is_list($value) ? $value : [];
		}

		return [];
	}

	/**
	 * @param array<string,mixed> $decoded
	 * @return array<string,mixed>
	 */
	private function extractBotPayload(array $decoded): array {
		foreach (['bot', 'data'] as $key) {
			if (isset($decoded[$key]) && is_array($decoded[$key])) {
				return $decoded[$key];
			}
		}

		if (isset($decoded['ocs']['data']) && is_array($decoded['ocs']['data'])) {
			return $decoded['ocs']['data'];
		}

		return $decoded;
	}

	/**
	 * @param array<int,array<string,mixed>> $bots
	 * @return array<string,mixed>|null
	 */
	private function findRegisteredBot(array $bots, string $webhookUrl): ?array {
		$storedBotId = $this->getStoredBotId();

		foreach ($bots as $bot) {
			if (!is_array($bot)) {
				continue;
			}

			$botId = $this->extractBotId($bot);
			if ($storedBotId !== null && $botId === $storedBotId) {
				return $bot;
			}

			if (($bot['url'] ?? '') === $webhookUrl) {
				return $bot;
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed>|null $bot
	 */
	private function extractBotId(?array $bot): ?int {
		if ($bot === null) {
			return null;
		}

		$id = $bot['id'] ?? $bot['bot_id'] ?? null;
		if ($id === null || !is_numeric((string)$id)) {
			return null;
		}

		return (int)$id;
	}

	private function storeBotMetadata(int $botId, string $webhookUrl): void {
		$this->config->setAppValue(Application::APP_ID, self::CONFIG_BOT_ID, (string)$botId);
		$this->config->setAppValue(Application::APP_ID, self::CONFIG_BOT_URL, $webhookUrl);
	}

	private function clearStoredBotMetadata(): void {
		$this->config->deleteAppValue(Application::APP_ID, self::CONFIG_BOT_ID);
		$this->config->deleteAppValue(Application::APP_ID, self::CONFIG_BOT_URL);
	}

	private function getStoredBotId(): ?int {
		$raw = $this->config->getAppValue(Application::APP_ID, self::CONFIG_BOT_ID, '');
		if ($raw === '' || !is_numeric($raw)) {
			return null;
		}

		return (int)$raw;
	}

	private function getStoredBotUrl(): string {
		return trim($this->config->getAppValue(Application::APP_ID, self::CONFIG_BOT_URL, ''));
	}

}
