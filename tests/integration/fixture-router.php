<?php

declare(strict_types=1);

// Run only as a loopback PHP development server in a disposable test container.
// Request counters deliberately exclude headers and bodies, including credentials.
$stateFile = sys_get_temp_dir() . '/educai-integration-fixture-state.json';
$state = is_file($stateFile) ? json_decode((string)file_get_contents($stateFile), true) : null;
$state = is_array($state) ? $state : ['mode' => 'normal', 'requests' => []];
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$reply = static function (array $body, int $status = 200): void {
	http_response_code($status);
	header('Content-Type: application/json');
	echo json_encode($body, JSON_THROW_ON_ERROR);
};

if ($path === '/control') {
	$input = json_decode((string)file_get_contents('php://input'), true);
	$mode = $input['mode'] ?? 'normal';
	if (!in_array($mode, ['normal', 'fail_past', 'fail_embeddings'], true)) {
		$reply(['error' => 'Unknown fixture mode'], 400);
		return;
	}
	$state = ['mode' => $mode, 'requests' => !empty($input['reset']) ? [] : $state['requests']];
	file_put_contents($stateFile, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
	$reply(['ok' => true]);
	return;
}
if ($path === '/stats') {
	$reply($state);
	return;
}

$state['requests'][$path] = ($state['requests'][$path] ?? 0) + 1;
file_put_contents($stateFile, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
if ($path === '/catalogue/version') {
	$reply(['version' => 'fixture-1.0']);
	return;
}
if ($path === '/catalogue/course/list') {
	$past = ($_GET['state'] ?? 'OPEN') === 'CLOSED';
	if ($past && $state['mode'] === 'fail_past') {
		$reply(['error' => 'Synthetic archived catalogue failure'], 503);
		return;
	}
	$courses = $past
		? [['id' => 81, 'title' => 'Archived history seminar', 'summary' => 'Historical learning opportunity.']]
		: [
			['id' => 42, 'title' => 'Physics laboratory', 'summary' => 'Study physics and scientific experiments.'],
			['id' => 43, 'title' => 'Introduction to literature', 'summary' => 'Read and discuss literary texts.'],
		];
	$offset = max(0, (int)($_GET['offset'] ?? 0));
	$limit = max(1, min(500, (int)($_GET['limit'] ?? 20)));
	$reply(['courses' => array_slice($courses, $offset, $limit), 'count' => count($courses)]);
	return;
}
if (in_array($path, ['/v1/embeddings', '/v1-alt/embeddings'], true)) {
	if ($state['mode'] === 'fail_embeddings') {
		$reply(['error' => ['message' => 'Synthetic embedding failure']], 500);
		return;
	}
	$input = json_decode((string)file_get_contents('php://input'), true);
	$texts = $input['input'] ?? [];
	$texts = is_array($texts) ? $texts : [$texts];
	$data = [];
	foreach ($texts as $index => $text) {
		$text = strtolower((string)$text);
		$vector = str_contains($text, 'physics') ? [1.0, 0.0, 0.0]
			: (str_contains($text, 'history') ? [0.0, 1.0, 0.0] : [0.0, 0.0, 1.0]);
		$data[] = ['object' => 'embedding', 'index' => $index, 'embedding' => $vector];
	}
	$reply(['object' => 'list', 'model' => $input['model'] ?? 'fixture-embedding', 'data' => $data, 'usage' => ['total_tokens' => 1]]);
	return;
}
if ($path === '/v1/models') {
	$reply(['data' => [['id' => 'fixture-embedding', 'object' => 'model']]]);
	return;
}
$reply(['error' => 'Unknown synthetic endpoint'], 404);
