<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_admin();

$pdo = db();

$type = $_GET['type'] ?? 'mechanic';
if (!in_array($type, ['mechanic', 'task', 'equipment'], true)) {
    $type = 'mechanic';
}

$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo = $_GET['to'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = date('Y-m-d');
}
$fromDT = $dateFrom . ' 00:00:00';
$toDT = $dateTo . ' 23:59:59';

// ---- Отчёт по механикам ----
function build_mechanic_report(PDO $pdo, string $fromDT, string $toDT, string $dateFrom, string $dateTo): array
{
    $mechanics = $pdo->query("SELECT id, full_name FROM users WHERE role = 'mechanic' AND is_active = 1 ORDER BY full_name")->fetchAll();

    $attStmt = $pdo->prepare(
        'SELECT user_id, SUM(is_present) AS present_days, COUNT(*) AS reported_days
         FROM daily_reports WHERE report_date BETWEEN ? AND ? GROUP BY user_id'
    );
    $attStmt->execute([$dateFrom, $dateTo]);
    $attendance = [];
    foreach ($attStmt->fetchAll() as $row) {
        $attendance[(int) $row['user_id']] = $row;
    }

    $taskStmt = $pdo->prepare(
        "SELECT assignee_id,
                COUNT(*) AS total_tasks,
                SUM(" . sql_is_final() . ") AS completed_tasks
         FROM tasks WHERE assignee_id IS NOT NULL AND created_at BETWEEN ? AND ?
         GROUP BY assignee_id"
    );
    $taskStmt->execute([$fromDT, $toDT]);
    $taskStats = [];
    foreach ($taskStmt->fetchAll() as $row) {
        $taskStats[(int) $row['assignee_id']] = $row;
    }

    $overdueStmt = $pdo->prepare(
        "SELECT assignee_id, COUNT(*) AS overdue_count
         FROM tasks WHERE assignee_id IS NOT NULL AND due_date IS NOT NULL AND due_date < CURDATE()
               AND " . sql_not_final() . "
         GROUP BY assignee_id"
    );
    $overdueStmt->execute();
    $overdue = [];
    foreach ($overdueStmt->fetchAll() as $row) {
        $overdue[(int) $row['assignee_id']] = (int) $row['overdue_count'];
    }

    $rows = [];
    foreach ($mechanics as $m) {
        $id = (int) $m['id'];
        $rows[] = [
            'Механик' => $m['full_name'],
            'Дней с отчётом' => (int) ($attendance[$id]['reported_days'] ?? 0),
            'Дней на работе' => (int) ($attendance[$id]['present_days'] ?? 0),
            'Задач создано за период' => (int) ($taskStats[$id]['total_tasks'] ?? 0),
            'Из них выполнено (проверено)' => (int) ($taskStats[$id]['completed_tasks'] ?? 0),
            'Просрочено сейчас' => (int) ($overdue[$id] ?? 0),
        ];
    }
    return $rows;
}

// ---- Отчёт по задачам ----
function build_task_report(PDO $pdo, string $fromDT, string $toDT): array
{
    $stmt = $pdo->prepare(
        "SELECT t.id, t.title, e.inventory_number, e.name AS equipment_name, u1.full_name AS assignee_name,
                t.priority, t.status, t.due_date, t.created_at, t.closed_at
         FROM tasks t
         JOIN equipment e ON e.id = t.equipment_id
         LEFT JOIN users u1 ON u1.id = t.assignee_id
         WHERE t.created_at BETWEEN ? AND ?
         ORDER BY t.created_at DESC"
    );
    $stmt->execute([$fromDT, $toDT]);
    $tasks = $stmt->fetchAll();

    $rows = [];
    foreach ($tasks as $t) {
        $rows[] = [
            '№' => $t['id'],
            'Задача' => $t['title'],
            'Техника' => $t['inventory_number'] . ' — ' . $t['equipment_name'],
            'Исполнитель' => $t['assignee_name'] ?? 'не назначен',
            'Приоритет' => priority_label($t['priority']),
            'Статус' => task_status_label($t['status']),
            'Срок' => fmt_date($t['due_date']),
            'Создана' => fmt_datetime($t['created_at']),
            'Закрыта' => fmt_datetime($t['closed_at']),
        ];
    }
    return $rows;
}

