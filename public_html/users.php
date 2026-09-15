<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_admin();

$errors = [];
$allObjects = objects_list(true);

// Роли, которые может назначать главный механик
const ROLE_CHOICES = ['admin', 'mechanic', 'fitter', 'manager'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    csrf_verify();

    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $role     = $_POST['role'] ?? 'mechanic';
    $phone    = trim($_POST['phone'] ?? '');
    $objectIds = array_map('intval', (array) ($_POST['object_ids'] ?? []));

    // Слесарь заводится карточкой без доступа на сайт
    $canLogin = $role !== 'fitter';

    if ($fullName === '') $errors[] = 'Укажите ФИО.';
    if (!in_array($role, ROLE_CHOICES, true)) $errors[] = 'Некорректная роль.';

    if ($canLogin) {
        if ($username === '') $errors[] = 'Укажите логин.';
        if ($username !== '' && !preg_match('/^[A-Za-z0-9_.\-]{3,100}$/', $username)) {
            $errors[] = 'Логин: латиница, цифры, точка, дефис, подчёркивание, от 3 символов.';
        }
        if (strlen($password) < 6) $errors[] = 'Пароль должен быть не короче 6 символов.';
    }

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO users (full_name, username, password_hash, role, can_login, phone) VALUES (?,?,?,?,?,?)'
            );
            $stmt->execute([
                $fullName,
                $canLogin ? $username : null,
                $canLogin ? password_hash($password, PASSWORD_DEFAULT) : null,
                $role,
                $canLogin ? 1 : 0,
                $phone ?: null,
            ]);
            $newId = (int) $pdo->lastInsertId();

            if (in_array($role, ['mechanic', 'fitter'], true)) {
                set_user_objects($newId, $objectIds);
            }

            $pdo->commit();
            flash('success', 'Сотрудник добавлен.');
            redirect('users.php');
        } catch (PDOException $ex) {
            $pdo->rollBack();
            $errors[] = $ex->getCode() === '23000' ? 'Такой логин уже занят.' : ('Ошибка: ' . $ex->getMessage());
        }
    }
}

// Быстрые действия: деактивировать/активировать
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_active') {
    csrf_verify();
    $targetId = (int) ($_POST['user_id'] ?? 0);
    if ($targetId && $targetId !== (int) $user['id']) {
        $stmt = db()->prepare('UPDATE users SET is_active = NOT is_active WHERE id = ?');
        $stmt->execute([$targetId]);
        flash('success', 'Статус учётной записи обновлён.');
    } else {
        flash('error', 'Нельзя отключить собственную учётную запись.');
    }
    redirect('users.php');
}

// Вкладки: с доступом на сайт / без доступа (слесари)
$tab = ($_GET['tab'] ?? 'staff') === 'fitters' ? 'fitters' : 'staff';

$sql = "SELECT * FROM users WHERE role " . ($tab === 'fitters' ? '=' : '!=') . " 'fitter'
        ORDER BY FIELD(role,'admin','manager','mechanic','fitter'), full_name ASC";
$users = db()->query($sql)->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>Сотрудники</h1>
</div>

<?php foreach ($errors as $err): ?>
    <div class="flash flash-error"><?= e($err) ?></div>
<?php endforeach; ?>

<div class="report-tabs">
    <a href="users.php?tab=staff" class="report-tab <?= $tab === 'staff' ? 'active' : '' ?>">С доступом на сайт</a>
    <a href="users.php?tab=fitters" class="report-tab <?= $tab === 'fitters' ? 'active' : '' ?>">Слесари</a>
</div>

<div class="table-wrap">
<table class="data-table">
    <thead>
        <tr>
            <th>ФИО</th>
            <?php if ($tab === 'staff'): ?><th>Логин</th><?php endif; ?>
            <th>Роль</th>
            <th>Объекты</th>
            <th>Телефон</th>
            <th>Статус</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php if (!$users): ?>
        <tr><td colspan="7">Записей пока нет.</td></tr>
    <?php endif; ?>
    <?php foreach ($users as $u): ?>
        <tr>
            <td><?= e($u['full_name']) ?></td>
            <?php if ($tab === 'staff'): ?><td><?= e($u['username'] ?? '—') ?></td><?php endif; ?>
            <td><?= e(role_label($u['role'])) ?></td>
            <td><?= in_array($u['role'], ['mechanic','fitter'], true) ? e(user_objects_label((int) $u['id'])) : '—' ?></td>
            <td><?= e($u['phone'] ?? '—') ?></td>
            <td><span class="badge <?= $u['is_active'] ? 'badge-verified' : 'badge-rejected' ?>"><?= $u['is_active'] ? 'Активен' : 'Отключён' ?></span></td>
            <td class="actions-cell">
                <a href="user_edit.php?id=<?= (int) $u['id'] ?>">Изменить</a>
                <?php if ((int) $u['id'] !== (int) $user['id']): ?>
                    · <form method="post" class="inline-form" onsubmit="return confirm('Изменить статус учётной записи?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle_active">
                        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                        <button type="submit" class="btn-link"><?= $u['is_active'] ? 'Отключить' : 'Включить' ?></button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<h2>Добавить сотрудника</h2>
<form method="post" class="form-card" id="userCreateForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="form-row">
        <label>ФИО *
            <input type="text" name="full_name" required value="<?= e($_POST['full_name'] ?? '') ?>">
        </label>
        <label>Роль
            <select name="role" id="roleSelect">
                <option value="mechanic">Механик участка</option>
                <option value="fitter">Слесарь (без доступа на сайт)</option>
                <option value="admin">Главный механик</option>
                <option value="manager">Менеджер (только просмотр техники)</option>
            </select>
        </label>
    </div>

    <div id="loginFields">
        <div class="form-row">
            <label>Логин *
                <input type="text" name="username" value="<?= e($_POST['username'] ?? '') ?>">
            </label>
            <label>Пароль *
                <input type="text" name="password" placeholder="не короче 6 символов">
            </label>
        </div>
    </div>

    <div class="form-row">
        <label>Телефон
            <input type="text" name="phone" value="<?= e($_POST['phone'] ?? '') ?>">
        </label>
    </div>

    <div id="objectFields">
        <label>Закрепить за объектами</label>
        <?php if (!$allObjects): ?>
            <p class="hint">Объектов пока нет — сначала заведите их в разделе «Объекты».</p>
        <?php else: ?>
            <div class="checkbox-grid">
                <?php foreach ($allObjects as $o): ?>
                    <label class="checkbox-label">
                        <input type="checkbox" name="object_ids[]" value="<?= (int) $o['id'] ?>">
                        <?= e($o['name']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="hint">Механик может вести несколько объектов. Слесарь обычно закреплён за одним.</p>
        <?php endif; ?>
    </div>

    <button type="submit" class="btn btn-primary">Создать</button>
</form>

<script>
(function () {
    var roleSelect = document.getElementById('roleSelect');
    var loginFields = document.getElementById('loginFields');
    var objectFields = document.getElementById('objectFields');
    if (!roleSelect) { return; }

    function sync() {
        var role = roleSelect.value;
        var needsLogin = role !== 'fitter';
        var needsObjects = (role === 'mechanic' || role === 'fitter');

        loginFields.style.display = needsLogin ? '' : 'none';
        objectFields.style.display = needsObjects ? '' : 'none';

        loginFields.querySelectorAll('input').forEach(function (input) {
            input.required = needsLogin;
            if (!needsLogin) { input.value = ''; }
        });
    }

    roleSelect.addEventListener('change', sync);
    sync();
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
