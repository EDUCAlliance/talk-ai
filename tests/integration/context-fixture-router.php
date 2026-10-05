<?php

declare(strict_types=1);

// A local, synthetic chat provider. It never stores prompts, headers or API keys.
if (!is_file('/.dockerenv') || getenv('EDUCAI_TEST_ALLOW_MUTATION') !== '1'
	|| ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
	http_response_code(403);
	exit;
}
$port = (int)($_SERVER['SERVER_PORT'] ?? 0);
$state = sys_get_temp_dir() . '/educai-context-fixture-' . $port . '.json';
$data = is_file($state) ? json_decode(file_get_contents($state), true, 512, JSON_THROW_ON_ERROR) : ['config' => [], 'requests' => []];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if ($path === '/control') {
	$config = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
	if (!is_array($config)) {
		http_response_code(400);
		exit;
	}
	$data = ['config' => $config, 'requests' => []];
	file_put_contents($state, json_encode($data, JSON_THROW_ON_ERROR), LOCK_EX);
	echo '{"ok":true}';
	return;
}
if ($path === '/stats') {
	echo json_encode(['requests' => $data['requests']], JSON_THROW_ON_ERROR);
	return;
}
if ($path !== '/v1/chat/completions') {
	http_response_code(404);
	echo '{"error":"unknown synthetic route"}';
	return;
}
$raw = file_get_contents('php://input');
$request = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
$chars = 0;
foreach ($request['messages'] ?? [] as $message) {
	$chars += mb_strlen(is_string($message['content'] ?? null) ? $message['content'] : json_encode($message['content'] ?? null), 'UTF-8');
}
$data['requests'][] = ['request_bytes' => strlen($raw), 'content_chars' => $chars, 'messages' => count($request['messages'] ?? []), 'stream' => !empty($request['stream'])];
file_put_contents($state, json_encode($data, JSON_THROW_ON_ERROR), LOCK_EX);
if ($chars > ($data['config']['max_chars'] ?? 32768)) {
	http_response_code(400);
	echo json_encode(['error' => ['code' => 'context_length_exceeded', 'type' => 'invalid_request_error', 'message' => 'This synthetic model maximum context length has been exceeded.']], JSON_THROW_ON_ERROR);
	return;
}
$length = max(0, min(100000, (int)($data['config']['output_chars'] ?? 32)));
$character = ($data['config']['unicode'] ?? false) ? '🧪' : 'x';
$output = str_repeat($character, $length);
if (!empty($request['stream'])) {
	header('Content-Type: text/event-stream');
	echo 'data: ' . json_encode(['id' => 'fixture', 'object' => 'chat.completion.chunk', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => $output], 'finish_reason' => null]]], JSON_THROW_ON_ERROR) . "\n\n";
	echo 'data: ' . json_encode(['id' => 'fixture', 'object' => 'chat.completion.chunk', 'choices' => [['index' => 0, 'delta' => new stdClass(), 'finish_reason' => 'stop']]] , JSON_THROW_ON_ERROR) . "\n\n";
	echo "data: [DONE]\n\n";
} else {
	echo json_encode(['id' => 'fixture', 'object' => 'chat.completion', 'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $output], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => (int)ceil($chars / 4), 'completion_tokens' => 8, 'total_tokens' => (int)ceil($chars / 4) + 8]], JSON_THROW_ON_ERROR);
}