// ---- Отчёт по технике ----
function build_equipment_report(PDO $pdo, string $fromDT, string $toDT): array
{
    $equipment = $pdo->query("SELECT * FROM equipment WHERE is_active = 1 ORDER BY name")->fetchAll();

    $taskStmt = $pdo->prepare(
        "SELECT equipment_id,
                COUNT(*) AS total_tasks,
                SUM(" . sql_is_final() . ") AS completed_tasks,
                SUM(" . sql_not_final() . " AND due_date IS NOT NULL AND due_date < CURDATE()) AS overdue_tasks
         FROM tasks WHERE created_at BETWEEN ? AND ?
         GROUP BY equipment_id"
    );
    $taskStmt->execute([$fromDT, $toDT]);
    $stats = [];
    foreach ($taskStmt->fetchAll() as $row) {
        $stats[(int) $row['equipment_id']] = $row;
    }

    $rows = [];
    foreach ($equipment as $eq) {
        $id = (int) $eq['id'];
        $rows[] = [
            'Инв. №' => $eq['inventory_number'],
            'Наименование' => $eq['name'],
            'Текущий статус' => equipment_status_label($eq['status']),
            'Задач за период' => (int) ($stats[$id]['total_tasks'] ?? 0),
            'Из них выполнено' => (int) ($stats[$id]['completed_tasks'] ?? 0),
            'Просрочено сейчас' => (int) ($stats[$id]['overdue_tasks'] ?? 0),
        ];
    }
    return $rows;
}

switch ($type) {
    case 'task':
        $rows = build_task_report($pdo, $fromDT, $toDT);
        $reportTitle = 'Отчёт по задачам';
        break;
    case 'equipment':
        $rows = build_equipment_report($pdo, $fromDT, $toDT);
        $reportTitle = 'Отчёт по технике';
        break;
    default:
        $rows = build_mechanic_report($pdo, $fromDT, $toDT, $dateFrom, $dateTo);
        $reportTitle = 'Отчёт по механикам';
        break;
}

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report_' . $type . '_' . $dateFrom . '_' . $dateTo . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM для корректного отображения кириллицы в Excel
    $out = fopen('php://output', 'w');
    if ($rows) {
        fputcsv($out, array_keys($rows[0]), ';');
        foreach ($rows as $r) {
            fputcsv($out, $r, ';');
        }
    }
    fclose($out);
    exit;
}

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>Отчёты</h1>
</div>

<div class="report-tabs">
    <a href="reports.php?type=mechanic&from=<?= e($dateFrom) ?>&to=<?= e($dateTo) ?>" class="report-tab <?= $type === 'mechanic' ? 'active' : '' ?>">По механикам</a>
    <a href="reports.php?type=task&from=<?= e($dateFrom) ?>&to=<?= e($dateTo) ?>" class="report-tab <?= $type === 'task' ? 'active' : '' ?>">По задачам</a>
    <a href="reports.php?type=equipment&from=<?= e($dateFrom) ?>&to=<?= e($dateTo) ?>" class="report-tab <?= $type === 'equipment' ? 'active' : '' ?>">По технике</a>
</div>

<form method="get" class="filter-bar">
    <input type="hidden" name="type" value="<?= e($type) ?>">
    <label class="hint">С <input type="date" name="from" value="<?= e($dateFrom) ?>"></label>
    <label class="hint">По <input type="date" name="to" value="<?= e($dateTo) ?>"></label>
    <button type="submit" class="btn">Применить</button>
    <a class="btn" href="reports.php?type=<?= e($type) ?>&from=<?= e($dateFrom) ?>&to=<?= e($dateTo) ?>&export=csv">Скачать CSV</a>
</form>

<h2><?= e($reportTitle) ?> (<?= fmt_date($dateFrom) ?> — <?= fmt_date($dateTo) ?>)</h2>
<div class="table-wrap">
<table class="data-table">
    <?php if ($rows): ?>
    <thead>
        <tr><?php foreach (array_keys($rows[0]) as $col): ?><th><?= e($col) ?></th><?php endforeach; ?></tr>
    </thead>
    <tbody>
        <?php foreach ($rows as $r): ?>
            <tr><?php foreach ($r as $val): ?><td><?= e((string) $val) ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
    </tbody>
    <?php else: ?>
    <tbody><tr><td class="empty-cell">Нет данных за выбранный период.</td></tr></tbody>
    <?php endif; ?>
</table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
