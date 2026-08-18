<?php
/* ============================================================
   Админка: управление составом наставников.

   Пароль задаётся при первом заходе и хранится хешем в файле
   выше public_html — в открытом виде он не лежит нигде, включая
   этот код и репозиторий.

   Всё в одном файле намеренно: панель маленькая, и держать её
   целиком перед глазами проще, чем разносить по модулям.
   ============================================================ */

require dirname(__DIR__) . '/lib/team.php';
require dirname(__DIR__) . '/lib/faq.php';
require dirname(__DIR__) . '/lib/programs.php';

define('ADMIN_FILE', dirname(dirname(__DIR__)) . '/kinokrd-admin.json');
define('MAX_PHOTO',  5 * 1024 * 1024);

session_set_cookie_params(0, '/', '', !empty($_SERVER['HTTPS']), true);
session_start();

/* ---------- пароль ---------- */

function admin_hash_read() {
    if (!is_readable(ADMIN_FILE)) { return null; }
    $d = json_decode(file_get_contents(ADMIN_FILE), true);
    return isset($d['hash']) ? $d['hash'] : null;
}

function admin_hash_write($password) {
    $json = json_encode(array('hash' => password_hash($password, PASSWORD_DEFAULT)));
    return file_put_contents(ADMIN_FILE, $json, LOCK_EX) !== false;
}

/* ---------- защита от подделки запросов ---------- */

function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_check() {
    $sent = isset($_POST['csrf']) ? $_POST['csrf'] : '';
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        http_response_code(400);
        exit('Сессия устарела. Обновите страницу и попробуйте снова.');
    }
}

function e($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function redirect($qs = '') {
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . ($qs ? '?' . $qs : ''));
    exit;
}

$hash     = admin_hash_read();
$isSetup  = ($hash === null);
$loggedIn = !empty($_SESSION['admin']);
$notice   = isset($_GET['ok']) ? $_GET['ok'] : '';
$error    = '';

/* ---------- действия без авторизации ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isSetup && isset($_POST['action']) && $_POST['action'] === 'setup') {
    csrf_check();
    $p1 = isset($_POST['password']) ? $_POST['password'] : '';
    $p2 = isset($_POST['password2']) ? $_POST['password2'] : '';
    if (mb_strlen($p1) < 8) {
        $error = 'Пароль должен быть не короче 8 символов.';
    } elseif ($p1 !== $p2) {
        $error = 'Пароли не совпадают.';
    } elseif (admin_hash_write($p1)) {
        $_SESSION['admin'] = true;
        redirect('ok=' . rawurlencode('Пароль сохранён'));
    } else {
        $error = 'Не удалось сохранить пароль. Проверьте права на запись.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isSetup && isset($_POST['action']) && $_POST['action'] === 'login') {
    csrf_check();
    /* Пауза после неверной попытки: перебор становится бессмысленным,
       а живому человеку секунда не мешает. */
    if (password_verify(isset($_POST['password']) ? $_POST['password'] : '', $hash)) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        redirect();
    } else {
        sleep(1);
        $error = 'Неверный пароль.';
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    redirect();
}

/* ---------- действия с данными ---------- */

