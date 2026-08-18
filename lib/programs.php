<?php
/* ============================================================
   Программы обучения: чтение, запись и отрисовка.

   Раньше программы были зашиты в трёх местах сразу: в карточках
   на странице, в выпадающем списке формы и в проверке на сервере.
   Поменяв цену в одном месте, легко было забыть про остальные —
   и в заявке приходило старое название.

   Теперь источник один: этот файл читает JSON и сам собирает
   и карточки, и список формы, и таблицу названий для писем.
   ============================================================ */

define('PROGRAMS_FILE', dirname(dirname(__DIR__)) . '/kinokrd-programs.json');

/* Иконки лежат в коде, а не в данных: это часть оформления.
   В админке выбирается ключ, произвольную разметку туда не пустить. */
function programs_icons() {
    return array(
        'mask'   => array(
            'name' => 'Маска (актёрское)',
            'svg'  => '<path d="M4 5h16v7a8 8 0 0 1-8 8 8 8 0 0 1-8-8z"/><path d="M8.5 10.5h2M13.5 10.5h2"/><path d="M9.5 15c1.5 1.2 3.5 1.2 5 0"/>',
        ),
        'camera' => array(
            'name' => 'Кинокамера (режиссура)',
            'svg'  => '<rect x="3" y="9" width="12" height="10" rx="1"/><path d="M15 13.5 21 10v8l-6-3.5z"/><circle cx="7" cy="5.5" r="2.5"/><circle cx="13" cy="5.5" r="2.5"/>',
        ),
        'mic'    => array(
            'name' => 'Микрофон (подкасты)',
            'svg'  => '<rect x="9" y="2.5" width="6" height="11" rx="3"/><path d="M5.5 11a6.5 6.5 0 0 0 13 0"/><path d="M12 17.5V21M9 21h6"/>',
        ),
        'pen'    => array(
            'name' => 'Карандаш (сценарий)',
            'svg'  => '<path d="M4 20h4L20 8l-4-4L4 16z"/><path d="M14 6l4 4"/>',
        ),
        'star'   => array(
            'name' => 'Звезда (общее)',
            'svg'  => '<path d="M12 3l2.7 5.9 6.3.7-4.7 4.3 1.3 6.1L12 17l-5.6 3 1.3-6.1L3 9.6l6.3-.7z"/>',
        ),
    );
}

function programs_load() {
    if (!is_readable(PROGRAMS_FILE)) {
        return array('programs' => array());
    }
    $data = json_decode(file_get_contents(PROGRAMS_FILE), true);
    if (!is_array($data) || !isset($data['programs']) || !is_array($data['programs'])) {
        return array('programs' => array());
    }
    return $data;
}

function programs_save($data) {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $tmp = PROGRAMS_FILE . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) { return false; }
    return rename($tmp, PROGRAMS_FILE);
}

function programs_find($data, $id) {
    foreach ($data['programs'] as $i => $p) {
        if ($p['id'] === $id) { return $i; }
    }
    return null;
}

/* Возвращает пару «индекс программы, индекс уровня» — уровни правятся
   отдельно от программы, и без обеих координат до них не добраться. */
function programs_find_tier($data, $tierId) {
    foreach ($data['programs'] as $i => $p) {
        if (empty($p['tiers'])) { continue; }
        foreach ($p['tiers'] as $j => $t) {
            if ($t['id'] === $tierId) { return array($i, $j); }
        }
    }
    return null;
}

function programs_esc($s) {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/* ---------- карточки на странице ---------- */

function programs_render($data) {
    $icons = programs_icons();
    $html = '';

    foreach ($data['programs'] as $p) {
        if (empty($p['visible'])) { continue; }

        $iconKey = isset($p['icon']) && isset($icons[$p['icon']]) ? $p['icon'] : 'star';

        $html .= "                    <article class=\"prog reveal\">\n";
        $html .= "                        <header class=\"prog-head\">\n";
        $html .= "                            <span class=\"prog-icon\" aria-hidden=\"true\">\n";
        $html .= '                                <svg class="ico" viewBox="0 0 24 24">' . $icons[$iconKey]['svg'] . "</svg>\n";
        $html .= "                            </span>\n";
        $html .= '                            <h3>' . programs_esc($p['title']) . "</h3>\n";
        $html .= "                        </header>\n";

        if (!empty($p['desc'])) {
            $html .= '                        <p class="prog-desc">' . programs_esc($p['desc']) . "</p>\n";
        }

        $html .= "\n                        <div class=\"tiers\">\n";

        $first = true;
        foreach ((isset($p['tiers']) ? $p['tiers'] : array()) as $t) {
            /* Первый уровень открыт сразу: переключатель возраста показывает
               ровно один, и без класса show карточка была бы пустой. */
            $show = $first ? ' show' : '';
            $first = false;

            $html .= '                            <button type="button" class="tier' . $show . '"'
                   . ' data-program="' . programs_esc($t['id']) . '"'
                   . ' data-tier="' . programs_esc($t['tier']) . "\">\n";
            $html .= '                                <span class="tier-price">' . programs_esc($t['price'])
                   . ' <i>' . programs_esc($t['unit']) . "</i></span>\n";
            $html .= '                                <span class="tier-meta">' . programs_esc($t['meta']) . "</span>\n";
            $html .= '                                <span class="tier-desc">' . programs_esc($t['desc']) . "</span>\n";
            $html .= "                                <span class=\"tier-go\">Выбрать</span>\n";
            $html .= "                            </button>\n";
        }

        $html .= "                        </div>\n";
        $html .= "                    </article>\n";
    }

    return $html;
}

/* ---------- выпадающий список в форме ---------- */

function programs_options($data) {
    $html = '';
    foreach ($data['programs'] as $p) {
        if (empty($p['visible'])) { continue; }
        foreach ((isset($p['tiers']) ? $p['tiers'] : array()) as $t) {
            $html .= '                                <option value="' . programs_esc($t['id']) . '">'
                   . programs_esc($t['label']) . "</option>\n";
        }
    }
    return $html;
}

/* ---------- названия для писем и проверки заявки ---------- */

function programs_labels($data = null) {
    if ($data === null) { $data = programs_load(); }
    $map = array();
    foreach ($data['programs'] as $p) {
        foreach ((isset($p['tiers']) ? $p['tiers'] : array()) as $t) {
            $map[$t['id']] = $t['label'];
        }
    }
    return $map;
}
