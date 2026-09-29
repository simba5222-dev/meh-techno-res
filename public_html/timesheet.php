<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_admin();

$pdo = db();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upsert') {
    csrf_verify();

    $targetUserId = (int) ($_POST['user_id'] ?? 0);
    $reportDate = $_POST['report_date'] ?? '';
    $isPresent = isset($_POST['is_present']) ? 1 : 0;
    $summary = trim($_POST['summary'] ?? '');

    if (!$targetUserId) {
        $errors[] = 'Выберите механика.';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) {
        $errors[] = 'Некорректная дата.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare(
            'INSERT INTO daily_reports (user_id, report_date, is_present, summary)
             VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE is_present = VALUES(is_present), summary = VALUES(summary), updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([$targetUserId, $reportDate, $isPresent, $summary ?: null]);
        flash('success', 'Запись табеля сохранена.');
        redirect('timesheet.php?year=' . (int) substr($reportDate, 0, 4) . '&month=' . (int) substr($reportDate, 5, 2));
    }
}

$year = (int) ($_GET['year'] ?? date('Y'));
$month = (int) ($_GET['month'] ?? date('n'));
if ($month < 1 || $month > 12) {
    $month = (int) date('n');
}
if ($year < 2000 || $year > 2100) {
    $year = (int) date('Y');
}

$firstDay = sprintf('%04d-%02d-01', $year, $month);
$daysInMonth = (int) date('t', strtotime($firstDay));
$lastDay = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);

$mechanics = $pdo->query("SELECT id, full_name FROM users WHERE role = 'mechanic' AND is_active = 1 ORDER BY full_name")->fetchAll();

$reportsStmt = $pdo->prepare(
    'SELECT dr.*, u.full_name FROM daily_reports dr JOIN users u ON u.id = dr.user_id
     WHERE dr.report_date BETWEEN ? AND ? ORDER BY dr.report_date DESC'
);
$reportsStmt->execute([$firstDay, $lastDay]);
$periodReports = $reportsStmt->fetchAll();

$grid = [];
foreach ($periodReports as $r) {
    $day = (int) substr($r['report_date'], 8, 2);
    $grid[(int) $r['user_id']][$day] = $r;
}

$monthNames = [1=>'Январь',2=>'Февраль',3=>'Март',4=>'Апрель',5=>'Май',6=>'Июнь',7=>'Июль',8=>'Август',9=>'Сентябрь',10=>'Октябрь',11=>'Ноябрь',12=>'Декабрь'];

$prevMonth = $month - 1; $prevYear = $year;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
$nextMonth = $month + 1; $nextYear = $year;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>Табель — <?= e($monthNames[$month]) ?> <?= $year ?></h1>
    <div class="filter-bar" style="margin:0;box-shadow:none;padding:0;border:none;background:none;">
        <a href="timesheet.php?year=<?= $prevYear ?>&month=<?= $prevMonth ?>" class="btn">← Пред. месяц</a>
        <a href="timesheet.php?year=<?= $nextYear ?>&month=<?= $nextMonth ?>" class="btn">След. месяц →</a>
    </div>
</div>

<?php foreach ($errors as $err): ?>
    <div class="flash flash-error"><?= e($err) ?></div>
<?php endforeach; ?>

<div class="table-wrap">
<table class="data-table timesheet-grid">
    <thead>
        <tr>
            <th class="timesheet-name-col">Механик</th>
            <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                <th><?= $d ?></th>
            <?php endfor; ?>
        </tr>
    </thead>
    <tbody>
    <?php if (!$mechanics): ?>
        <tr><td colspan="<?= $daysInMonth + 1 ?>" class="empty-cell">Механики ещё не заведены.</td></tr>
    <?php endif; ?>
    <?php foreach ($mechanics as $m): ?>
        <tr>
            <td class="timesheet-name-col"><?= e($m['full_name']) ?></td>
            <?php for ($d = 1; $d <= $daysInMonth; $d++): $cell = $grid[(int) $m['id']][$d] ?? null;
                $pending = $cell && ($cell['review_status'] ?? 'pending') === 'pending';
                $cellClass = $cell ? ($pending ? 'ts-pending' : ($cell['is_present'] ? 'ts-present' : 'ts-absent')) : 'ts-empty';
            ?>
                <td class="timesheet-cell <?= $cellClass ?>"
                    title="<?= $cell && $cell['summary'] ? e($cell['summary']) : '' ?>">
                    <?php if ($cell): ?>
                        <a href="daily_report_view.php?id=<?= (int) $cell['id'] ?>"><?= $cell['is_present'] ? '✓' : '×' ?></a>
                    <?php endif; ?>
                </td>
            <?php endfor; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<p class="hint">✓ — был на работе, × — не был, пусто — отчёт не подан. Жёлтая ячейка — отчёт ещё не проверен, нажмите на неё. Клик по любой заполненной ячейке открывает отчёт и проверку.</p>

<h2>Добавить / изменить запись вручную</h2>
<form method="post" class="form-card">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upsert">
    <div class="form-row">
        <label>Механик
            <select name="user_id" required>
                <option value="">— выберите —</option>
                <?php foreach ($mechanics as $m): ?>
                    <option value="<?= (int) $m['id'] ?>"><?= e($m['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Дата
            <input type="date" name="report_date" required value="<?= e($firstDay) ?>" max="<?= e(date('Y-m-d')) ?>">
        </label>
    </div>
    <label class="checkbox-label"><input type="checkbox" name="is_present" value="1" checked> был(а) на работе</label>
    <label>Отчёт / примечание
        <textarea name="summary" rows="3"></textarea>
    </label>
    <button type="submit" class="btn btn-primary">Сохранить запись</button>
</form>

<h2>Отчёты за период (<?= fmt_date($firstDay) ?> — <?= fmt_date($lastDay) ?>)</h2>
<div class="table-wrap">
<table class="data-table">
    <thead><tr><th>Дата</th><th>Механик</th><th>Присутствие</th><th>Отчёт</th></tr></thead>
    <tbody>
    <?php if (!$periodReports): ?>
        <tr><td colspan="4" class="empty-cell">Отчётов за этот период нет.</td></tr>
    <?php endif; ?>
    <?php foreach ($periodReports as $r): ?>
        <tr>
            <td><?= fmt_date($r['report_date']) ?></td>
            <td><?= e($r['full_name']) ?></td>
            <td><span class="badge <?= $r['is_present'] ? 'badge-verified' : 'badge-rejected' ?>"><?= $r['is_present'] ? 'Был' : 'Не был' ?></span></td>
            <td class="report-summary-cell"><?= $r['summary'] ? nl2br(e($r['summary'])) : '—' ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