if ($loggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_check();
    $data   = team_load();
    $action = $_POST['action'];

    if ($action === 'save') {
        $id    = isset($_POST['id']) ? trim($_POST['id']) : '';
        $name  = trim(isset($_POST['name']) ? $_POST['name'] : '');

        if ($name === '') {
            $error = 'Имя обязательно.';
        } else {
            $idx = $id !== '' ? team_find($data, $id) : null;
            $member = $idx !== null ? $data['members'][$idx] : array('photo' => '', 'visible' => true);

            $member['name']      = $name;
            $member['role']      = trim(isset($_POST['role']) ? $_POST['role'] : '');
            $member['education'] = trim(isset($_POST['education']) ? $_POST['education'] : '');
            $member['extra']     = trim(isset($_POST['extra']) ? $_POST['extra'] : '');
            $member['visible']   = !empty($_POST['visible']);

            /* Фото необязательно: без него остаётся прежнее. */
            if (!empty($_FILES['photo']['name'])) {
                $up = upload_photo($_FILES['photo']);
                if (is_string($up)) {
                    $member['photo'] = $up;
                } else {
                    $error = $up['error'];
                }
            }

            if ($error === '') {
                if ($idx !== null) {
                    $data['members'][$idx] = $member;
                    $msg = 'Изменения сохранены';
                } else {
                    $member['id'] = team_new_id($data, $name);
                    $data['members'][] = $member;
                    $msg = 'Участник добавлен';
                }
                team_save($data);
                redirect('ok=' . rawurlencode($msg));
            }
        }
    }

    if ($action === 'delete') {
        $idx = team_find($data, isset($_POST['id']) ? $_POST['id'] : '');
        if ($idx !== null) {
            array_splice($data['members'], $idx, 1);
            team_save($data);
        }
        redirect('ok=' . rawurlencode('Участник удалён'));
    }

    if ($action === 'move') {
        $idx = team_find($data, isset($_POST['id']) ? $_POST['id'] : '');
        $dir = ($_POST['dir'] === 'up') ? -1 : 1;
        $new = $idx + $dir;
        if ($idx !== null && $new >= 0 && $new < count($data['members'])) {
            $tmp = $data['members'][$idx];
            $data['members'][$idx] = $data['members'][$new];
            $data['members'][$new] = $tmp;
            team_save($data);
        }
        redirect('ok=' . rawurlencode('Порядок изменён'));
    }

    /* ---------- программы обучения ---------- */

    if ($action === 'prog-save') {
        $progs = programs_load();
        $id    = isset($_POST['id']) ? trim($_POST['id']) : '';
        $title = trim(isset($_POST['title']) ? $_POST['title'] : '');

        if ($title === '') {
            $error = 'Название программы обязательно.';
        } else {
            $idx = $id !== '' ? programs_find($progs, $id) : null;
            $p   = $idx !== null ? $progs['programs'][$idx] : array('tiers' => array());

            $p['title']   = $title;
            $p['desc']    = trim(isset($_POST['desc']) ? $_POST['desc'] : '');
            $p['icon']    = isset($_POST['icon']) ? $_POST['icon'] : 'star';
            $p['visible'] = !empty($_POST['visible']);

            if ($idx !== null) {
                $progs['programs'][$idx] = $p;
                $msg = 'Программа сохранена';
            } else {
                /* Идентификатор задаём сами и больше не меняем: на него
                   ссылаются уже отправленные заявки. */
                $p['id'] = 'prog' . time();
                $progs['programs'][] = $p;
                $msg = 'Программа добавлена';
            }
            programs_save($progs);
            redirect('tab=prog&ok=' . rawurlencode($msg));
        }
    }

    if ($action === 'prog-delete') {
        $progs = programs_load();
        $idx = programs_find($progs, isset($_POST['id']) ? $_POST['id'] : '');
        if ($idx !== null) {
            array_splice($progs['programs'], $idx, 1);
            programs_save($progs);
        }
        redirect('tab=prog&ok=' . rawurlencode('Программа удалена'));
    }

    if ($action === 'prog-move') {
        $progs = programs_load();
        $idx = programs_find($progs, isset($_POST['id']) ? $_POST['id'] : '');
        $dir = ($_POST['dir'] === 'up') ? -1 : 1;
        $new = $idx + $dir;
        if ($idx !== null && $new >= 0 && $new < count($progs['programs'])) {
            $tmp = $progs['programs'][$idx];
            $progs['programs'][$idx] = $progs['programs'][$new];
            $progs['programs'][$new] = $tmp;
            programs_save($progs);
        }
        redirect('tab=prog&ok=' . rawurlencode('Порядок изменён'));
    }

    if ($action === 'tier-save') {
        $progs  = programs_load();
        $tierId = isset($_POST['id']) ? trim($_POST['id']) : '';
        $progId = isset($_POST['program']) ? trim($_POST['program']) : '';
        $price  = trim(isset($_POST['price']) ? $_POST['price'] : '');

        if ($price === '') {
            $error = 'Цена обязательна.';
        } else {
            $found = $tierId !== '' ? programs_find_tier($progs, $tierId) : null;

            if ($found !== null) {
                list($pi, $ti) = $found;
                $t = $progs['programs'][$pi]['tiers'][$ti];
            } else {
                $pi = programs_find($progs, $progId);
                if ($pi === null) { redirect('tab=prog&ok=' . rawurlencode('Программа не найдена')); }
                $ti = null;
                $t  = array('id' => 'tier' . time());
            }

            $t['price'] = $price;
            $t['unit']  = trim(isset($_POST['unit'])  ? $_POST['unit']  : '');
            $t['meta']  = trim(isset($_POST['meta'])  ? $_POST['meta']  : '');
            $t['desc']  = trim(isset($_POST['desc'])  ? $_POST['desc']  : '');
            $t['label'] = trim(isset($_POST['label']) ? $_POST['label'] : '');
            $t['tier']  = isset($_POST['tier']) ? $_POST['tier'] : 'both';

            if ($t['label'] === '') { $t['label'] = $progs['programs'][$pi]['title']; }

            if ($ti !== null) {
                $progs['programs'][$pi]['tiers'][$ti] = $t;
                $msg = 'Уровень сохранён';
            } else {
                $progs['programs'][$pi]['tiers'][] = $t;
                $msg = 'Уровень добавлен';
            }
            programs_save($progs);
            redirect('tab=prog&ok=' . rawurlencode($msg));
        }
    }

    if ($action === 'tier-delete') {
        $progs = programs_load();
        $found = programs_find_tier($progs, isset($_POST['id']) ? $_POST['id'] : '');
        if ($found !== null) {
            list($pi, $ti) = $found;
            array_splice($progs['programs'][$pi]['tiers'], $ti, 1);
            programs_save($progs);
        }
        redirect('tab=prog&ok=' . rawurlencode('Уровень удалён'));
    }

    /* ---------- вопросы родителей ---------- */

    if ($action === 'faq-save') {
        $faq = faq_load();
        $id  = isset($_POST['id']) ? trim($_POST['id']) : '';
        $q   = trim(isset($_POST['q']) ? $_POST['q'] : '');

        if ($q === '') {
            $error = 'Вопрос обязателен.';
        } else {
            $idx  = $id !== '' ? faq_find($faq, $id) : null;
            $item = $idx !== null ? $faq['items'][$idx] : array();

            $item['q']       = $q;
            $item['a']       = trim(isset($_POST['a']) ? $_POST['a'] : '');
            $item['visible'] = !empty($_POST['visible']);

            if ($idx !== null) {
                $faq['items'][$idx] = $item;
                $msg = 'Вопрос сохранён';
            } else {
                $item['id'] = faq_new_id($faq);
                $faq['items'][] = $item;
                $msg = 'Вопрос добавлен';
            }
            faq_save($faq);
            redirect('tab=faq&ok=' . rawurlencode($msg));
        }
    }

    if ($action === 'faq-delete') {
        $faq = faq_load();
        $idx = faq_find($faq, isset($_POST['id']) ? $_POST['id'] : '');
        if ($idx !== null) {
            array_splice($faq['items'], $idx, 1);
            faq_save($faq);
        }
        redirect('tab=faq&ok=' . rawurlencode('Вопрос удалён'));
    }

    if ($action === 'faq-move') {
        $faq = faq_load();
        $idx = faq_find($faq, isset($_POST['id']) ? $_POST['id'] : '');
        $dir = ($_POST['dir'] === 'up') ? -1 : 1;
        $new = $idx + $dir;
        if ($idx !== null && $new >= 0 && $new < count($faq['items'])) {
            $tmp = $faq['items'][$idx];
            $faq['items'][$idx] = $faq['items'][$new];
            $faq['items'][$new] = $tmp;
            faq_save($faq);
        }
        redirect('tab=faq&ok=' . rawurlencode('Порядок изменён'));
    }

    if ($action === 'password') {
        $p1 = isset($_POST['password']) ? $_POST['password'] : '';
        $p2 = isset($_POST['password2']) ? $_POST['password2'] : '';
        if (mb_strlen($p1) < 8) {
            $error = 'Пароль должен быть не короче 8 символов.';
        } elseif ($p1 !== $p2) {
            $error = 'Пароли не совпадают.';
        } else {
            admin_hash_write($p1);
            redirect('ok=' . rawurlencode('Пароль изменён'));
        }
    }
}

