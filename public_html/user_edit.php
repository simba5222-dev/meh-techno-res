<?php
require __DIR__ . '/includes/bootstrap.php';
$admin = require_admin();

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$id]);
$target = $stmt->fetch();

if (!$target) {
    flash('error', 'Сотрудник не найден.');
    redirect('users.php');
}

$errors = [];
$allObjects = objects_list(true);
$selectedObjects = user_object_ids($id);
$roleChoices = ['admin', 'mechanic', 'fitter', 'manager'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $fullName = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $role = $_POST['role'] ?? $target['role'];
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $selectedObjects = array_map('intval', (array) ($_POST['object_ids'] ?? []));
    $maxUserId = trim($_POST['max_user_id'] ?? '');

    if ($fullName === '') $errors[] = 'Укажите ФИО.';
    if (!in_array($role, $roleChoices, true)) $errors[] = 'Некорректная роль.';
    if ($newPassword !== '' && strlen($newPassword) < 6) $errors[] = 'Новый пароль должен быть не короче 6 символов.';
    if ($maxUserId !== '' && !preg_match('/^\d+$/', $maxUserId)) $errors[] = 'MAX ID — только цифры (id из лога бота).';

    if ((int) $target['id'] === (int) $admin['id'] && $role !== 'admin') {
        $errors[] = 'Нельзя понизить роль собственной учётной записи.';
    }

    // Перевод в слесари снимает доступ на сайт; обратный перевод его возвращает,
    // но войти получится только после установки пароля.
    $canLogin = $role !== 'fitter' ? 1 : 0;

    if ($role === 'fitter' && $newPassword !== '') {
        $errors[] = 'Слесарю пароль не нужен — у него нет доступа на сайт.';
    }

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($role === 'fitter') {
                // Логин освобождаем, чтобы он не занимал уникальное имя
                $stmt = $pdo->prepare('UPDATE users SET full_name=?, phone=?, role=?, can_login=0, username=NULL, password_hash=NULL WHERE id=?');
                $stmt->execute([$fullName, $phone ?: null, $role, $id]);
            } elseif ($newPassword !== '') {
                $stmt = $pdo->prepare('UPDATE users SET full_name=?, phone=?, role=?, can_login=?, password_hash=? WHERE id=?');
                $stmt->execute([$fullName, $phone ?: null, $role, $canLogin, password_hash($newPassword, PASSWORD_DEFAULT), $id]);
            } else {
                $stmt = $pdo->prepare('UPDATE users SET full_name=?, phone=?, role=?, can_login=? WHERE id=?');
                $stmt->execute([$fullName, $phone ?: null, $role, $canLogin, $id]);
            }

            if (in_array($role, ['mechanic', 'fitter'], true)) {
                set_user_objects($id, $selectedObjects);
            } else {
                set_user_objects($id, []);
            }

            $maxIdStmt = $pdo->prepare('UPDATE users SET max_user_id = ? WHERE id = ?');
            $maxIdStmt->execute([$maxUserId !== '' ? $maxUserId : null, $id]);

            $pdo->commit();
            flash('success', 'Данные сотрудника обновлены.');
            redirect('users.php' . ($role === 'fitter' ? '?tab=fitters' : ''));
        } catch (PDOException $ex) {
            $pdo->rollBack();
            $errors[] = $ex->getCode() === '23000'
                ? 'Этот MAX ID уже привязан к другому сотруднику.'
                : ('Ошибка сохранения: ' . $ex->getMessage());
        }
    }

    $target['full_name'] = $fullName;
    $target['phone'] = $phone;
    $target['role'] = $role;
    $target['max_user_id'] = $maxUserId;
}

$isSelf = (int) $target['id'] === (int) $admin['id'];

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>Редактирование сотрудника</h1>
    <a href="users.php" class="btn-link">← К списку</a>
</div>

<?php foreach ($errors as $err): ?>
    <div class="flash flash-error"><?= e($err) ?></div>
<?php endforeach; ?>

<form method="post" class="form-card">
    <?= csrf_field() ?>
    <div class="form-row">
        <label>ФИО *
            <input type="text" name="full_name" required value="<?= e($target['full_name']) ?>">
        </label>
        <label>Логин
            <input type="text" value="<?= e($target['username'] ?? '— нет доступа на сайт —') ?>" disabled>
        </label>
    </div>
    <div class="form-row">
        <label>Телефон
            <input type="text" name="phone" value="<?= e($target['phone'] ?? '') ?>">
        </label>
        <label>Роль
            <select name="role" id="roleSelect" <?= $isSelf ? 'disabled' : '' ?>>
                <option value="mechanic" <?= $target['role'] === 'mechanic' ? 'selected' : '' ?>>Механик участка</option>
                <option value="fitter" <?= $target['role'] === 'fitter' ? 'selected' : '' ?>>Слесарь (без доступа на сайт)</option>
                <option value="admin" <?= $target['role'] === 'admin' ? 'selected' : '' ?>>Главный механик</option>
                <option value="manager" <?= $target['role'] === 'manager' ? 'selected' : '' ?>>Менеджер (только просмотр техники)</option>
            </select>
            <?php if ($isSelf): ?>
                <input type="hidden" name="role" value="admin">
            <?php endif; ?>
        </label>
    </div>

    <div id="passwordField">
        <label>Новый пароль <span class="hint">(оставьте пустым, если менять не нужно)</span>
            <input type="text" name="new_password" minlength="6" placeholder="не короче 6 символов">
        </label>
    </div>

    <div class="form-row">
        <label>MAX ID <span class="hint">(id отправителя из лога бота — привязка отчётов в чате MAX)</span>
            <input type="text" name="max_user_id" pattern="\d*" placeholder="только цифры, оставьте пустым, если не привязан"
                value="<?= e((string) ($target['max_user_id'] ?? '')) ?>">
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
                        <input type="checkbox" name="object_ids[]" value="<?= (int) $o['id'] ?>"
                            <?= in_array((int) $o['id'], $selectedObjects, true) ? 'checked' : '' ?>>
                        <?= e($o['name']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <button type="submit" class="btn btn-primary">Сохранить</button>
</form>

<script>
(function () {
    var roleSelect = document.getElementById('roleSelect');
    var passwordField = document.getElementById('passwordField');
    var objectFields = document.getElementById('objectFields');
    if (!roleSelect) { return; }

    function sync() {
        var role = roleSelect.value;
        passwordField.style.display = (role === 'fitter') ? 'none' : '';
        objectFields.style.display = (role === 'mechanic' || role === 'fitter') ? '' : 'none';
        if (role === 'fitter') {
            passwordField.querySelectorAll('input').forEach(function (i) { i.value = ''; });
        }
    }

    roleSelect.addEventListener('change', sync);
    sync();
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
