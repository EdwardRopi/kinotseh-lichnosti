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
$editId  = isset($_GET['edit']) ? $_GET['edit'] : null;
$editing = null;
if ($loggedIn && $editId !== null) {
    $i = team_find($data, $editId);
    $editing = $i !== null ? $data['members'][$i] : null;
}
$adding = $loggedIn && isset($_GET['add']);
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

<?php else: ?>

    <div class="bar">
        <div>
            <h1>Команда</h1>
            <p class="sub" style="margin:0">Участников: <?= count($data['members']) ?>. Порядок в списке — порядок на сайте.</p>
        </div>
        <div class="acts">
            <a class="btn primary" href="?add=1">+ Добавить участника</a>
            <a class="btn" href="../" target="_blank">Открыть сайт</a>
            <a class="btn" href="?logout=1">Выйти</a>
        </div>
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

<?php endif; ?>

</div>
</body>
</html>
