<?php
/*
 * Firstplace.hub — приём заявок с формы сайта и отправка в Telegram.
 * Токен бота и секретный код привязки лежат в tg-config.php (в git не хранится).
 *
 * Привязка группы:
 *   1. Добавьте бота в группу и отправьте там: /bind ВАШ_КОД
 *   2. Откройте https://firstplace.uz/send.php?bind=ВАШ_КОД
 * chat id группы сохранится в tg-config.chat.php, и заявки пойдут туда.
 * После привязки повторная привязка выключена (чтобы перепривязать, удалите tg-config.chat.php).
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$cfgFile = __DIR__ . '/tg-config.php';
$cfg = is_file($cfgFile) ? (include $cfgFile) : [];
$token = trim($cfg['bot_token'] ?? '');
$bindCode = trim((string)($cfg['bind_code'] ?? ''));
$chatFile = __DIR__ . '/tg-config.chat.php';
$chatRaw = (string)($cfg['chat_id'] ?? '');
if ($chatRaw === '' && is_file($chatFile)) $chatRaw = (string)(include $chatFile);
$chats = array_filter(array_map('trim', explode(',', $chatRaw)));

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

/* Привязка группы по команде /bind КОД */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['bind'])) {
    $out = function ($msg) { echo json_encode(['result' => $msg], JSON_UNESCAPED_UNICODE); exit; };
    if (!$token) $out('Заполните bot_token в tg-config.php');
    if (strlen($bindCode) < 6) $out('Впишите bind_code (не короче 6 символов) в tg-config.php');
    if ($chats) $out('Группа уже привязана. Чтобы перепривязать, удалите tg-config.chat.php');
    if (!hash_equals($bindCode, (string)$_GET['bind'])) { sleep(2); $out('Неверный код'); }
    $u = tg_call($token, 'getUpdates', ['allowed_updates' => '["message"]']);
    if ($u === null) $out('Сервер хостинга не смог связаться с api.telegram.org (исходящие запросы закрыты или нет curl). Напишите в поддержку хостера.');
    if (empty($u['ok'])) {
        $d = (string)($u['description'] ?? 'неизвестная ошибка');
        if (stripos($d, 'webhook') !== false) $out('У бота включён webhook (бот подключён к конструктору или другому сервису). Отключите его или создайте отдельного бота для заявок. Ответ Telegram: ' . $d);
        if (stripos($d, 'unauthorized') !== false || stripos($d, 'not found') !== false) $out('Telegram не принял токен — проверьте bot_token в tg-config.php. Ответ Telegram: ' . $d);
        $out('Ошибка Telegram: ' . $d);
    }
    $found = null;
    $seen = [];
    foreach (($u['result'] ?? []) as $upd) {
        $m = $upd['message'] ?? null;
        if (!$m || empty($m['text'])) continue;
        $seen[] = mb_substr($m['text'], 0, 40) . ' (' . ($m['chat']['type'] ?? '?') . ')';
        if (preg_match('~^/bind(@\w+)?\s+(\S+)~u', trim($m['text']), $mm) && hash_equals($bindCode, $mm[2])) $found = $m['chat'];
    }
    if (!$found) {
        if (!$seen) $out('Бот пока не получил ни одного сообщения. Убедитесь, что бот добавлен в группу, отправьте там /bind@имя_бота ВАШ_КОД (с @username бота, иначе Telegram может не передать команду) и обновите страницу.');
        $out('Бот видит сообщения, но среди них нет /bind с вашим кодом. Последние: ' . implode(' | ', array_slice($seen, -5)));
    }
    $id = (string)$found['id'];
    if (!preg_match('~^-?\d+$~', $id)) $out('Странный chat id, привязка отменена');
    file_put_contents($chatFile, "<?php return '" . $id . "';\n");
    tg_call($token, 'sendMessage', ['chat_id' => $id, 'text' => 'Готово: заявки с сайта firstplace.uz будут приходить в этот чат.']);
    $out('Привязано к чату «' . ($found['title'] ?? 'личный чат') . '». Проверьте сообщение от бота в группе.');
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
