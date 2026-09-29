<?php
/**
 * Карточка одного дневного отчёта — открывается кликом по ячейке табеля
 * (timesheet.php) или напрямую по ссылке. Проверка (принято/частично/не
 * принято) отправляется в daily_report_review.php.
 */
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
if (!in_array($user['role'], ['admin', 'mechanic'], true)) {
    http_response_code(403);
    die('Доступ запрещён.');
}

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare(
    'SELECT dr.*, u.full_name, u.role AS author_role
       FROM daily_reports dr JOIN users u ON u.id = dr.user_id
      WHERE dr.id = ?'
);
$stmt->execute([$id]);
$report = $stmt->fetch();

if (!$report) {
    flash('error', 'Отчёт не найден.');
    redirect('timesheet.php');
}

$isOwn = (int) $report['user_id'] === (int) $user['id'];

$backUrl = 'timesheet.php?year=' . (int) substr($report['report_date'], 0, 4)
    . '&month=' . (int) substr($report['report_date'], 5, 2);

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>Отчёт за <?= fmt_date($report['report_date']) ?></h1>
    <a href="<?= e($backUrl) ?>" class="btn-link">← Назад к табелю</a>
</div>

<div class="form-card">
    <div class="mechanic-card-head">
        <strong><?= e($report['full_name']) ?></strong>
        <span class="hint"><?= e(role_label($report['author_role'])) ?>
            <?= $report['max_chat_id'] ? ' · из чата MAX' : ' · внесено на сайте' ?></span>
    </div>
    <p>
        <span class="badge <?= $report['is_present'] ? 'badge-verified' : 'badge-rejected' ?>">
            <?= $report['is_present'] ? 'Был на работе' : 'Не был на работе' ?>
        </span>
        <span class="badge <?= daily_report_review_class($report['review_status'] ?? 'pending') ?>">
            <?= e(daily_report_review_label($report['review_status'] ?? 'pending')) ?>
        </span>
    </p>
    <p class="report-summary-cell"><?= $report['summary'] ? nl2br(e($report['summary'])) : '—' ?></p>

    <?php if (!empty($report['review_comment'])): ?>
        <p class="hint">Комментарий проверяющего: <?= nl2br(e($report['review_comment'])) ?></p>
    <?php endif; ?>
</div>

<?php if ($isOwn): ?>
    <p class="hint">Нельзя проверять собственный отчёт.</p>
<?php else: ?>
    <h2>Проверка</h2>
    <form method="post" action="daily_report_review.php" class="form-card">
        <?= csrf_field() ?>
        <input type="hidden" name="report_id" value="<?= (int) $report['id'] ?>">
        <input type="hidden" name="return_to" value="<?= e($backUrl) ?>">
        <label>Комментарий <span class="hint">(обязателен при «не принято» / «частично» — уйдёт в чат MAX, если отчёт пришёл оттуда)</span>
            <textarea name="comment" rows="3"><?= e($report['review_comment'] ?? '') ?></textarea>
        </label>
        <div class="inline-form-row">
            <button type="submit" name="verdict" value="approved" class="btn btn-success">Принято</button>
            <button type="submit" name="verdict" value="partial" class="btn btn-warn">Частично</button>
            <button type="submit" name="verdict" value="rejected" class="btn btn-danger">Не принято</button>
        </div>
    </form>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
