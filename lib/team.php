<?php
/* ============================================================
   Команда наставников: чтение, запись и отрисовка.

   Данные лежат в JSON выше public_html — из браузера файл
   не скачать, а править его можно только через админку.
   Фотографии лежат внутри сайта, в img/team/.

   Тексты хранятся в том же виде, в каком их набирают в админке,
   и разбираются при выводе. Так редактор всегда показывает ровно
   то, что было введено, без потерь при обратном преобразовании.
   ============================================================ */

/* lib/ лежит внутри public_html, поэтому два уровня вверх — домашняя папка. */
define('TEAM_FILE',   dirname(dirname(__DIR__)) . '/kinokrd-team.json');
define('TEAM_PHOTOS', dirname(__DIR__) . '/img/team');

function team_load() {
    if (!is_readable(TEAM_FILE)) {
        return array('members' => array());
    }
    $data = json_decode(file_get_contents(TEAM_FILE), true);
    if (!is_array($data) || !isset($data['members']) || !is_array($data['members'])) {
        return array('members' => array());
    }
    return $data;
}

function team_save($data) {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    /* Пишем через временный файл: если сервер оборвётся на середине,
       основной файл останется целым, а не обрежется наполовину. */
    $tmp = TEAM_FILE . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) { return false; }
    return rename($tmp, TEAM_FILE);
}

function team_find($data, $id) {
    foreach ($data['members'] as $i => $m) {
        if ($m['id'] === $id) { return $i; }
    }
    return null;
}

/* Уникальный и безопасный идентификатор для нового участника. */
function team_new_id($data, $name) {
    $translit = array(
        'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z',
        'и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r',
        'с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch',
        'ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',' '=>'-',
    );
    $base = strtr(mb_strtolower($name, 'UTF-8'), $translit);
    $base = preg_replace('/[^a-z0-9\-]/', '', $base);
    $base = trim(preg_replace('/-+/', '-', $base), '-');
    if ($base === '') { $base = 'member'; }

    $id = $base;
    $n  = 2;
    while (team_find($data, $id) !== null) {
        $id = $base . '-' . $n;
        $n++;
    }
    return $id;
}

/* ---------- разбор текстовых полей ---------- */

/* Роль: каждая строка — с новой строки на странице. */
function team_role_html($role) {
    $lines = preg_split('/\R/u', trim($role));
    $lines = array_filter(array_map('trim', $lines), 'strlen');
    return implode('<br>', array_map('team_esc', $lines));
}

/* Образование: блоки разделены пустой строкой, первая строка блока — заголовок. */
function team_education_blocks($education) {
    $blocks = preg_split('/\R\s*\R/u', trim($education));
    $out = array();
    foreach ($blocks as $block) {
        $lines = preg_split('/\R/u', trim($block));
        $lines = array_values(array_filter(array_map('trim', $lines), 'strlen'));
        if (!$lines) { continue; }
        $out[] = array(
            'title' => array_shift($lines),
            'rest'  => $lines,
        );
    }
    return $out;
}

/* Дополнительная информация: одна строка — один пункт списка. */
function team_extra_items($extra) {
    $lines = preg_split('/\R/u', trim($extra));
    return array_values(array_filter(array_map('trim', $lines), 'strlen'));
}

function team_esc($s) {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/* ---------- отрисовка секции ---------- */

function team_render($data) {
    $html = '';
    foreach ($data['members'] as $m) {
        if (empty($m['visible'])) { continue; }

        $edu   = team_education_blocks(isset($m['education']) ? $m['education'] : '');
        $extra = team_extra_items(isset($m['extra']) ? $m['extra'] : '');

        /* Заголовок раскрывающегося блока подстраивается под содержимое. */
        $summary = $extra ? 'Образование и регалии' : 'Образование';

        $html .= "                    <article class=\"member reveal\">\n";
        $html .= "                        <div class=\"frame\">\n";
        $html .= '                            <img src="' . team_esc($m['photo']) . '" alt="' . team_esc($m['name']) . '" loading="lazy" width="371" height="371">' . "\n";
        $html .= "                        </div>\n";
        $html .= '                        <h3>' . team_esc($m['name']) . "</h3>\n";
        $html .= '                        <p class="role">' . team_role_html(isset($m['role']) ? $m['role'] : '') . "</p>\n";

        if ($edu || $extra) {
            $html .= "\n                        <details class=\"cv\">\n";
            $html .= '                            <summary>' . $summary . "</summary>\n";

            if ($edu) {
                $html .= "                            <ul>\n";
                foreach ($edu as $b) {
                    $html .= '                                <li><strong>' . team_esc($b['title']) . '</strong>';
                    if ($b['rest']) {
                        $html .= "<br>\n                                    " . implode("<br>\n                                    ", array_map('team_esc', $b['rest']));
                    }
                    $html .= "</li>\n";
                }
                $html .= "                            </ul>\n";
            }

            if ($extra) {
                $html .= "                            <p class=\"cv-sub\">Дополнительная информация:</p>\n";
                $html .= "                            <ul class=\"cv-flat\">\n";
                foreach ($extra as $item) {
                    $html .= '                                <li>' . team_esc($item) . "</li>\n";
                }
                $html .= "                            </ul>\n";
            }

            $html .= "                        </details>\n";
        }

        $html .= "                    </article>\n";
    }
    return $html;
}
