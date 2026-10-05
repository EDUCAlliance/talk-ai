<?php

declare(strict_types=1);

use OCA\EducAI\Db\ChatRoom;
use OCA\EducAI\Db\ChatRoomMapper;
use OCA\EducAI\Db\Conversation;
use OCA\EducAI\Db\ConversationMapper;
use OCA\EducAI\Db\SettingsMapper;
use OCA\EducAI\Service\BotService;
use OCA\EducAI\Service\CredentialService;
use OCA\EducAI\Service\SettingsService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;

if (PHP_SAPI !== 'cli' || !is_file('/.dockerenv') || getenv('EDUCAI_TEST_ALLOW_MUTATION') !== '1') {
	fwrite(STDERR, "This mutating test requires a disposable Docker instance and EDUCAI_TEST_ALLOW_MUTATION=1.\n");
	exit(2);
}
$uid = getenv('EDUCAI_TEST_USER') ?: '';
$room = getenv('EDUCAI_TEST_ROOM') ?: '';
$origin = getenv('EDUCAI_TEST_FIXTURE_ORIGIN') ?: 'http://127.0.0.1:18096';
$base = getenv('EDUCAI_TEST_BASE_URL') ?: 'http://127.0.0.1';
if ($uid === '' || $room === '' || preg_match('#^http://127\.0\.0\.1:[0-9]+$#', $origin) !== 1
	|| preg_match('#^http://127\.0\.0\.1(?::[0-9]+)?$#', $base) !== 1) {
	fwrite(STDERR, "Set EDUCAI_TEST_USER and EDUCAI_TEST_ROOM; both HTTP origins must be loopback.\n");
	exit(2);
}
define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';
OC_App::loadApp('educai');
$container = OC::$server->getRegisteredAppContainer('educai');
$get = static fn (string $class) => $container->get($class);
$db = $get(IDBConnection::class);
$config = $get(IConfig::class);
$settingsMapper = $get(SettingsMapper::class);
$conversations = $get(ConversationMapper::class);
$botService = $get(BotService::class);
$settingsService = $get(SettingsService::class);
$http = static function (string $url, ?string $body = null, array $headers = []): array {
	$ch = curl_init($url);
	curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Expect:'], $headers)]);
	if ($body !== null) {
		curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body]);
	}
	$response = curl_exec($ch);
	$result = ['status' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => (string)$response, 'seconds' => round(curl_getinfo($ch, CURLINFO_TOTAL_TIME), 3)];
	$error = curl_errno($ch);
	curl_close($ch);
	if ($error !== 0) {
		throw new RuntimeException('Local HTTP request failed (curl code ' . $error . ')');
	}
	return $result;
};
$fixture = static function (string $path, ?array $body = null) use ($http, $origin): array {
	$result = $http($origin . $path, $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR));
	if ($result['status'] !== 200) {
		throw new RuntimeException('Local synthetic fixture is not ready');
	}
	return json_decode($result['body'], true, 512, JSON_THROW_ON_ERROR);
};
// Fail before mutation unless the dedicated room, user, signing setup and fixture exist.
$roomId = (int)$db->executeQuery('SELECT id FROM *PREFIX*talk_rooms WHERE token = ?', [$room])->fetchOne();
if ($roomId === 0 || !$get(IUserManager::class)->userExists($uid) || $settingsService->getWebhookSecret() === '') {
	fwrite(STDERR, "A disposable Talk room, user and registered Talk AI webhook are required.\n");
	exit(2);
}
$fixture('/stats');
$original = $settingsMapper->getSettings();
$fields = ['ApiProvider', 'ApiEndpoint', 'ApiKey', 'DefaultModel', 'RateLimitEnabled', 'DoclingEnabled', 'RagEnabled', 'CatalogueEnabled', 'ConversationContextTokens'];
$saved = [];
foreach ($fields as $field) {
	$saved[$field] = $original->{'get' . $field}();
}
$allowLocal = $config->getSystemValue('allow_local_remote_servers', '__SMOKE_UNSET__');
$bot = null;
$commentIds = [];
$checks = [];
$metrics = [];
$cleanupErrors = [];
$check = static function (bool $passed, string $label) use (&$checks): void {
	$checks[] = ['check' => $label, 'passed' => $passed];
};
$failure = null;

