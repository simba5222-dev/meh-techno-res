<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_admin();

$errors = [];

// --- Создание объекта ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    csrf_verify();

    $name = trim($_POST['name'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($name === '') {
        $errors[] = 'Укажите название объекта.';
    }

    if (!$errors) {
        try {
            $stmt = db()->prepare('INSERT INTO objects (name, code, notes) VALUES (?,?,?)');
            $stmt->execute([$name, $code ?: null, $notes ?: null]);
            flash('success', 'Объект добавлен.');
            redirect('objects.php');
        } catch (PDOException $ex) {
            $errors[] = $ex->getCode() === '23000'
                ? 'Объект с таким названием уже есть.'
                : ('Ошибка: ' . $ex->getMessage());
        }
    }
}

// --- Переименование / правка ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    csrf_verify();

    $id = (int) ($_POST['object_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if (!$id) {
        $errors[] = 'Объект не найден.';
    }
    if ($name === '') {
        $errors[] = 'Название не может быть пустым.';
    }

    if (!$errors) {
        try {
            $stmt = db()->prepare('UPDATE objects SET name=?, code=?, notes=? WHERE id=?');
            $stmt->execute([$name, $code ?: null, $notes ?: null, $id]);
            flash('success', 'Объект обновлён.');
            redirect('objects.php');
        } catch (PDOException $ex) {
            $errors[] = $ex->getCode() === '23000'
                ? 'Объект с таким названием уже есть.'
                : ('Ошибка: ' . $ex->getMessage());
        }
    }
}

// --- Включить / отключить объект ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_active') {
    csrf_verify();

    $id = (int) ($_POST['object_id'] ?? 0);
    if ($id) {
        $stmt = db()->prepare('UPDATE objects SET is_active = NOT is_active WHERE id = ?');
        $stmt->execute([$id]);
        flash('success', 'Статус объекта изменён.');
    }
    redirect('objects.php');
}

$editId = (int) ($_GET['edit'] ?? 0);
$editRow = $editId ? object_get($editId) : null;

// Список объектов со счётчиками людей и техники
$objects = db()->query(
    "SELECT o.*,
            (SELECT COUNT(*) FROM user_objects uo JOIN users u ON u.id = uo.user_id
              WHERE uo.object_id = o.id AND u.is_active = 1 AND u.role = 'mechanic') AS mechanics_count,
            (SELECT COUNT(*) FROM user_objects uo JOIN users u ON u.id = uo.user_id
              WHERE uo.object_id = o.id AND u.is_active = 1 AND u.role = 'fitter') AS fitters_count,
            (SELECT COUNT(*) FROM equipment eq
              WHERE eq.object_id = o.id AND eq.is_active = 1) AS equipment_count
       FROM objects o
      ORDER BY o.is_active DESC, o.name"
)->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>Объекты</h1>
</div>

<?php foreach ($errors as $err): ?>
    <div class="flash flash-error"><?= e($err) ?></div>
<?php endforeach; ?>

<div class="table-wrap">
<table class="data-table">
    <thead>
        <tr><th>Название</th><th>Код</th><th>Механики</th><th>Слесари</th><th>Техника</th><th>Статус</th><th></th></tr>
    </thead>
    <tbody>
    <?php if (!$objects): ?>
        <tr><td colspan="7">Объектов пока нет. Добавьте первый в форме ниже.</td></tr>
    <?php endif; ?>
    <?php foreach ($objects as $o): ?>
        <tr>
            <td><?= e($o['name']) ?><?php if ($o['notes']): ?><br><span class="hint"><?= e($o['notes']) ?></span><?php endif; ?></td>
            <td><?= e($o['code'] ?? '—') ?></td>
            <td><?= (int) $o['mechanics_count'] ?></td>
            <td><?= (int) $o['fitters_count'] ?></td>
            <td><?= (int) $o['equipment_count'] ?></td>
            <td><span class="badge <?= $o['is_active'] ? 'badge-verified' : 'badge-rejected' ?>"><?= $o['is_active'] ? 'Активен' : 'Отключён' ?></span></td>
            <td class="actions-cell">
                <a href="objects.php?edit=<?= (int) $o['id'] ?>">Изменить</a>
                · <form method="post" class="inline-form" onsubmit="return confirm('Изменить статус объекта?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle_active">
                    <input type="hidden" name="object_id" value="<?= (int) $o['id'] ?>">
                    <button type="submit" class="btn-link"><?= $o['is_active'] ? 'Отключить' : 'Включить' ?></button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php if ($editRow): ?>
    <h2>Редактирование объекта</h2>
    <form method="post" class="form-card">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="object_id" value="<?= (int) $editRow['id'] ?>">
        <div class="form-row">
            <label>Название *
                <input type="text" name="name" required maxlength="255" value="<?= e($editRow['name']) ?>">
            </label>
            <label>Код <span class="hint">(необязательно)</span>
                <input type="text" name="code" maxlength="50" value="<?= e($editRow['code'] ?? '') ?>">
            </label>
        </div>
        <label>Примечание
            <textarea name="notes" rows="2"><?= e($editRow['notes'] ?? '') ?></textarea>
        </label>
        <button type="submit" class="btn btn-primary">Сохранить</button>
        <a href="objects.php" class="btn-link">Отмена</a>
    </form>
<?php else: ?>
    <h2>Добавить объект</h2>
    <form method="post" class="form-card">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="form-row">
            <label>Название *
                <input type="text" name="name" required maxlength="255" placeholder="напр. Участок №1">
            </label>
            <label>Код <span class="hint">(необязательно)</span>
                <input type="text" name="code" maxlength="50">
            </label>
        </div>
        <label>Примечание
            <textarea name="notes" rows="2"></textarea>
        </label>
        <button type="submit" class="btn btn-primary">Добавить объект</button>
    </form>
<?php endif; ?>

<p class="hint">Объекты не удаляются, а отключаются — так сохраняется история задач по закрытым участкам.
Отключённый объект перестаёт предлагаться при постановке задач и закреплении людей.</p>

<?php require __DIR__ . '/includes/footer.php'; ?>
