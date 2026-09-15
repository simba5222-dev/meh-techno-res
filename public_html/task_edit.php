<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_admin();

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM tasks WHERE id = ?');
$stmt->execute([$id]);
$task = $stmt->fetch();

if (!$task) {
    flash('error', 'Задача не найдена.');
    redirect('tasks.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $priority = $_POST['priority'] ?? 'normal';
    $dueDate = $_POST['due_date'] ?? '';
    $equipmentId = (int) ($_POST['equipment_id'] ?? 0);

    if ($title === '') $errors[] = 'Укажите заголовок задачи.';
    if (!$equipmentId) $errors[] = 'Выберите технику.';
    if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) $errors[] = 'Некорректный приоритет.';
    if ($dueDate !== '' && !strtotime($dueDate)) $errors[] = 'Некорректная дата.';

    if (!$errors) {
        $stmt = db()->prepare('UPDATE tasks SET title=?, description=?, priority=?, due_date=?, equipment_id=? WHERE id=?');
        $stmt->execute([$title, $description ?: null, $priority, $dueDate ?: null, $equipmentId, $id]);
        flash('success', 'Задача обновлена.');
        redirect('task_view.php?id=' . $id);
    }

    $task['title'] = $title;
    $task['description'] = $description;
    $task['priority'] = $priority;
    $task['due_date'] = $dueDate;
    $task['equipment_id'] = $equipmentId;
}

$equipmentList = db()->query('SELECT id, inventory_number, name FROM equipment WHERE is_active = 1 ORDER BY name')->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>Редактирование задачи #<?= (int) $task['id'] ?></h1>
    <a href="task_view.php?id=<?= (int) $task['id'] ?>" class="btn-link">← К задаче</a>
</div>

<?php foreach ($errors as $err): ?>
    <div class="flash flash-error"><?= e($err) ?></div>
<?php endforeach; ?>

<form method="post" class="form-card">
    <?= csrf_field() ?>
    <label>Техника *
        <select name="equipment_id" required>
            <?php foreach ($equipmentList as $eq): ?>
                <option value="<?= (int) $eq['id'] ?>" <?= (int) $task['equipment_id'] === (int) $eq['id'] ? 'selected' : '' ?>>
                    <?= e($eq['inventory_number'] . ' — ' . $eq['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Заголовок *
        <input type="text" name="title" required maxlength="255" value="<?= e($task['title']) ?>">
    </label>
    <label>Описание
        <textarea name="description" rows="4"><?= e($task['description']) ?></textarea>
    </label>
    <div class="form-row">
        <label>Приоритет
            <select name="priority">
                <option value="low" <?= $task['priority'] === 'low' ? 'selected' : '' ?>>Низкий</option>
                <option value="normal" <?= $task['priority'] === 'normal' ? 'selected' : '' ?>>Обычный</option>
                <option value="high" <?= $task['priority'] === 'high' ? 'selected' : '' ?>>Высокий</option>
                <option value="urgent" <?= $task['priority'] === 'urgent' ? 'selected' : '' ?>>Срочно</option>
            </select>
        </label>
        <label>Срок выполнения
            <input type="date" name="due_date" value="<?= e($task['due_date'] ?? '') ?>">
        </label>
    </div>
    <button type="submit" class="btn btn-primary">Сохранить</button>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
