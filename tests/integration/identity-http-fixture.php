<?php

declare(strict_types=1);

// Benign loopback fixture: only tiny, valid compressed bodies, never an exploit.
if (PHP_SAPI !== 'cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
	http_response_code(403);
	exit;
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$log = getenv('EDUCAI_HTTP_FIXTURE_LOG');
if ($log !== false) {
	file_put_contents($log, $path . "\n", FILE_APPEND | LOCK_EX);
}
header('Content-Type: application/json');
$brotli = base64_decode('iwmAeyJmaXh0dXJlIjoiYnJvdGxpIn0D', true);
switch ($path) {
	case '/br':
	case '/error-br':
	case '/redirect-br':
	case '/mixed':
	case '/stacked':
	case '/multiple':
		if ($path === '/error-br') {
			http_response_code(500);
		} elseif ($path === '/redirect-br') {
			header('Location: /echo');
		}
		header('Content-Encoding: ' . match ($path) {
			'/mixed' => 'Br',
			'/stacked' => 'gzip, br',
			'/multiple' => 'identity',
			default => 'br',
		});
		if ($path === '/multiple') {
			header('Content-Encoding: br', false);
		}
		echo $brotli;
		break;
	case '/gzip':
		header('Content-Encoding: gzip');
		echo gzencode('{"fixture":"gzip"}');
		break;
	case '/redirect':
		header('Location: /echo');
		break;
	case '/error':
		http_response_code(503);
		echo '{"error":"fixture"}';
		break;
	case '/binary':
		header('Content-Type: application/octet-stream');
		echo "\x00\x01\xfffixture\r\n";
		break;
	case '/sse':
		header('Content-Type: text/event-stream');
		header('Transfer-Encoding: chunked');
		foreach (["data: first\n\n", "data: second\n\n", "data: [DONE]\n\n"] as $chunk) {
			echo dechex(strlen($chunk)) . "\r\n" . $chunk . "\r\n";
			flush();
			usleep(20000);
		}
		echo "0\r\n\r\n";
		break;
	default:
		echo json_encode([
			'method' => $_SERVER['REQUEST_METHOD'],
			'encoding' => $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',
			'body' => file_get_contents('php://input'),
			'fields' => $_POST,
			'file' => isset($_FILES['document']) ? file_get_contents($_FILES['document']['tmp_name']) : null,
		], JSON_THROW_ON_ERROR);
}
