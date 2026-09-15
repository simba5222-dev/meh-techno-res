<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

if ($user['role'] === 'manager') {
    redirect('equipment.php');
}

$pdo = db();

$today = date('Y-m-d');
$reportDate = $_GET['date'] ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) {
    $reportDate = $today;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $postDate = $_POST['report_date'] ?? $today;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $postDate)) {
        $postDate = $today;
    }
    if ($postDate > $today) {
        $errors[] = 'Нельзя отправить отчёт за будущую дату.';
    }

    $isPresent = isset($_POST['is_present']) ? 1 : 0;
    $summary = trim($_POST['summary'] ?? '');

    if ($isPresent && $summary === '') {
        $errors[] = 'Опишите, что было сделано за день.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare(
            'INSERT INTO daily_reports (user_id, report_date, is_present, summary)
             VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE is_present = VALUES(is_present), summary = VALUES(summary), updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([$user['id'], $postDate, $isPresent, $summary ?: null]);
        flash('success', 'Отчёт за ' . fmt_date($postDate) . ' сохранён.');
        redirect('report_today.php?date=' . urlencode($postDate));
    }
    $reportDate = $postDate;
}

$stmt = $pdo->prepare('SELECT * FROM daily_reports WHERE user_id = ? AND report_date = ?');
$stmt->execute([$user['id'], $reportDate]);
$current = $stmt->fetch();

$currentIsPresent = $current ? (bool) $current['is_present'] : true;
$currentSummary = $current['summary'] ?? '';

$historyStmt = $pdo->prepare(
    'SELECT * FROM daily_reports WHERE user_id = ? ORDER BY report_date DESC LIMIT 30'
);
$historyStmt->execute([$user['id']]);
$history = $historyStmt->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>Мой отчёт</h1>
</div>

<?php foreach ($errors as $err): ?>
    <div class="flash flash-error"><?= e($err) ?></div>
<?php endforeach; ?>

<form method="post" class="form-card">
    <?= csrf_field() ?>
    <div class="form-row">
        <label>Дата
            <input type="date" name="report_date" max="<?= e($today) ?>" value="<?= e($reportDate) ?>" onchange="location='report_today.php?date='+this.value">
        </label>
    </div>
    <label class="checkbox-label"><input type="checkbox" name="is_present" value="1" <?= $currentIsPresent ? 'checked' : '' ?>> был(а) на работе в этот день</label>
    <label>Что сделано за день
        <textarea name="summary" rows="4" placeholder="Опишите выполненные работы"><?= e($currentSummary) ?></textarea>
    </label>
    <button type="submit" class="btn btn-primary"><?= $current ? 'Сохранить изменения' : 'Отправить отчёт' ?></button>
</form>

<h2>История моих отчётов</h2>
<div class="table-wrap">
<table class="data-table">
    <thead><tr><th>Дата</th><th>Присутствие</th><th>Отчёт</th></tr></thead>
    <tbody>
    <?php if (!$history): ?>
        <tr><td colspan="3" class="empty-cell">Отчётов пока нет.</td></tr>
    <?php endif; ?>
    <?php foreach ($history as $h): ?>
        <tr>
            <td><a href="report_today.php?date=<?= e($h['report_date']) ?>"><?= fmt_date($h['report_date']) ?></a></td>
            <td><span class="badge <?= $h['is_present'] ? 'badge-verified' : 'badge-rejected' ?>"><?= $h['is_present'] ? 'Был' : 'Не был' ?></span></td>
            <td class="report-summary-cell"><?= $h['summary'] ? nl2br(e($h['summary'])) : '—' ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
