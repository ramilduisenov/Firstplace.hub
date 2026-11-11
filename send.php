<?php
/*
 * Firstplace.hub — приём заявок с формы сайта и отправка в Telegram.
 * Токен бота и chat id лежат в tg-config.php (в git не хранится).
 *
 * Помощник настройки: пока chat_id не заполнен, откройте
 *   https://firstplace.uz/send.php?whoami
 * после того как написали боту (или добавили его в группу) — увидите chat id.
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$cfgFile = __DIR__ . '/tg-config.php';
$cfg = is_file($cfgFile) ? (include $cfgFile) : [];
$token = trim($cfg['bot_token'] ?? '');
$chats = array_filter(array_map('trim', explode(',', (string)($cfg['chat_id'] ?? ''))));

function tg_call($token, $method, $params = null) {
    $url = "https://api.telegram.org/bot{$token}/{$method}";
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        if ($params !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }
        $res = curl_exec($ch);
        curl_close($ch);
    } else {
        $opts = ['http' => ['timeout' => 10, 'ignore_errors' => true]];
        if ($params !== null) {
            $opts['http'] += ['method' => 'POST', 'header' => 'Content-Type: application/x-www-form-urlencoded', 'content' => http_build_query($params)];
        }
        $res = @file_get_contents($url, false, stream_context_create($opts));
    }
    return $res ? json_decode($res, true) : null;
}

/* Помощник: показать chat id, пока он не настроен */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['whoami'])) {
    if (!$token) { echo json_encode(['error' => 'Заполните bot_token в tg-config.php'], JSON_UNESCAPED_UNICODE); exit; }
    if ($chats) { echo json_encode(['error' => 'chat_id уже настроен, помощник выключен'], JSON_UNESCAPED_UNICODE); exit; }
    $u = tg_call($token, 'getUpdates');
    $found = [];
    foreach (($u['result'] ?? []) as $upd) {
        $m = $upd['message'] ?? $upd['my_chat_member'] ?? $upd['channel_post'] ?? null;
        if (!empty($m['chat']['id'])) {
            $c = $m['chat'];
            $found[$c['id']] = ['chat_id' => $c['id'], 'type' => $c['type'], 'name' => $c['title'] ?? trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''))];
        }
    }
    echo json_encode(['chats' => array_values($found), 'hint' => $found ? 'Скопируйте нужный chat_id в tg-config.php' : 'Напишите боту любое сообщение (или добавьте его в группу и напишите там) и обновите страницу'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{"ok":false}'; exit; }
if (!$token || !$chats) { http_response_code(503); echo json_encode(['ok' => false, 'error' => 'not_configured']); exit; }

$raw = file_get_contents('php://input', false, null, 0, 20000);
$in = json_decode($raw, true);
if (!is_array($in)) $in = $_POST;

/* honeypot: поле, которое люди не видят */
if (!empty($in['website'])) { echo '{"ok":true}'; exit; }

/* простое ограничение: не больше 5 заявок с одного IP за 10 минут */
$ip = $_SERVER['REMOTE_ADDR'] ?? '0';
$rlFile = sys_get_temp_dir() . '/fph_rl_' . md5($ip);
$now = time();
$hits = array_filter(is_file($rlFile) ? (array)json_decode((string)@file_get_contents($rlFile), true) : [], fn($t) => $t > $now - 600);
if (count($hits) >= 5) { http_response_code(429); echo json_encode(['ok' => false, 'error' => 'rate']); exit; }
$hits[] = $now;
@file_put_contents($rlFile, json_encode(array_values($hits)));

function f($in, $k, $max = 300) {
    $v = trim((string)($in[$k] ?? ''));
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v);
    return htmlspecialchars(mb_substr($v, 0, $max), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

$name = f($in, 'name', 120);
$contact = f($in, 'contact', 120);
if ($name === '' || $contact === '') { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'invalid']); exit; }

$lines = ["<b>Новая заявка с сайта</b>", "", "<b>Имя:</b> {$name}", "<b>Контакт:</b> {$contact}"];
if (($v = f($in, 'brand', 160)) !== '') $lines[] = "<b>Бренд:</b> {$v}";
if (($v = f($in, 'industry', 80)) !== '') $lines[] = "<b>Отрасль:</b> {$v}";
if (($v = f($in, 'need', 80)) !== '') $lines[] = "<b>Что нужно:</b> {$v}";
if (($v = f($in, 'message', 3000)) !== '') { $lines[] = ""; $lines[] = "<b>Задача:</b>"; $lines[] = $v; }
$text = implode("\n", $lines);

$sent = false;
foreach ($chats as $chat) {
    $r = tg_call($token, 'sendMessage', ['chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true']);
    if (!empty($r['ok'])) $sent = true;
}

if (!$sent) { http_response_code(502); echo json_encode(['ok' => false, 'error' => 'telegram']); exit; }
echo '{"ok":true}';
