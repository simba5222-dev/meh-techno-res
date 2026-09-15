<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM equipment WHERE id = ?');
$stmt->execute([$id]);
$eq = $stmt->fetch();

if (!$eq) {
    flash('error', 'Техника не найдена.');
    redirect('equipment.php');
}

$tasksStmt = db()->prepare(
    'SELECT t.*, u1.full_name AS assignee_name, u2.full_name AS creator_name
     FROM tasks t
     LEFT JOIN users u1 ON u1.id = t.assignee_id
     LEFT JOIN users u2 ON u2.id = t.created_by
     WHERE t.equipment_id = ?
     ORDER BY t.created_at DESC'
);
$tasksStmt->execute([$id]);
$tasks = $tasksStmt->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1><?= e($eq['name']) ?> <span class="badge <?= equipment_status_class($eq['status']) ?>"><?= equipment_status_label($eq['status']) ?></span></h1>
    <div>
        <?php if (is_admin()): ?><a href="equipment_edit.php?id=<?= (int) $eq['id'] ?>" class="btn-link">Изменить</a> · <?php endif; ?>
        <a href="equipment.php" class="btn-link">← К списку техники</a>
    </div>
</div>

<div class="detail-grid">
    <div><span class="label">Инвентарный №</span><?= e($eq['inventory_number']) ?></div>
    <div><span class="label">Тип</span><?= e($eq['type'] ?? '—') ?></div>
    <div><span class="label">Гос. номер</span><?= e($eq['reg_number'] ?? '—') ?></div>
    <div><span class="label">ПТС</span><?= e($eq['pts'] ?? '—') ?></div>
    <div><span class="label">СТС</span><?= e($eq['sts'] ?? '—') ?></div>
    <div><span class="label">Локация</span><?= e($eq['location'] ?? '—') ?></div>
    <div><span class="label">Моточасы</span><?= $eq['engine_hours'] !== null ? e((string) $eq['engine_hours']) : '—' ?></div>
    <div><span class="label">В парке с</span><?= fmt_date($eq['created_at']) ?></div>
</div>
<?php if ($eq['notes']): ?>
    <p class="notes-block"><?= nl2br(e($eq['notes'])) ?></p>
<?php endif; ?>

<div class="page-head">
    <h2>Задачи по этой технике</h2>
    <?php if (is_admin()): ?>
        <a href="task_create.php?equipment_id=<?= (int) $eq['id'] ?>" class="btn btn-primary">+ Новая задача</a>
    <?php endif; ?>
</div>

<div class="table-wrap">
<table class="data-table">
    <thead>
        <tr><th>№</th><th>Задача</th><th>Исполнитель</th><th>Приоритет</th><th>Статус</th><th>Срок</th><th>Создана</th></tr>
    </thead>
    <tbody>
    <?php if (!$tasks): ?>
        <tr><td colspan="7" class="empty-cell">Задач по этой технике пока не было.</td></tr>
    <?php endif; ?>
    <?php foreach ($tasks as $t): ?>
        <tr class="<?= task_is_overdue($t) ? 'row-overdue' : '' ?>">
            <td>#<?= (int) $t['id'] ?></td>
            <td><a href="task_view.php?id=<?= (int) $t['id'] ?>"><?= e($t['title']) ?></a></td>
            <td><?= e($t['assignee_name'] ?? '—') ?></td>
            <td><span class="badge <?= priority_class($t['priority']) ?>"><?= priority_label($t['priority']) ?></span></td>
            <td><span class="badge" style="<?= e(task_status_style($t['status'])) ?>"><?= task_status_label($t['status']) ?></span></td>
            <td><?= fmt_date($t['due_date']) ?><?= task_is_overdue($t) ? ' ⚠' : '' ?></td>
            <td><?= fmt_date($t['created_at']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