/* ---------- загрузка фотографии ---------- */

function upload_photo($file) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return array('error' => 'Файл не загрузился. Возможно, он слишком большой.');
    }
    if ($file['size'] > MAX_PHOTO) {
        return array('error' => 'Фотография больше 5 МБ. Уменьшите её и попробуйте снова.');
    }

    /* Проверяем не расширение, а содержимое: расширение подделывается. */
    $info = @getimagesize($file['tmp_name']);
    if (!$info) {
        return array('error' => 'Это не изображение.');
    }
    $ext = array(IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp');
    if (!isset($ext[$info[2]])) {
        return array('error' => 'Подходят JPG, PNG и WebP.');
    }

    if (!is_dir(TEAM_PHOTOS) && !mkdir(TEAM_PHOTOS, 0755, true)) {
        return array('error' => 'Не удалось создать папку для фотографий.');
    }

    $name = 'member-' . bin2hex(random_bytes(6)) . '.' . $ext[$info[2]];
    if (!move_uploaded_file($file['tmp_name'], TEAM_PHOTOS . '/' . $name)) {
        return array('error' => 'Не удалось сохранить фотографию.');
    }
    return 'img/team/' . $name;
}

/* ---------- что показываем ---------- */

$data    = team_load();
$faq     = faq_load();
$progs   = programs_load();

