<?php
/* ============================================================
   Приём заявок с формы сайта «Киноцех личности».

   Заменяет Formspree. Работает на PHP 7.1+ без сторонних библиотек.

   Порядок такой: сначала заявка пишется в журнал на диске, и только
   потом рассылается по каналам. Если упадёт почта, MAX или сеть —
   заявка всё равно останется на сервере и её можно поднять.

   Секреты лежат в config.php ВЫШЕ public_html, поэтому по HTTP
   их не скачать. В этом файле их быть не должно.
   ============================================================ */

header('Content-Type: application/json; charset=utf-8');

$CONFIG_PATH = dirname(__DIR__) . '/kinokrd-config.php';
$LOG_PATH    = dirname(__DIR__) . '/kinokrd-leads.jsonl';
$RATE_PATH   = dirname(__DIR__) . '/kinokrd-rate.json';

/* ---------- служебное ---------- */

function reply($ok, $message, $code = 200) {
    http_response_code($code);
    echo json_encode(array('ok' => $ok, 'message' => $message), JSON_UNESCAPED_UNICODE);
    exit;
}

function clean($v, $max = 500) {
    $v = is_string($v) ? $v : '';
    $v = str_replace(array("\r", "\n", "\0"), ' ', $v);
    $v = trim(strip_tags($v));
    return mb_substr($v, 0, $max, 'UTF-8');
}

/* Многострочное поле: переносы сохраняем, теги — нет. */
function cleanMultiline($v, $max = 2000) {
    $v = is_string($v) ? $v : '';
    $v = str_replace("\0", '', $v);
    $v = trim(strip_tags($v));
    return mb_substr($v, 0, $max, 'UTF-8');
}

function clientIp() {
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
}

/* ---------- 1. только POST ---------- */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reply(false, 'Метод не поддерживается', 405);
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) { $data = $_POST; }

/* ---------- 2. антиспам ---------- */

/* Ловушка: поле скрыто стилями, человек его не видит и не заполняет.
   Ботам отвечаем «успешно», чтобы они не искали обход. */
if (clean(isset($data['website']) ? $data['website'] : '') !== '') {
    reply(true, 'Заявка отправлена');
}

/* Форму, заполненную быстрее трёх секунд, заполнял не человек. */
$elapsed = isset($data['elapsed']) ? (int)$data['elapsed'] : 999;
if ($elapsed < 3) {
    reply(true, 'Заявка отправлена');
}

/* Не больше 5 заявок с одного адреса в час. */
$ip    = clientIp();
$now   = time();
$rates = is_readable($RATE_PATH) ? json_decode(file_get_contents($RATE_PATH), true) : array();
if (!is_array($rates)) { $rates = array(); }
foreach ($rates as $k => $stamps) {
    $rates[$k] = array_values(array_filter($stamps, function ($t) use ($now) { return $t > $now - 3600; }));
    if (!$rates[$k]) { unset($rates[$k]); }
}
if (isset($rates[$ip]) && count($rates[$ip]) >= 5) {
    reply(false, 'Слишком много заявок. Попробуйте позже или позвоните нам.', 429);
}
$rates[$ip][] = $now;
@file_put_contents($RATE_PATH, json_encode($rates), LOCK_EX);

/* ---------- 3. разбор и проверка ---------- */

/* Названия берутся из тех же данных, что и карточки на сайте. Пока список
   был продублирован здесь, правка программы в админке оставляла в письме
   старое название — и заявка приходила про несуществующий курс. */
require __DIR__ . '/lib/programs.php';
$PROGRAMS = programs_labels();

$lead = array(
    'name'     => clean(isset($data['name'])     ? $data['name']     : '', 120),
    'phone'    => clean(isset($data['phone'])    ? $data['phone']    : '', 40),
    'email'    => clean(isset($data['email'])    ? $data['email']    : '', 120),
    'childAge' => clean(isset($data['childAge']) ? $data['childAge'] : '', 10),
    'program'  => clean(isset($data['program'])  ? $data['program']  : '', 60),
    'message'  => cleanMultiline(isset($data['message']) ? $data['message'] : ''),
    'consent'  => !empty($data['privacyPolicy']),
);

$errors = array();
if ($lead['name']  === '') { $errors[] = 'имя'; }
if ($lead['phone'] === '') { $errors[] = 'телефон'; }
if ($lead['email'] === '' || !filter_var($lead['email'], FILTER_VALIDATE_EMAIL)) { $errors[] = 'email'; }
if ($lead['program'] === '') { $errors[] = 'программа'; }

$age = (int)$lead['childAge'];
if ($age < 8 || $age > 17) { $errors[] = 'возраст ребёнка (8–17)'; }

if ($errors) {
    reply(false, 'Проверьте поля: ' . implode(', ', $errors), 422);
}
if (!$lead['consent']) {
    reply(false, 'Нужно согласие на обработку персональных данных', 422);
}

$lead['programText'] = isset($PROGRAMS[$lead['program']]) ? $PROGRAMS[$lead['program']] : $lead['program'];
$lead['at']          = date('Y-m-d H:i:s');
$lead['ip']          = $ip;

/* ---------- 4. журнал: делаем ДО рассылки ---------- */

