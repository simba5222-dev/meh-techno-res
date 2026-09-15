<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_admin();

$equipmentList = db()->query("SELECT id, inventory_number, name FROM equipment WHERE is_active = 1 ORDER BY name")->fetchAll();
$mechanics = db()->query(
    "SELECT u.id, u.full_name,
            (SELECT GROUP_CONCAT(uo.object_id) FROM user_objects uo WHERE uo.user_id = u.id) AS object_ids
       FROM users u
      WHERE u.role = 'mechanic' AND u.is_active = 1
      ORDER BY u.full_name"
)->fetchAll();

$objectsList = objects_list(true);

$form = [
    'equipment_id' => (int) ($_GET['equipment_id'] ?? 0),
    'object_id' => '',
    'title' => '',
    'description' => '',
    'priority' => 'normal',
    'assignee_id' => '',
    'due_date' => '',
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $form['equipment_id'] = (int) ($_POST['equipment_id'] ?? 0);
    $form['object_id'] = $_POST['object_id'] ?? '';
    $form['title'] = trim($_POST['title'] ?? '');
    $form['description'] = trim($_POST['description'] ?? '');
    $form['priority'] = $_POST['priority'] ?? 'normal';
    $form['assignee_id'] = $_POST['assignee_id'] ?? '';
    $form['due_date'] = $_POST['due_date'] ?? '';

    if (!$form['equipment_id']) $errors[] = 'Выберите технику.';
    if ($form['title'] === '') $errors[] = 'Укажите краткое описание задачи (заголовок).';
    if (!in_array($form['priority'], ['low', 'normal', 'high', 'urgent'], true)) $errors[] = 'Некорректный приоритет.';
    if ($form['due_date'] !== '' && !strtotime($form['due_date'])) $errors[] = 'Некорректная дата срока.';

    $assigneeId = $form['assignee_id'] !== '' ? (int) $form['assignee_id'] : null;
    $objectId = $form['object_id'] !== '' ? (int) $form['object_id'] : null;
    $initialStatus = task_status_initial();

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO tasks (equipment_id, object_id, title, description, priority, status, assignee_id, created_by, due_date)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $form['equipment_id'], $objectId, $form['title'], $form['description'] ?: null, $form['priority'],
                $initialStatus, $assigneeId, $user['id'], $form['due_date'] ?: null,
            ]);
            $taskId = (int) $pdo->lastInsertId();

            $hist = $pdo->prepare('INSERT INTO task_history (task_id, user_id, old_status, new_status, comment) VALUES (?,?,?,?,?)');
            $hist->execute([$taskId, $user['id'], null, $initialStatus, 'Задача создана']);

            $pdo->commit();
            flash('success', 'Задача #' . $taskId . ' создана.');
            redirect('task_view.php?id=' . $taskId);
        } catch (Exception $ex) {
            $pdo->rollBack();
            $errors[] = 'Ошибка сохранения: ' . $ex->getMessage();
        }
    }
}

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>Новая задача</h1>
    <a href="tasks.php" class="btn-link">← К задачам</a>
</div>

<?php foreach ($errors as $err): ?>
    <div class="flash flash-error"><?= e($err) ?></div>
<?php endforeach; ?>

<form method="post" class="form-card">
    <?= csrf_field() ?>
    <label>Техника *
        <select name="equipment_id" required>
            <option value="">— выберите —</option>
            <?php foreach ($equipmentList as $eq): ?>
                <option value="<?= (int) $eq['id'] ?>" <?= $form['equipment_id'] === (int) $eq['id'] ? 'selected' : '' ?>>
                    <?= e($eq['inventory_number'] . ' — ' . $eq['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Краткое описание (заголовок) *
        <input type="text" name="title" required maxlength="255" value="<?= e($form['title']) ?>" placeholder="напр. Замена масляного фильтра">
    </label>
    <label>Подробное описание
        <textarea name="description" rows="4" placeholder="Что нужно сделать, симптомы неисправности и т.п."><?= e($form['description']) ?></textarea>
    </label>
    <div class="form-row">
        <label>Объект
            <select name="object_id" id="objectSelect">
                <option value="">— не указан —</option>
                <?php foreach ($objectsList as $o): ?>
                    <option value="<?= (int) $o['id'] ?>" <?= (string) $form['object_id'] === (string) $o['id'] ? 'selected' : '' ?>><?= e($o['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Механик участка
            <select name="assignee_id" id="assigneeSelect">
                <option value="">Пока не назначен</option>
                <?php foreach ($mechanics as $m): ?>
                    <option value="<?= (int) $m['id'] ?>" data-objects="<?= e($m['object_ids'] ?? '') ?>" <?= (string) $form['assignee_id'] === (string) $m['id'] ? 'selected' : '' ?>><?= e($m['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Приоритет
            <select name="priority">
                <option value="low" <?= $form['priority'] === 'low' ? 'selected' : '' ?>>Низкий</option>
                <option value="normal" <?= $form['priority'] === 'normal' ? 'selected' : '' ?>>Обычный</option>
                <option value="high" <?= $form['priority'] === 'high' ? 'selected' : '' ?>>Высокий</option>
                <option value="urgent" <?= $form['priority'] === 'urgent' ? 'selected' : '' ?>>Срочно</option>
            </select>
        </label>
        <label>Срок выполнения
            <input type="date" name="due_date" value="<?= e($form['due_date']) ?>">
        </label>
    </div>
    <button type="submit" class="btn btn-primary">Создать задачу</button>
</form>

<script>
(function () {
    var objectSelect = document.getElementById('objectSelect');
    var assigneeSelect = document.getElementById('assigneeSelect');
    if (!objectSelect || !assigneeSelect) { return; }

    var options = Array.prototype.slice.call(assigneeSelect.options);

    function sync() {
        var objectId = objectSelect.value;

        options.forEach(function (opt) {
            if (!opt.value) { return; }
            var list = (opt.getAttribute('data-objects') || '').split(',').filter(Boolean);
            var fits = !objectId || list.indexOf(objectId) !== -1;
            opt.hidden = !fits;
            opt.disabled = !fits;
            if (!fits && assigneeSelect.value === opt.value) { assigneeSelect.value = ''; }
        });
    }

    objectSelect.addEventListener('change', sync);
    sync();
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
