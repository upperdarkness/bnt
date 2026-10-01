<?php

declare(strict_types=1);

/**
 * Mock OpenRouter chat-completions server for tests (php -S ... tests/mock_openrouter.php).
 *
 * MOCK_DIR (env) holds:
 *   queue.json    list of scripted responses, consumed in order (the last one repeats if "repeat": true)
 *   requests.json every request body received (for assertions)
 *
 * Response forms:
 *   {"tool_calls": [{"name": "scan", "arguments": {}}], "content": null, "usage": {"prompt_tokens": 900, "completion_tokens": 40, "cost": 0.001}}
 *   {"content": "done"}                       -> no tool calls: the model is finished
 *   {"http_status": 500, "error": "boom"}     -> provider failure
 *   {"raw_arguments": "{not json", "name": "go_to"}  as a tool call entry -> malformed arguments
 */

$dir = getenv('MOCK_DIR') ?: sys_get_temp_dir();
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
header('Content-Type: application/json');

if ($path === '/health') {
    echo '{"ok":true}';
    return;
}

$body = file_get_contents('php://input');
$request = json_decode($body, true) ?: [];
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

$fh = fopen("$dir/requests.json", 'c+');
flock($fh, LOCK_EX);
$log = json_decode((string)stream_get_contents($fh), true) ?: [];
$log[] = ['auth_present' => $auth !== '', 'auth' => $auth, 'body' => $request];
ftruncate($fh, 0);
rewind($fh);
fwrite($fh, json_encode($log));
flock($fh, LOCK_UN);
fclose($fh);

$qf = fopen("$dir/queue.json", 'c+');
flock($qf, LOCK_EX);
$queue = json_decode((string)stream_get_contents($qf), true) ?: [];
$item = $queue[0] ?? ['content' => 'nothing more to do'];
if (!empty($queue) && empty($item['repeat'])) {
    array_shift($queue);
    ftruncate($qf, 0);
    rewind($qf);
    fwrite($qf, json_encode($queue));
}
flock($qf, LOCK_UN);
fclose($qf);

if (isset($item['http_status'])) {
    http_response_code((int)$item['http_status']);
    echo json_encode(['error' => ['message' => $item['error'] ?? 'mock failure', 'code' => $item['http_status']]]);
    return;
}

$toolCalls = [];
foreach ($item['tool_calls'] ?? [] as $i => $tc) {
    $toolCalls[] = [
        'id' => 'call_' . bin2hex(random_bytes(4)),
        'type' => 'function',
        'function' => [
            'name' => $tc['name'],
            'arguments' => $tc['raw_arguments'] ?? json_encode($tc['arguments'] ?? new stdClass()),
        ],
    ];
}
$message = ['role' => 'assistant', 'content' => $item['content'] ?? null];
if ($toolCalls) {
    $message['tool_calls'] = $toolCalls;
}
echo json_encode([
    'id' => 'gen-' . bin2hex(random_bytes(4)),
    'model' => $request['model'] ?? 'mock/model',
    'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => $toolCalls ? 'tool_calls' : 'stop']],
    'usage' => $item['usage'] ?? ['prompt_tokens' => 1200, 'completion_tokens' => 60, 'cost' => 0.0012],
]);
