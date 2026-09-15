<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

if ($user['role'] === 'manager') {
    redirect('equipment.php');
}

$isAdmin = $user['role'] === 'admin';

$assigneeFilter  = $isAdmin ? ($_GET['assignee_id'] ?? '') : (string) $user['id'];
$objectFilter    = (int) ($_GET['object_id'] ?? 0);
$equipmentFilter = trim($_GET['equipment'] ?? '');
$showClosed      = isset($_GET['show_closed']);

$sql = "SELECT t.*, e.inventory_number, e.name AS equipment_name,
        u1.full_name AS assignee_name, u2.full_name AS creator_name,
        o.name AS object_name,
        (SELECT COUNT(*) FROM task_photos p WHERE p.task_id = t.id) AS photo_count,
        (SELECT GROUP_CONCAT(uu.full_name ORDER BY uu.full_name SEPARATOR ', ')
           FROM task_assignees ta JOIN users uu ON uu.id = ta.user_id
          WHERE ta.task_id = t.id) AS fitters
        FROM tasks t
        JOIN equipment e ON e.id = t.equipment_id
        LEFT JOIN users u1 ON u1.id = t.assignee_id
        LEFT JOIN users u2 ON u2.id = t.created_by
        LEFT JOIN objects o ON o.id = t.object_id
        WHERE 1=1";
$params = [];

if ($assigneeFilter !== '') {
    $sql .= ' AND t.assignee_id = ?';
    $params[] = (int) $assigneeFilter;
}

if ($objectFilter) {
    $sql .= ' AND t.object_id = ?';
    $params[] = $objectFilter;
}

if ($equipmentFilter !== '') {
    $sql .= ' AND (e.name LIKE ? OR e.inventory_number LIKE ?)';
    $like = '%' . $equipmentFilter . '%';
    $params[] = $like;
    $params[] = $like;
}

if (!$showClosed) {
    $sql .= ' AND ' . sql_not_final('t');
}

$sql .= ' ORDER BY FIELD(t.priority,\'urgent\',\'high\',\'normal\',\'low\'), t.due_date IS NULL, t.due_date ASC, t.created_at DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$allTasks = $stmt->fetchAll();

// Колонки строятся из справочника статусов
$statuses = task_statuses_all();
$columns = [];
foreach ($statuses as $code => $row) {
    if (!$showClosed && $row['is_final']) {
        continue;
    }
    $columns[$code] = [];
}

// Статусы, которых нет в справочнике (например отключённые), не теряем
foreach ($allTasks as $t) {
    if (!isset($columns[$t['status']])) {
        $columns[$t['status']] = [];
    }
    $columns[$t['status']][] = $t;
}

$allowedStatuses = statuses_allowed_for_role($user['role']);

$mechanicsForFilter = $isAdmin ? users_of_role('mechanic') : [];
$objectsForFilter = objects_list(true);

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1><?= $isAdmin ? 'Задачи' : 'Мои задачи' ?></h1>
    <?php if ($isAdmin): ?>
        <a href="task_create.php" class="btn btn-primary">+ Новая задача</a>
    <?php endif; ?>
</div>

<form method="get" class="filter-bar">
    <?php if ($isAdmin): ?>
        <select name="assignee_id">
            <option value="">Все механики</option>
            <?php foreach ($mechanicsForFilter as $m): ?>
                <option value="<?= (int) $m['id'] ?>" <?= (string) $assigneeFilter === (string) $m['id'] ? 'selected' : '' ?>><?= e($m['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
    <?php endif; ?>
    <select name="object_id">
        <option value="">Все объекты</option>
        <?php foreach ($objectsForFilter as $o): ?>
            <option value="<?= (int) $o['id'] ?>" <?= $objectFilter === (int) $o['id'] ? 'selected' : '' ?>><?= e($o['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <input type="text" name="equipment" placeholder="Поиск по технике" value="<?= e($equipmentFilter) ?>">
    <label class="checkbox-label"><input type="checkbox" name="show_closed" value="1" <?= $showClosed ? 'checked' : '' ?> onchange="this.form.submit()"> показать закрытые</label>
    <button type="submit" class="btn">Применить</button>
</form>

<p class="hint kanban-hint">Карточку можно перетащить в другую колонку — мышью или пальцем. Доступны только те колонки, в которые вам разрешено переводить задачи.</p>

<div class="kanban" id="kanbanBoard" data-csrf="<?= e(csrf_token()) ?>">
    <?php foreach ($columns as $statusKey => $tasksInCol):
        $statusRow = task_status_get($statusKey);
        $canDrop = isset($allowedStatuses[$statusKey]);
    ?>
    <div class="kanban-col <?= $canDrop ? 'kanban-col-droppable' : 'kanban-col-locked' ?>" data-status="<?= e($statusKey) ?>">
        <div class="kanban-col-head" style="<?= $statusRow ? 'border-bottom:3px solid ' . e($statusRow['color_text']) . ';' : '' ?>">
            <span><?= e($statusRow['name'] ?? $statusKey) ?></span>
            <span class="count"><?= count($tasksInCol) ?></span>
        </div>
        <div class="kanban-col-body">
            <?php if (!$tasksInCol): ?>
                <div class="kanban-empty">пусто</div>
            <?php endif; ?>
            <?php foreach ($tasksInCol as $t): ?>
                <div class="task-card <?= task_is_overdue($t) ? 'task-card-overdue' : '' ?>" data-task-id="<?= (int) $t['id'] ?>" draggable="false">
                    <a class="task-card-link" href="task_view.php?id=<?= (int) $t['id'] ?>">
                        <div class="task-card-title">#<?= (int) $t['id'] ?> <?= e($t['title']) ?></div>
                        <div class="task-card-eq"><?= e($t['inventory_number'] . ' · ' . $t['equipment_name']) ?></div>
                        <?php if ($t['object_name']): ?>
                            <div class="task-card-object"><?= e($t['object_name']) ?></div>
                        <?php endif; ?>
                        <div class="task-card-meta">
                            <span class="badge <?= priority_class($t['priority']) ?>"><?= priority_label($t['priority']) ?></span>
                            <?php if ($isAdmin): ?><span class="assignee"><?= e($t['assignee_name'] ?? 'не назначен') ?></span><?php endif; ?>
                        </div>
                        <?php if ($t['fitters']): ?>
                            <div class="task-card-fitters">Слесари: <?= e($t['fitters']) ?></div>
                        <?php endif; ?>
                        <?php if ($t['due_date']): ?>
                            <div class="task-card-due <?= task_is_overdue($t) ? 'overdue-text' : '' ?>">
                                срок: <?= fmt_date($t['due_date']) ?><?= task_is_overdue($t) ? ' ⚠ просрочено' : '' ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($t['photo_count'] > 0): ?><div class="task-card-photos">📷 <?= (int) $t['photo_count'] ?></div><?php endif; ?>
                    </a>
                    <?php if ($allowedStatuses): ?>
                    <div class="task-card-move">
                        <label class="visually-hidden" for="move-<?= (int) $t['id'] ?>">Перенести задачу</label>
                        <select class="task-move-select" id="move-<?= (int) $t['id'] ?>" data-task-id="<?= (int) $t['id'] ?>">
                            <option value="">перенести в…</option>
                            <?php foreach ($allowedStatuses as $code => $row): ?>
                                <?php if ($code === $t['status']) continue; ?>
                                <option value="<?= e($code) ?>"><?= e($row['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
