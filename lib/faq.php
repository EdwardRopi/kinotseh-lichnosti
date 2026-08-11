<?php
/* ============================================================
   Вопросы родителей: чтение, запись и отрисовка.

   Устроено так же, как команда наставников: данные в JSON выше
   public_html, правятся через админку, страница собирает раздел
   при каждом открытии.
   ============================================================ */

define('FAQ_FILE', dirname(dirname(__DIR__)) . '/kinokrd-faq.json');

function faq_load() {
    if (!is_readable(FAQ_FILE)) {
        return array('items' => array());
    }
    $data = json_decode(file_get_contents(FAQ_FILE), true);
    if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
        return array('items' => array());
    }
    return $data;
}

function faq_save($data) {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    /* Через временный файл: обрыв на середине записи не должен
       оставить основной файл наполовину обрезанным. */
    $tmp = FAQ_FILE . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) { return false; }
    return rename($tmp, FAQ_FILE);
}

function faq_find($data, $id) {
    foreach ($data['items'] as $i => $item) {
        if ($item['id'] === $id) { return $i; }
    }
    return null;
}

function faq_new_id($data) {
    /* Порядковый номер не годится: после удаления он повторится
       и разошлётся по ссылкам. Берём метку времени со счётчиком. */
    $base = 'q' . time();
    $id = $base;
    $n = 2;
    while (faq_find($data, $id) !== null) {
        $id = $base . '-' . $n;
        $n++;
    }
    return $id;
}

function faq_esc($s) {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/* Ответ может быть в несколько абзацев: пустая строка их разделяет */
function faq_answer_html($answer) {
    $blocks = preg_split('/\R\s*\R/u', trim($answer));
    $out = array();
    foreach ($blocks as $block) {
        $block = trim($block);
        if ($block === '') { continue; }
        /* Одиночные переносы внутри абзаца сохраняем */
        $out[] = '<p>' . nl2br(faq_esc($block)) . '</p>';
    }
    return implode("\n                                ", $out);
}

function faq_render($data) {
    $html = '';
    foreach ($data['items'] as $item) {
        if (empty($item['visible'])) { continue; }
        $q = trim(isset($item['q']) ? $item['q'] : '');
        $a = trim(isset($item['a']) ? $item['a'] : '');
        if ($q === '') { continue; }

        $html .= "                    <details class=\"faq-item reveal\">\n";
        $html .= '                        <summary>' . faq_esc($q) . "</summary>\n";
        if ($a !== '') {
            $html .= "                        <div class=\"faq-answer\">\n";
            $html .= '                                ' . faq_answer_html($a) . "\n";
            $html .= "                        </div>\n";
        }
        $html .= "                    </details>\n";
    }
    return $html;
}