@file_put_contents($LOG_PATH, json_encode($lead, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

/* ---------- 5. конфиг ---------- */

$cfg = is_readable($CONFIG_PATH) ? require $CONFIG_PATH : array();
if (!is_array($cfg)) { $cfg = array(); }

/* ---------- 6. текст заявки ---------- */

$lines = array(
    'Новая заявка с сайта кинокрд.рф',
    '',
    'Имя:       ' . $lead['name'],
    'Телефон:   ' . $lead['phone'],
    'Email:     ' . $lead['email'],
    'Возраст:   ' . $lead['childAge'],
    'Программа: ' . $lead['programText'],
);
if ($lead['message'] !== '') {
    $lines[] = '';
    $lines[] = 'Комментарий:';
    $lines[] = $lead['message'];
}
$lines[] = '';
$lines[] = 'Отправлено: ' . $lead['at'];
$text = implode("\n", $lines);

$delivered = array();

/* ---------- 7. почта ---------- */

if (!empty($cfg['mail_to'])) {
    $subject = 'Заявка с сайта: ' . $lead['name'];
    $sent = false;

    if (!empty($cfg['smtp_host']) && !empty($cfg['smtp_user'])) {
        $sent = smtpSend($cfg, $cfg['mail_to'], $subject, $text, $lead['email']);
    } else {
        /* Запасной путь. Письма через mail() с шаред-хостинга
           часто попадают в спам — SMTP предпочтительнее. */
        $from    = !empty($cfg['mail_from']) ? $cfg['mail_from'] : 'noreply@' . $_SERVER['HTTP_HOST'];
        $headers = "From: =?UTF-8?B?" . base64_encode('Сайт Киноцех') . "?= <$from>\r\n"
                 . "Reply-To: " . $lead['email'] . "\r\n"
                 . "Content-Type: text/plain; charset=UTF-8\r\n"
                 . "MIME-Version: 1.0\r\n";
        $sent = @mail($cfg['mail_to'], "=?UTF-8?B?" . base64_encode($subject) . "?=", $text, $headers);
    }
    $delivered['mail'] = $sent ? 'ok' : 'fail';
}

/* ---------- 8. MAX ---------- */

if (!empty($cfg['max_token']) && !empty($cfg['max_chat_id'])) {
    $delivered['max'] = maxSend($cfg, $text) ? 'ok' : 'fail';
}

@file_put_contents($LOG_PATH, json_encode(array('at' => $lead['at'], 'delivery' => $delivered), JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

reply(true, 'Заявка отправлена. Мы свяжемся с вами в течение дня.');


/* ============================================================
   Отправители
   ============================================================ */

function maxSend($cfg, $text) {
    $base = !empty($cfg['max_api']) ? rtrim($cfg['max_api'], '/') : 'https://platform-api.max.ru';
    $body = json_encode(array('text' => $text), JSON_UNESCAPED_UNICODE);

    /* Документация MAX расходится в том, как передаётся токен и какой
       именно хост актуален. Пробуем известные варианты по очереди,
       первый успешный выигрывает. */
    $attempts = array(
        array($base . '/messages?chat_id=' . urlencode($cfg['max_chat_id']), array('Authorization: ' . $cfg['max_token'])),
        array($base . '/messages?access_token=' . urlencode($cfg['max_token']) . '&chat_id=' . urlencode($cfg['max_chat_id']), array()),
        array('https://platform-api2.max.ru/messages?chat_id=' . urlencode($cfg['max_chat_id']), array('Authorization: ' . $cfg['max_token'])),
    );

    foreach ($attempts as $a) {
        $ch = curl_init($a[0]);
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => array_merge(array('Content-Type: application/json'), $a[1]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
        ));
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 200 && $code < 300) { return true; }
    }
    return false;
}

function smtpSend($cfg, $to, $subject, $body, $replyTo) {
    $host   = $cfg['smtp_host'];
    $port   = !empty($cfg['smtp_port']) ? (int)$cfg['smtp_port'] : 465;
    $secure = isset($cfg['smtp_secure']) ? $cfg['smtp_secure'] : 'ssl';
    $from   = !empty($cfg['mail_from']) ? $cfg['mail_from'] : $cfg['smtp_user'];

    $target = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $fp = @stream_socket_client($target, $errno, $errstr, 15);
    if (!$fp) { return false; }
    stream_set_timeout($fp, 15);

    $read = function () use ($fp) {
        $out = '';
        while ($line = fgets($fp, 515)) {
            $out .= $line;
            if (isset($line[3]) && $line[3] === ' ') { break; }
        }
        return $out;
    };
    $say = function ($cmd) use ($fp, $read) {
        fwrite($fp, $cmd . "\r\n");
        return $read();
    };

    $read();
    $say('EHLO ' . $host);

    if ($secure === 'tls') {
        $say('STARTTLS');
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($fp); return false; }
        $say('EHLO ' . $host);
    }

    $say('AUTH LOGIN');
    $say(base64_encode($cfg['smtp_user']));
    $auth = $say(base64_encode($cfg['smtp_pass']));
    if (strpos($auth, '235') !== 0) { fclose($fp); return false; }

    $say('MAIL FROM:<' . $from . '>');
    $say('RCPT TO:<' . $to . '>');
    $data = $say('DATA');
    if (strpos($data, '354') !== 0) { fclose($fp); return false; }

    $headers = 'From: =?UTF-8?B?' . base64_encode('Сайт Киноцех') . "?= <$from>\r\n"
             . "To: <$to>\r\n"
             . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n"
             . "Reply-To: <$replyTo>\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: base64\r\n"
             . 'Date: ' . date('r') . "\r\n";

    /* Точка в начале строки завершает передачу письма — экранируем. */
    $payload = chunk_split(base64_encode($body));
    $result  = $say($headers . "\r\n" . $payload . "\r\n.");
    $say('QUIT');
    fclose($fp);

    return strpos($result, '250') === 0;
}
