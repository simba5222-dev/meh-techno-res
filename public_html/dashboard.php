<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_admin();

$pdo = db();

$activeTasks = (int) $pdo->query("SELECT COUNT(*) FROM tasks WHERE " . sql_not_final())->fetchColumn();
$overdueCount = (int) $pdo->query("SELECT COUNT(*) FROM tasks WHERE due_date IS NOT NULL AND due_date < CURDATE() AND " . sql_not_final())->fetchColumn();
$newUnassigned = (int) $pdo->query("SELECT COUNT(*) FROM tasks WHERE status = " . $pdo->quote(task_status_initial()) . " AND assignee_id IS NULL")->fetchColumn();

// Механики и их текущая загрузка
$mechanics = $pdo->query("SELECT id, full_name, phone FROM users WHERE role='mechanic' AND is_active=1 ORDER BY full_name")->fetchAll();

$tasksByMechanic = [];
$taskStmt = $pdo->prepare(
    "SELECT t.*, e.inventory_number, e.name AS equipment_name
     FROM tasks t JOIN equipment e ON e.id = t.equipment_id
     WHERE t.assignee_id = ? AND " . sql_not_final('t') . "
     ORDER BY FIELD(t.priority,'urgent','high','normal','low'), t.due_date IS NULL, t.due_date ASC"
);
foreach ($mechanics as $m) {
    $taskStmt->execute([$m['id']]);
    $tasksByMechanic[$m['id']] = $taskStmt->fetchAll();
}

// Просроченные задачи (все)
$overdueStmt = $pdo->query(
    "SELECT t.*, e.inventory_number, e.name AS equipment_name, u.full_name AS assignee_name
     FROM tasks t JOIN equipment e ON e.id = t.equipment_id
     LEFT JOIN users u ON u.id = t.assignee_id
     WHERE t.due_date IS NOT NULL AND t.due_date < CURDATE() AND " . sql_not_final('t') . "
     ORDER BY t.due_date ASC"
);
$overdueTasks = $overdueStmt->fetchAll();

// Техника на ремонте
$repairStmt = $pdo->query("SELECT * FROM equipment WHERE is_active=1 AND status='repair' ORDER BY name");
$repairList = $repairStmt->fetchAll();

// Новые задачи, ожидающие реакции механика (новые или возвращённые)
$pendingStmt = $pdo->query(
    "SELECT t.*, e.inventory_number, e.name AS equipment_name, u.full_name AS assignee_name
     FROM tasks t JOIN equipment e ON e.id = t.equipment_id
     LEFT JOIN users u ON u.id = t.assignee_id
     WHERE t.assignee_id IS NULL AND " . sql_not_final('t') . "
     ORDER BY t.created_at DESC"
);
$pendingTasks = $pendingStmt->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>Дашборд</h1>
    <a href="tasks.php" class="btn-link">Все задачи →</a>
</div>

<div class="stat-row">
    <div class="stat-tile"><div class="stat-num"><?= $activeTasks ?></div><div class="stat-label">Активных задач</div></div>
    <div class="stat-tile <?= $overdueCount > 0 ? 'stat-danger' : '' ?>"><div class="stat-num"><?= $overdueCount ?></div><div class="stat-label">Просрочено</div></div>
    <div class="stat-tile <?= $newUnassigned > 0 ? 'stat-warn' : '' ?>"><div class="stat-num"><?= $newUnassigned ?></div><div class="stat-label">Без исполнителя</div></div>
</div>

<h2>Кто чем занят</h2>
<div class="mechanic-grid">
    <?php foreach ($mechanics as $m): $mTasks = $tasksByMechanic[$m['id']]; ?>
        <div class="mechanic-card">
            <div class="mechanic-card-head">
                <strong><?= e($m['full_name']) ?></strong>
                <span class="count-pill"><?= count($mTasks) ?></span>
            </div>
            <?php if (!$mTasks): ?>
                <div class="mechanic-free">Свободен, активных задач нет</div>
            <?php else: ?>
                <ul class="mechanic-task-list">
                    <?php foreach ($mTasks as $t): ?>
                        <li class="<?= task_is_overdue($t) ? 'row-overdue' : '' ?>">
                            <a href="task_view.php?id=<?= (int) $t['id'] ?>">#<?= (int) $t['id'] ?> <?= e($t['title']) ?></a>
                            <div class="mechanic-task-meta">
                                <?= e($t['inventory_number']) ?> · <span class="badge" style="<?= e(task_status_style($t['status'])) ?>"><?= task_status_label($t['status']) ?></span>
                                <?php if ($t['due_date']): ?> · срок <?= fmt_date($t['due_date']) ?><?= task_is_overdue($t) ? ' ⚠' : '' ?><?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if (!$mechanics): ?>
        <p class="empty-cell">Механики ещё не заведены. <a href="users.php">Добавить пользователя</a>.</p>
    <?php endif; ?>
</div>

<?php if ($overdueTasks): ?>
<h2>Просроченные задачи</h2>
<div class="table-wrap">
<table class="data-table">
    <thead><tr><th>№</th><th>Задача</th><th>Техника</th><th>Исполнитель</th><th>Срок</th><th>Статус</th></tr></thead>
    <tbody>
    <?php foreach ($overdueTasks as $t): ?>
        <tr class="row-overdue">
            <td>#<?= (int) $t['id'] ?></td>
            <td><a href="task_view.php?id=<?= (int) $t['id'] ?>"><?= e($t['title']) ?></a></td>
            <td><?= e($t['inventory_number'] . ' — ' . $t['equipment_name']) ?></td>
            <td><?= e($t['assignee_name'] ?? 'не назначен') ?></td>
            <td class="overdue-text"><?= fmt_date($t['due_date']) ?></td>
            <td><span class="badge" style="<?= e(task_status_style($t['status'])) ?>"><?= task_status_label($t['status']) ?></span></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<?php if ($pendingTasks): ?>
<h2>Ожидают реакции механика (новые / возвращённые)</h2>
<div class="table-wrap">
<table class="data-table">
    <thead><tr><th>№</th><th>Задача</th><th>Техника</th><th>Исполнитель</th><th>Статус</th><th>Создана</th></tr></thead>
    <tbody>
    <?php foreach ($pendingTasks as $t): ?>
        <tr>
            <td>#<?= (int) $t['id'] ?></td>
            <td><a href="task_view.php?id=<?= (int) $t['id'] ?>"><?= e($t['title']) ?></a></td>
            <td><?= e($t['inventory_number'] . ' — ' . $t['equipment_name']) ?></td>
            <td><?= e($t['assignee_name'] ?? 'не назначен') ?></td>
            <td><span class="badge" style="<?= e(task_status_style($t['status'])) ?>"><?= task_status_label($t['status']) ?></span></td>
            <td><?= fmt_datetime($t['created_at']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<?php if ($repairList): ?>
<h2>Техника на ремонте</h2>
<div class="table-wrap">
<table class="data-table">
    <thead><tr><th>Инв. №</th><th>Наименование</th><th>Локация</th></tr></thead>
    <tbody>
    <?php foreach ($repairList as $eq): ?>
        <tr>
            <td><?= e($eq['inventory_number']) ?></td>
            <td><a href="equipment_view.php?id=<?= (int) $eq['id'] ?>"><?= e($eq['name']) ?></a></td>
            <td><?= e($eq['location'] ?? '—') ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