try {
	$config->setSystemValue('allow_local_remote_servers', true);
	$settings = $settingsMapper->getSettings();
	$settings->setApiProvider('custom');
	$settings->setApiEndpoint($origin . '/v1/chat/completions');
	$settings->setApiKey($get(CredentialService::class)->encrypt('synthetic-fixture-not-a-credential'));
	$settings->setDefaultModel('primary:fixture-context');
	$settings->setRateLimitEnabled(false);
	$settings->setDoclingEnabled(false);
	$settings->setRagEnabled(false);
	$settings->setCatalogueEnabled(false);
	$settings->setConversationContextTokens(8000);
	$settingsMapper->update($settings);
	$mention = 'context-smoke-' . bin2hex(random_bytes(6));
	$systemPrompt = 'Reply with the synthetic fixture answer.';
	$bot = $botService->createBot($uid, 'Synthetic context probe', $mention, $systemPrompt, false, 'primary:fixture-context', null, 'personal');
	$chatRoom = new ChatRoom();
	$chatRoom->setBotId($bot->getId());
	$chatRoom->setRoomToken($room);
	$chatRoom->setResponseMode('mention');
	$chatRoom->setOnboardingStatus('completed');
	$chatRoom->setActivatedBy($uid);
	$chatRoom->setCreatedAt(time());
	$chatRoom->setUpdatedAt(time());
	$get(ChatRoomMapper::class)->insert($chatRoom);
	$payload = static function (string $message) use ($uid, $room, $mention): array {
		return ['type' => 'Create', 'actor' => ['id' => $uid, 'type' => 'Person'], 'object' => ['id' => 0, 'name' => 'chat', 'content' => json_encode(['message' => '@' . $mention . ' ' . $message], JSON_THROW_ON_ERROR)], 'target' => ['id' => $room, 'type' => 'Collection']];
	};
	$encode = static fn (array $value): string => json_encode($value, JSON_THROW_ON_ERROR);
	$probe = static function (string $label, string $body, array $options = [], bool $validSignature = true, bool $keepHistory = false, bool $chunked = false, bool $includeSignature = true) use ($fixture, $http, $db, $base, $roomId, $bot, $settingsService, $conversations, &$commentIds, &$metrics): array {
		if (!$keepHistory) {
			$conversations->deleteByBot($bot->getId());
		}
		$fixture('/control', $options + ['output_chars' => 32, 'max_chars' => 32768]);
		$lastId = (int)$db->executeQuery('SELECT COALESCE(MAX(id), 0) FROM *PREFIX*comments')->fetchOne();
		$nonce = bin2hex(random_bytes(32));
		$signature = $validSignature ? hash_hmac('sha256', $nonce . $body, $settingsService->getWebhookSecret()) : str_repeat('0', 64);
		$headers = $includeSignature ? ['X-Nextcloud-Talk-Random: ' . $nonce, 'X-Nextcloud-Talk-Signature: ' . $signature] : [];
		if ($chunked) {
			$headers[] = 'Transfer-Encoding: chunked';
			$headers[] = 'Content-Length:';
		}
		$response = $http($base . '/index.php/apps/educai/webhook/talk', $body, $headers);
		$rows = $db->executeQuery("SELECT id, message FROM *PREFIX*comments WHERE id > ? AND object_type = 'chat' AND object_id = ? AND actor_type = 'bots' ORDER BY id", [$lastId, (string)$roomId])->fetchAllAssociative();
		foreach ($rows as $row) {
			$commentIds[] = (int)$row['id'];
		}
		$history = $db->executeQuery('SELECT role, content FROM *PREFIX*educai_conversations WHERE bot_id = ? ORDER BY id', [$bot->getId()])->fetchAllAssociative();
		$stats = $fixture('/stats')['requests'];
		$metrics[] = ['case' => $label, 'body_bytes' => strlen($body), 'http_status' => $response['status'], 'seconds' => $response['seconds'], 'provider_requests' => $stats, 'talk_chars' => array_map(static fn (array $r): int => mb_strlen($r['message'], 'UTF-8'), $rows), 'history' => array_map(static fn (array $r): array => ['role' => $r['role'], 'chars' => mb_strlen($r['content'], 'UTF-8')], $history)];
		return ['status' => $response['status'], 'requests' => $stats, 'comments' => array_column($rows, 'message'), 'history' => $history];
	};
	$currentMessage = 'A short synthetic request.';
	$body = $encode($payload($currentMessage));
	$r = $probe('normal', $body);
	$check($r['status'] === 200 && count($r['requests']) === 1 && $r['comments'] === [str_repeat('x', 32)], 'Normal signed webhook reaches the LLM and persists its Talk response');
	foreach (['root true' => 'true', 'root null' => 'null', 'root list' => '[]', 'scalar object' => '{"object":true}', 'invalid JSON' => '{'] as $label => $invalid) {
		$r = $probe($label, $invalid);
		$check($r['status'] === 400 && $r['requests'] === [] && $r['comments'] === [] && $r['history'] === [], $label . ' is rejected as HTTP 400 without model calls or history');
	}
	$r = $probe('invalid signature', $body, [], false);
	$check($r['status'] === 401 && $r['requests'] === [] && $r['comments'] === [] && $r['history'] === [], 'Invalid HMAC returns HTTP 401 without processing');
	$r = $probe('missing signature', $body, includeSignature: false);
	$check($r['status'] === 401 && $r['requests'] === [] && $r['comments'] === [] && $r['history'] === [], 'Missing HMAC headers return HTTP 401 without processing');
	foreach ([1048575, 1048576, 1048577] as $bytes) {
		// Padding is JSON whitespace, not extra model context: isolates the transport limit.
		$r = $probe('body ' . $bytes . ' bytes', $body . str_repeat(' ', $bytes - strlen($body)));
		$accepted = $bytes <= 1048576;
		$check($r['status'] === ($accepted ? 200 : 413) && count($r['requests']) === ($accepted ? 1 : 0)
			&& ($accepted ? $r['comments'] === [str_repeat('x', 32)] : $r['comments'] === [] && $r['history'] === []), 'Signed ' . $bytes . '-byte body respects the inclusive 1 MiB boundary');
	}
	$r = $probe('chunked body without Content-Length', $body . str_repeat(' ', 1048577 - strlen($body)), [], true, false, true);
	$check($r['status'] === 413 && $r['requests'] === [] && $r['comments'] === [] && $r['history'] === [], 'Oversized chunked request is bounded even without Content-Length');
	$quoted = $payload(str_repeat('🧪', 32000 - mb_strlen('@' . $mention . ' ', 'UTF-8')));
	$quoted['object']['inReplyTo'] = ['object' => ['id' => 0, 'name' => 'chat', 'content' => json_encode(['message' => str_repeat('🧪', 32000)], JSON_THROW_ON_ERROR)]];
	$quotedBody = $encode($quoted);
	$r = $probe('32000 Unicode current + quoted parent', $quotedBody, ['max_chars' => 100000]);
	$check(strlen($quotedBody) < 1048576 && $r['status'] === 200 && count($r['requests']) === 1 && count($r['comments']) === 1, 'Two maximum-length Unicode Talk messages fit even with both JSON escaping layers');
	$r = $probe('model context overflow', $encode($payload(str_repeat('a', 65536))));
	$assistant = array_values(array_filter($r['history'], static fn (array $h): bool => $h['role'] === 'assistant'));
	$check($r['status'] === 200 && count($r['requests']) === 1 && count($r['comments']) === 1
		&& str_contains($r['comments'][0], "model's context window") && $assistant === [], 'Model context overflow calls once, reports the context limit and never stores an assistant error');
	$conversations->deleteByBot($bot->getId());
	for ($i = 0; $i < 50; $i++) {
		$history = new Conversation();
		$history->setBotId($bot->getId());
		$history->setRoomToken($room);
		$history->setUserId($uid);
		$history->setRole($i % 2 === 0 ? 'user' : 'assistant');
		$history->setContent(str_repeat('h', 32000));
		$history->setThreadRootMessageId(null);
		$history->setCreatedAt(time() - 100 + $i);
		$conversations->insert($history);
	}
	$shortHistory = new Conversation();
	$shortHistory->setBotId($bot->getId());
	$shortHistory->setRoomToken($room);
	$shortHistory->setUserId($uid);
	$shortHistory->setRole('assistant');
	$shortHistory->setContent(str_repeat('s', 100));
	$shortHistory->setThreadRootMessageId(null);
	$shortHistory->setCreatedAt(time() - 1);
	$conversations->insert($shortHistory);
	$r = $probe('50 large history rows', $body, [], true, true);
	// A leading historical assistant turn receives the existing user compatibility
	// placeholder. Count it as well, so this check cannot pass when history vanishes.
	$expectedContextChars = mb_strlen($systemPrompt . '(continued)' . $shortHistory->getContent() . $currentMessage, 'UTF-8');
	$check($r['status'] === 200 && count($r['requests']) === 1 && $r['requests'][0]['content_chars'] === $expectedContextChars && $r['requests'][0]['messages'] === 4
		&& count($r['comments']) === 1 && count($r['history']) === 53, 'History budget keeps the short recent turn and excludes fifty 32000-character rows without deleting stored history');
	foreach ([32000, 32001, 64005] as $chars) {
		$r = $probe('Unicode answer ' . $chars, $body, ['output_chars' => $chars, 'unicode' => true]);
		$expected = str_repeat('🧪', $chars);
		$assistant = array_values(array_filter($r['history'], static fn (array $h): bool => $h['role'] === 'assistant'));
		$check($r['status'] === 200 && count($r['requests']) === 1 && count($r['comments']) === (int)ceil($chars / 32000)
			&& implode('', $r['comments']) === $expected && count($assistant) === 1 && $assistant[0]['content'] === $expected
			&& count(array_filter($r['comments'], static fn (string $m): bool => mb_strlen($m, 'UTF-8') > 32000)) === 0, $chars . '-character Unicode answer is fully persisted in history and correctly sized Talk chunks');
	}
} catch (Throwable $e) {
	// Do not print exception bodies: an HTTP library might include headers/payloads.
	$failure = get_class($e);
} finally {
	$cleanup = static function (string $label, callable $action) use (&$cleanupErrors): void {
		try {
			$action();
		} catch (Throwable $e) {
			$cleanupErrors[] = $label . ' (' . get_class($e) . ')';
		}
	};
	if ($bot !== null) {
		$cleanup('Synthetic traces', static function () use ($db, $bot): void {
			$runIds = $db->executeQuery('SELECT id FROM *PREFIX*educai_trace_runs WHERE bot_id = ?', [$bot->getId()])->fetchFirstColumn();
			foreach ($runIds as $id) {
				$db->executeStatement('DELETE FROM *PREFIX*educai_trace_events WHERE run_id = ?', [(int)$id]);
			}
			$db->executeStatement('DELETE FROM *PREFIX*educai_trace_runs WHERE bot_id = ?', [$bot->getId()]);
		});
		$cleanup('Synthetic queue', static fn () => $db->executeStatement('DELETE FROM *PREFIX*educai_queue WHERE bot_id = ?', [$bot->getId()]));
		$cleanup('Synthetic bot and conversations', static fn () => $botService->deleteBot($bot->getId(), $uid));
	}
	// Only comments observed during these requests in the dedicated room are removed.
	foreach ($commentIds as $id) {
		$cleanup('Synthetic Talk comment', static fn () => $db->executeStatement("DELETE FROM *PREFIX*comments WHERE id = ? AND object_type = 'chat' AND object_id = ? AND actor_type = 'bots'", [$id, (string)$roomId]));
	}
	$cleanup('Settings', static function () use ($settingsMapper, $saved): void {
		$settings = $settingsMapper->getSettings();
		foreach ($saved as $field => $value) {
			$settings->{'set' . $field}($value);
		}
		$settingsMapper->update($settings);
	});
	$cleanup('Local HTTP policy', static function () use ($config, $allowLocal): void {
		if ($allowLocal === '__SMOKE_UNSET__') {
			$config->deleteSystemValue('allow_local_remote_servers');
		} else {
			$config->setSystemValue('allow_local_remote_servers', $allowLocal);
		}
	});
	$cleanup('Synthetic provider state', static fn () => $fixture('/control', []));
}
$failed = array_values(array_filter($checks, static fn (array $c): bool => !$c['passed']));
$success = $failure === null && $failed === [] && $cleanupErrors === [];
echo json_encode(['success' => $success, 'checks' => $checks, 'metrics' => $metrics, 'error_class' => $failure, 'cleanup_errors' => $cleanupErrors], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
exit($success ? 0 : 1);