$tab = 'team';
if (isset($_GET['tab']) && in_array($_GET['tab'], array('faq', 'prog'), true)) {
    $tab = $_GET['tab'];
}

$editId  = isset($_GET['edit']) ? $_GET['edit'] : null;
$editing = null;
if ($loggedIn && $editId !== null) {
    $i = team_find($data, $editId);
    $editing = $i !== null ? $data['members'][$i] : null;
}
$adding = $loggedIn && isset($_GET['add']);

$faqEditId  = isset($_GET['faqedit']) ? $_GET['faqedit'] : null;
$faqEditing = null;
if ($loggedIn && $faqEditId !== null) {
    $i = faq_find($faq, $faqEditId);
    $faqEditing = $i !== null ? $faq['items'][$i] : null;
}
$faqAdding = $loggedIn && isset($_GET['faqadd']);

$progEditing = null;
if ($loggedIn && isset($_GET['progedit'])) {
    $i = programs_find($progs, $_GET['progedit']);
    $progEditing = $i !== null ? $progs['programs'][$i] : null;
}
$progAdding = $loggedIn && isset($_GET['progadd']);

/* Уровень правится в паре со своей программой: без неё непонятно,
   куда возвращать новый и чьё название подставлять по умолчанию. */
$tierEditing = null;
$tierParent  = null;
if ($loggedIn && isset($_GET['tieredit'])) {
    $found = programs_find_tier($progs, $_GET['tieredit']);
    if ($found !== null) {
        list($pi, $ti) = $found;
        $tierEditing = $progs['programs'][$pi]['tiers'][$ti];
        $tierParent  = $progs['programs'][$pi];
    }
}
$tierAdding = null;
if ($loggedIn && isset($_GET['tieradd'])) {
    $i = programs_find($progs, $_GET['tieradd']);
    $tierAdding = $i !== null ? $progs['programs'][$i] : null;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Управление командой — Киноцех личности</title>
<style>
    :root {
        --gate:#0C100C; --bench:#141A13; --panel:#1A2118; --rail:#2A3327;
        --lime:#C9F23A; --sand:#E8D5AC; --ink:#EDF0E8; --dim:#9AA695; --error:#E8705F;
    }
    * { box-sizing:border-box; }
    body {
        margin:0; padding:32px 20px 80px; background:var(--gate); color:var(--ink);
        font:16px/1.55 system-ui,-apple-system,'Segoe UI',sans-serif;
    }
    .wrap { max-width:860px; margin:0 auto; }
    h1 { font-size:26px; margin:0 0 4px; letter-spacing:-0.01em; }
    h2 { font-size:19px; margin:0 0 16px; }
    .sub { color:var(--dim); margin:0 0 28px; font-size:14px; }
    a { color:var(--lime); }
    .card {
        background:var(--bench); border:1px solid var(--rail); border-radius:2px;
        padding:20px; margin-bottom:14px;
    }
    .row { display:flex; gap:16px; align-items:flex-start; }
    .row img {
        width:76px; height:76px; object-fit:cover; border:1px solid var(--rail);
        background:var(--panel); flex:none;
    }
    .row .grow { flex:1; min-width:0; }
    .name { font-weight:600; font-size:17px; }
    .role { color:var(--dim); font-size:14px; margin-top:2px; }
    .hidden-tag { color:var(--error); font-size:13px; }
    label { display:block; margin:16px 0 6px; font-size:14px; color:var(--sand); }
    .hint { color:var(--dim); font-size:13px; font-weight:400; margin-top:2px; }
    input[type=text], input[type=password], textarea {
        width:100%; padding:10px 12px; background:var(--panel); color:var(--ink);
        border:1px solid var(--rail); border-radius:2px; font:inherit;
    }
    textarea { min-height:150px; resize:vertical; line-height:1.5; }
    input:focus, textarea:focus { outline:none; border-color:var(--lime); }
    button, .btn {
        display:inline-block; padding:10px 18px; border:1px solid var(--rail);
        background:var(--panel); color:var(--ink); border-radius:2px; cursor:pointer;
        font:inherit; text-decoration:none;
    }
    button:hover, .btn:hover { border-color:var(--lime); }
    .primary { background:var(--lime); color:var(--gate); border-color:var(--lime); font-weight:600; }
    .danger:hover { border-color:var(--error); color:var(--error); }
    .tiny { padding:5px 10px; font-size:13px; }
    .msg { padding:12px 16px; border-radius:2px; margin-bottom:20px; }
    .ok  { background:rgba(201,242,58,0.12); border:1px solid var(--lime); }
    .err { background:rgba(232,112,95,0.12); border:1px solid var(--error); }
    .bar { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; gap:12px; flex-wrap:wrap; }
    .acts { display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
    form.inline { display:inline; }
    .narrow { max-width:420px; }
    .tabs { display:flex; gap:4px; }
    .tab {
        padding:9px 16px; border:1px solid var(--rail); border-radius:2px;
        color:var(--dim); text-decoration:none; font-size:14px;
    }
    .tab:hover { color:var(--ink); }
    .tab.on { color:var(--gate); background:var(--lime); border-color:var(--lime); font-weight:600; }
</style>
</head>
<body>
<div class="wrap">

<?php if ($notice): ?><div class="msg ok"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error):  ?><div class="msg err"><?= e($error) ?></div><?php endif; ?>

<?php if ($isSetup): ?>

    <h1>Первый запуск</h1>
    <p class="sub">Задайте пароль для входа в панель. Его нужно будет вводить при каждом заходе.</p>
    <form method="post" class="card narrow">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="setup">
        <label>Пароль <span class="hint">не короче 8 символов</span></label>
        <input type="password" name="password" required autofocus>
        <label>Повторите пароль</label>
        <input type="password" name="password2" required>
        <p><button class="primary" type="submit">Сохранить пароль</button></p>
    </form>

<?php elseif (!$loggedIn): ?>

    <h1>Управление командой</h1>
    <p class="sub">Введите пароль.</p>
    <form method="post" class="card narrow">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="login">
        <label>Пароль</label>
        <input type="password" name="password" required autofocus>
        <p><button class="primary" type="submit">Войти</button></p>
    </form>

<?php elseif ($editing !== null || $adding): ?>

    <?php $m = $editing !== null ? $editing : array('id'=>'','name'=>'','role'=>'','education'=>'','extra'=>'','photo'=>'','visible'=>true); ?>

    <div class="bar">
        <h1><?= $editing !== null ? 'Редактирование' : 'Новый участник' ?></h1>
        <a class="btn" href="./">← К списку</a>
    </div>

    <form method="post" enctype="multipart/form-data" class="card">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= e($m['id']) ?>">

        <label>Имя и фамилия</label>
        <input type="text" name="name" value="<?= e($m['name']) ?>" required autofocus>

        <label>Должность и звания
            <span class="hint">Каждая строка выводится с новой строки на сайте.</span>
        </label>
        <textarea name="role" style="min-height:90px"><?= e($m['role']) ?></textarea>

        <label>Образование
            <span class="hint">Один пункт — один абзац, между пунктами оставляйте пустую строку.
            Первая строка пункта станет жирным заголовком, остальные — пояснением под ним.</span>
        </label>
        <textarea name="education"><?= e($m['education']) ?></textarea>

        <label>Дополнительная информация
            <span class="hint">По одному пункту на строку. Оставьте пустым, если не нужно —
            тогда блок не появится.</span>
        </label>
        <textarea name="extra" style="min-height:110px"><?= e($m['extra']) ?></textarea>

        <label>Фотография
            <span class="hint">JPG, PNG или WebP, до 5 МБ. Лучше квадратная, от 400×400.
            <?= $m['photo'] ? 'Если не выбирать файл, останется текущая.' : '' ?></span>
        </label>
        <?php if ($m['photo']): ?>
            <p><img src="<?= e('../' . $m['photo']) ?>" alt="" style="width:110px;height:110px;object-fit:cover;border:1px solid var(--rail)"></p>
        <?php endif; ?>
        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp">

        <label style="display:flex;align-items:center;gap:8px;margin-top:22px">
            <input type="checkbox" name="visible" value="1" <?= !empty($m['visible']) ? 'checked' : '' ?> style="width:auto">
            Показывать на сайте
        </label>

        <p style="margin-top:24px"><button class="primary" type="submit">Сохранить</button></p>
    </form>

<?php elseif ($progEditing !== null || $progAdding): ?>

    <?php $pr = $progEditing !== null ? $progEditing : array('id'=>'','title'=>'','desc'=>'','icon'=>'star','visible'=>true); ?>

    <div class="bar">
        <h1><?= $progEditing !== null ? 'Программа' : 'Новая программа' ?></h1>
        <a class="btn" href="?tab=prog">← К списку</a>
    </div>

    <form method="post" class="card">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="prog-save">
        <input type="hidden" name="id" value="<?= e($pr['id']) ?>">

        <label>Название направления</label>
        <input type="text" name="title" value="<?= e($pr['title']) ?>" required autofocus>

        <label>Описание под названием
            <span class="hint">Одна-две фразы о сути направления. Показывается над ценами.</span>
        </label>
        <textarea name="desc" style="min-height:90px"><?= e($pr['desc']) ?></textarea>

        <label>Значок</label>
        <select name="icon" style="width:100%;padding:10px 12px;background:var(--panel);color:var(--ink);border:1px solid var(--rail);border-radius:2px;font:inherit">
            <?php foreach (programs_icons() as $key => $ic): ?>
                <option value="<?= e($key) ?>" <?= (isset($pr['icon']) && $pr['icon'] === $key) ? 'selected' : '' ?>><?= e($ic['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <label style="display:flex;align-items:center;gap:8px;margin-top:22px">
            <input type="checkbox" name="visible" value="1" <?= !empty($pr['visible']) ? 'checked' : '' ?> style="width:auto">
            Показывать на сайте
        </label>

        <p style="margin-top:24px"><button class="primary" type="submit">Сохранить</button></p>
    </form>

<?php elseif ($tierEditing !== null || $tierAdding !== null): ?>

    <?php
        $tr     = $tierEditing !== null ? $tierEditing : array('id'=>'','price'=>'','unit'=>'₽/мес','meta'=>'','desc'=>'','label'=>'','tier'=>'both');
        $parent = $tierEditing !== null ? $tierParent : $tierAdding;
    ?>

    <div class="bar">
        <h1><?= $tierEditing !== null ? 'Цена и наполнение' : 'Новый уровень' ?></h1>
        <a class="btn" href="?tab=prog">← К списку</a>
    </div>

    <p class="sub">Направление: <?= e($parent['title']) ?></p>

    <form method="post" class="card">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="tier-save">
        <input type="hidden" name="id" value="<?= e($tr['id']) ?>">
        <input type="hidden" name="program" value="<?= e($parent['id']) ?>">

        <label>Цена
            <span class="hint">Только число, например 10 000. Единицы — в поле ниже.</span>
        </label>
        <input type="text" name="price" value="<?= e($tr['price']) ?>" required autofocus>

        <label>Единицы после цены
            <span class="hint">Например «₽/мес» или «₽/мес — 14 000 ₽ за курс». Показывается мелким шрифтом под ценой.</span>
        </label>
        <input type="text" name="unit" value="<?= e($tr['unit']) ?>">

        <label>Строка условий
            <span class="hint">Длительность и размер группы: «2 месяца · группы 8–12 чел.»</span>
        </label>
        <input type="text" name="meta" value="<?= e($tr['meta']) ?>">

        <label>Наполнение
            <span class="hint">Текст под ценой: что входит и чем заканчивается курс.</span>
        </label>
        <textarea name="desc" style="min-height:110px"><?= e($tr['desc']) ?></textarea>

        <label>Название в форме записи
            <span class="hint">Как этот уровень называется в выпадающем списке и в письме с заявкой.
            Например «Актёр: суть (8–12 лет, База)».</span>
        </label>
        <input type="text" name="label" value="<?= e($tr['label']) ?>">

        <label>Возрастная группа
            <span class="hint">Определяет, при каком положении переключателя на сайте виден этот уровень.</span>
        </label>
        <select name="tier" style="width:100%;padding:10px 12px;background:var(--panel);color:var(--ink);border:1px solid var(--rail);border-radius:2px;font:inherit">
            <option value="base" <?= $tr['tier'] === 'base' ? 'selected' : '' ?>>База — 8–12 лет</option>
            <option value="pro"  <?= $tr['tier'] === 'pro'  ? 'selected' : '' ?>>Про — 13–17 лет</option>
            <option value="both" <?= $tr['tier'] === 'both' ? 'selected' : '' ?>>Виден всегда</option>
        </select>

        <p style="margin-top:24px"><button class="primary" type="submit">Сохранить</button></p>
    </form>

<?php elseif ($faqEditing !== null || $faqAdding): ?>

    <?php $q = $faqEditing !== null ? $faqEditing : array('id'=>'','q'=>'','a'=>'','visible'=>true); ?>

    <div class="bar">
        <h1><?= $faqEditing !== null ? 'Редактирование вопроса' : 'Новый вопрос' ?></h1>
        <a class="btn" href="?tab=faq">← К списку</a>
    </div>

    <form method="post" class="card">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="faq-save">
        <input type="hidden" name="id" value="<?= e($q['id']) ?>">

        <label>Вопрос
            <span class="hint">Так, как его задают родители — своими словами, а не канцелярски.</span>
        </label>
        <input type="text" name="q" value="<?= e($q['q']) ?>" required autofocus>

        <label>Ответ
            <span class="hint">Между абзацами оставляйте пустую строку. Чем конкретнее ответ,
            тем меньше поводов не записаться.</span>
        </label>
        <textarea name="a" style="min-height:190px"><?= e($q['a']) ?></textarea>

        <label style="display:flex;align-items:center;gap:8px;margin-top:22px">
            <input type="checkbox" name="visible" value="1" <?= !empty($q['visible']) ? 'checked' : '' ?> style="width:auto">
            Показывать на сайте
        </label>

        <p style="margin-top:24px"><button class="primary" type="submit">Сохранить</button></p>
    </form>

<?php else: ?>

    <div class="bar">
        <div class="tabs">
            <a class="tab <?= $tab === 'team' ? 'on' : '' ?>" href="?">Команда</a>
            <a class="tab <?= $tab === 'prog' ? 'on' : '' ?>" href="?tab=prog">Программы</a>
            <a class="tab <?= $tab === 'faq'  ? 'on' : '' ?>" href="?tab=faq">Вопросы родителей</a>
        </div>
        <div class="acts">
            <a class="btn" href="../" target="_blank">Открыть сайт</a>
            <a class="btn" href="?logout=1">Выйти</a>
        </div>
    </div>

<?php if ($tab === 'prog'): ?>

    <div class="bar">
        <div>
            <h1>Программы обучения</h1>
            <p class="sub" style="margin:0">Цены и наполнение меняются здесь. Они же подставляются в форму записи и в письмо с заявкой.</p>
        </div>
        <a class="btn primary" href="?progadd=1">+ Добавить направление</a>
    </div>

    <?php if (!$progs['programs']): ?>
        <div class="card"><p style="margin:0;color:var(--dim)">Пока пусто. Нажмите «Добавить направление».</p></div>
    <?php endif; ?>

    <?php foreach ($progs['programs'] as $i => $pr): ?>
        <div class="card">
            <div class="name">
                <?= e($pr['title']) ?>
                <?php if (empty($pr['visible'])): ?><span class="hidden-tag">· скрыто</span><?php endif; ?>
            </div>
            <div class="role"><?= e(mb_substr(preg_replace('/\s+/u', ' ', isset($pr['desc']) ? $pr['desc'] : ''), 0, 140)) ?></div>

            <div class="acts" style="margin-top:12px">
                <a class="btn tiny" href="?progedit=<?= e($pr['id']) ?>">Название и описание</a>

                <form method="post" class="inline">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="prog-move">
                    <input type="hidden" name="id" value="<?= e($pr['id']) ?>">
                    <button class="tiny" name="dir" value="up" <?= $i === 0 ? 'disabled' : '' ?>>↑</button>
                </form>

                <form method="post" class="inline">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="prog-move">
                    <input type="hidden" name="id" value="<?= e($pr['id']) ?>">
                    <button class="tiny" name="dir" value="down" <?= $i === count($progs['programs']) - 1 ? 'disabled' : '' ?>>↓</button>
                </form>

                <form method="post" class="inline" onsubmit="return confirm('Удалить направление «<?= e($pr['title']) ?>» вместе со всеми ценами?')">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="prog-delete">
                    <input type="hidden" name="id" value="<?= e($pr['id']) ?>">
                    <button class="tiny danger" type="submit">Удалить</button>
                </form>
            </div>

            <!-- Уровни направления: именно здесь живут цена и наполнение -->
            <div style="margin-top:16px;border-top:1px solid var(--rail);padding-top:14px">
                <?php foreach ((isset($pr['tiers']) ? $pr['tiers'] : array()) as $tr): ?>
                    <div style="display:flex;gap:14px;align-items:baseline;flex-wrap:wrap;padding:9px 0;border-bottom:1px solid var(--rail)">
                        <span style="font-family:monospace;font-size:17px;color:var(--lime);font-weight:600;min-width:96px">
                            <?= e($tr['price']) ?>
                        </span>
                        <span style="color:var(--dim);font-size:13px;flex:1;min-width:180px">
                            <?= e($tr['meta']) ?>
                        </span>
                        <span class="acts">
                            <a class="btn tiny" href="?tieredit=<?= e($tr['id']) ?>">Цена и наполнение</a>
                            <form method="post" class="inline" onsubmit="return confirm('Удалить этот уровень?')">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="tier-delete">
                                <input type="hidden" name="id" value="<?= e($tr['id']) ?>">
                                <button class="tiny danger" type="submit">×</button>
                            </form>
                        </span>
                    </div>
                <?php endforeach; ?>

                <p style="margin-top:12px">
                    <a class="btn tiny" href="?tieradd=<?= e($pr['id']) ?>">+ Добавить уровень</a>
                </p>
            </div>
        </div>
    <?php endforeach; ?>

<?php elseif ($tab === 'faq'): ?>

    <div class="bar">
        <div>
            <h1>Вопросы родителей</h1>
            <p class="sub" style="margin:0">Вопросов: <?= count($faq['items']) ?>. Раздел стоит на сайте прямо перед формой записи.</p>
        </div>
        <a class="btn primary" href="?faqadd=1">+ Добавить вопрос</a>
    </div>

    <?php if (!$faq['items']): ?>
        <div class="card"><p style="margin:0;color:var(--dim)">Пока пусто. Если не добавить ни одного вопроса, раздел на сайте не появится вовсе.</p></div>
    <?php endif; ?>

    <?php foreach ($faq['items'] as $i => $item): ?>
        <div class="card">
            <div class="name">
                <?= e($item['q']) ?>
                <?php if (empty($item['visible'])): ?><span class="hidden-tag">· скрыт</span><?php endif; ?>
            </div>
            <div class="role"><?= e(mb_substr(preg_replace('/\s+/u', ' ', isset($item['a']) ? $item['a'] : ''), 0, 150)) ?></div>

            <div class="acts" style="margin-top:12px">
                <a class="btn tiny" href="?faqedit=<?= e($item['id']) ?>">Редактировать</a>

                <form method="post" class="inline">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="faq-move">
                    <input type="hidden" name="id" value="<?= e($item['id']) ?>">
                    <button class="tiny" name="dir" value="up" <?= $i === 0 ? 'disabled' : '' ?>>↑</button>
                </form>

                <form method="post" class="inline">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="faq-move">
                    <input type="hidden" name="id" value="<?= e($item['id']) ?>">
                    <button class="tiny" name="dir" value="down" <?= $i === count($faq['items']) - 1 ? 'disabled' : '' ?>>↓</button>
                </form>

                <form method="post" class="inline" onsubmit="return confirm('Удалить этот вопрос? Отменить будет нельзя.')">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="faq-delete">
                    <input type="hidden" name="id" value="<?= e($item['id']) ?>">
                    <button class="tiny danger" type="submit">Удалить</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>

<?php else: ?>

    <div class="bar">
        <div>
            <h1>Команда</h1>
            <p class="sub" style="margin:0">Участников: <?= count($data['members']) ?>. Порядок в списке — порядок на сайте.</p>
        </div>
        <a class="btn primary" href="?add=1">+ Добавить участника</a>
    </div>

    <?php if (!$data['members']): ?>
        <div class="card"><p style="margin:0;color:var(--dim)">Пока никого нет. Нажмите «Добавить участника».</p></div>
    <?php endif; ?>

    <?php foreach ($data['members'] as $i => $m): ?>
        <div class="card">
            <div class="row">
                <?php if (!empty($m['photo'])): ?>
                    <img src="<?= e('../' . $m['photo']) ?>" alt="">
                <?php else: ?>
                    <img src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==" alt="">
                <?php endif; ?>

                <div class="grow">
                    <div class="name">
                        <?= e($m['name']) ?>
                        <?php if (empty($m['visible'])): ?><span class="hidden-tag">· скрыт</span><?php endif; ?>
                    </div>
                    <div class="role"><?= e(mb_substr(preg_replace('/\s+/u', ' ', $m['role']), 0, 130)) ?></div>

                    <div class="acts" style="margin-top:12px">
                        <a class="btn tiny" href="?edit=<?= e($m['id']) ?>">Редактировать</a>

                        <form method="post" class="inline">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="move">
                            <input type="hidden" name="id" value="<?= e($m['id']) ?>">
                            <button class="tiny" name="dir" value="up" <?= $i === 0 ? 'disabled' : '' ?>>↑</button>
                        </form>

                        <form method="post" class="inline">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="move">
                            <input type="hidden" name="id" value="<?= e($m['id']) ?>">
                            <button class="tiny" name="dir" value="down" <?= $i === count($data['members']) - 1 ? 'disabled' : '' ?>>↓</button>
                        </form>

                        <form method="post" class="inline" onsubmit="return confirm('Удалить <?= e($m['name']) ?>? Отменить будет нельзя.')">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= e($m['id']) ?>">
                            <button class="tiny danger" type="submit">Удалить</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

<?php endif; /* конец переключения вкладок */ ?>

    <div class="card" style="margin-top:32px">
        <h2>Сменить пароль</h2>
        <form method="post" class="narrow">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="password">
            <label>Новый пароль <span class="hint">не короче 8 символов</span></label>
            <input type="password" name="password" required>
            <label>Повторите пароль</label>
            <input type="password" name="password2" required>
            <p><button type="submit">Сменить</button></p>
        </form>
    </div>

<?php endif; /* конец выбора экрана: установка, вход, форма, список */ ?>

</div>
</body>
</html>
