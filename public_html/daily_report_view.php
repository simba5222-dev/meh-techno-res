<?php
/**
 * Карточка одного сотрудника за один день — открывается кликом по ячейке
 * табеля (timesheet.php). Показывает отметку присутствия/самостоятельный
 * отчёт (daily_reports) и отдельно — каждое сообщение из чата MAX за этот
 * день (max_reports), каждое со своей кнопкой проверки.
 */
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
if (!in_array($user['role'], ['admin', 'mechanic'], true)) {
    http_response_code(403);
    die('Доступ запрещён.');
}

$targetUserId = (int) ($_GET['user_id'] ?? 0);
$date = (string) ($_GET['date'] ?? '');
if (!$targetUserId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    flash('error', 'Некорректный запрос.');
    redirect('timesheet.php');
}

$targetStmt = db()->prepare('SELECT * FROM users WHERE id = ?');
$targetStmt->execute([$targetUserId]);
$target = $targetStmt->fetch();
if (!$target) {
    flash('error', 'Сотрудник не найден.');
    redirect('timesheet.php');
}

$dayStmt = db()->prepare('SELECT * FROM daily_reports WHERE user_id = ? AND report_date = ?');
$dayStmt->execute([$targetUserId, $date]);
$dayRecord = $dayStmt->fetch();

$msgStmt = db()->prepare(
    'SELECT * FROM max_reports WHERE user_id = ? AND report_date = ? ORDER BY created_at ASC'
);
$msgStmt->execute([$targetUserId, $date]);
$messages = $msgStmt->fetchAll();

$isOwn = $targetUserId === (int) $user['id'];
$backUrl = 'timesheet.php?year=' . (int) substr($date, 0, 4) . '&month=' . (int) substr($date, 5, 2);
$returnParam = 'daily_report_view.php?user_id=' . $targetUserId . '&date=' . $date;

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1><?= e($target['full_name']) ?> — <?= fmt_date($date) ?></h1>
    <a href="<?= e($backUrl) ?>" class="btn-link">← Назад к табелю</a>
</div>

<?php if ($dayRecord): ?>
<div class="form-card">
    <div class="mechanic-card-head">
        <strong>Присутствие</strong>
        <span class="badge <?= $dayRecord['is_present'] ? 'badge-verified' : 'badge-rejected' ?>">
            <?= $dayRecord['is_present'] ? 'Был на работе' : 'Не был' ?>
        </span>
    </div>
    <?php if ($dayRecord['summary']): ?>
        <p class="report-summary-cell"><?= nl2br(e($dayRecord['summary'])) ?></p>
        <p>
            <span class="badge <?= daily_report_review_class($dayRecord['review_status'] ?? 'pending') ?>">
                <?= e(daily_report_review_label($dayRecord['review_status'] ?? 'pending')) ?>
            </span>
        </p>
        <?php if (!empty($dayRecord['review_comment'])): ?>
            <p class="hint">Комментарий: <?= nl2br(e($dayRecord['review_comment'])) ?></p>
        <?php endif; ?>
        <?php if (!$isOwn && ($dayRecord['review_status'] ?? 'pending') === 'pending'): ?>
            <form method="post" action="daily_report_review.php" class="inline-form-row">
                <?= csrf_field() ?>
                <input type="hidden" name="report_id" value="<?= (int) $dayRecord['id'] ?>">
                <input type="hidden" name="return_to" value="<?= e($returnParam) ?>">
                <input type="text" name="comment" placeholder="Комментарий" style="flex:1;min-width:220px;">
                <button type="submit" name="verdict" value="approved" class="btn btn-success">Принято</button>
                <button type="submit" name="verdict" value="partial" class="btn btn-warn">Частично</button>
                <button type="submit" name="verdict" value="rejected" class="btn btn-danger">Не принято</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<h2>Сообщения из чата MAX за этот день<?= $messages ? ' <span class="count-pill">' . count($messages) . '</span>' : '' ?></h2>
<?php if (!$messages): ?>
    <p class="empty-cell">Сообщений из чата за этот день нет.</p>
<?php else: ?>
    <?php foreach ($messages as $m): $files = max_report_files((int) $m['id']); ?>
        <div class="form-card">
            <div class="mechanic-card-head">
                <span class="hint"><?= fmt_datetime($m['created_at']) ?></span>
                <span class="badge <?= daily_report_review_class($m['review_status']) ?>"><?= e(daily_report_review_label($m['review_status'])) ?></span>
            </div>
            <p class="report-summary-cell"><?= nl2br(e($m['text'])) ?></p>
            <?php if ($files): ?>
                <div class="report-attachments">
                    <?php foreach ($files as $f): ?>
                        <?php if ($f['file_type'] === 'image'): ?>
                            <a href="<?= e($f['file_path']) ?>" target="_blank"><img src="<?= e($f['file_path']) ?>" alt="" class="report-thumb"></a>
                        <?php else: ?>
                            <a href="<?= e($f['file_path']) ?>" target="_blank">🎬 видео</a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($m['review_comment']): ?>
                <p class="hint">Комментарий: <?= nl2br(e($m['review_comment'])) ?></p>
            <?php endif; ?>
            <?php if (!$isOwn): ?>
                <form method="post" action="max_report_review.php" class="inline-form-row">
                    <?= csrf_field() ?>
                    <input type="hidden" name="report_id" value="<?= (int) $m['id'] ?>">
                    <input type="hidden" name="return_to" value="<?= e($returnParam) ?>">
                    <input type="text" name="comment" placeholder="Комментарий" value="<?= e($m['review_comment'] ?? '') ?>" style="flex:1;min-width:220px;">
                    <button type="submit" name="verdict" value="approved" class="btn btn-success">Принято</button>
                    <button type="submit" name="verdict" value="partial" class="btn btn-warn">Частично</button>
                    <button type="submit" name="verdict" value="rejected" class="btn btn-danger">Не принято</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
