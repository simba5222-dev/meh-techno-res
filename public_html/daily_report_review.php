<?php
/**
 * Проверка ежедневного отчёта (принято / не принято / частично + комментарий).
 * Обычная форма (как остальные действия на report_today.php) — не AJAX.
 * Доступно admin и mechanic. Если отчёт пришёл из группы MAX — комментарий
 * уходит туда же ответом на исходное сообщение (includes/max_client.php).
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/max_client.php';

$user = require_login();
if (!in_array($user['role'], ['admin', 'mechanic'], true)) {
    http_response_code(403);
    die('Доступ запрещён.');
}

// Куда вернуться после проверки — со страницы отчёта или из табеля за
// конкретный месяц. Разрешён только короткий список локальных страниц,
// чтобы нельзя было подсунуть редирект на чужой сайт.
$returnTo = (string) ($_POST['return_to'] ?? 'report_today.php');
if (!preg_match('/^(report_today\.php|timesheet\.php(\?year=\d{4}&month=\d{1,2})?)$/', $returnTo)) {
    $returnTo = 'report_today.php';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect($returnTo);
}

csrf_verify();

$reportId = (int) ($_POST['report_id'] ?? 0);
$verdict = (string) ($_POST['verdict'] ?? '');
$comment = trim((string) ($_POST['comment'] ?? ''));

$allowedVerdicts = ['approved', 'rejected', 'partial'];
if (!$reportId || !in_array($verdict, $allowedVerdicts, true)) {
    flash('error', 'Не переданы данные проверки.');
    redirect($returnTo);
}
if (in_array($verdict, ['rejected', 'partial'], true) && $comment === '') {
    flash('error', 'При отклонении или частичной приёмке нужен комментарий.');
    redirect($returnTo);
}

$stmt = db()->prepare('SELECT * FROM daily_reports WHERE id = ?');
$stmt->execute([$reportId]);
$report = $stmt->fetch();

if (!$report) {
    flash('error', 'Отчёт не найден.');
    redirect($returnTo);
}
if ((int) $report['user_id'] === (int) $user['id']) {
    flash('error', 'Нельзя проверять собственный отчёт.');
    redirect($returnTo);
}

$upd = db()->prepare(
    'UPDATE daily_reports
        SET review_status = ?, review_comment = ?, reviewed_by = ?, reviewed_at = NOW()
      WHERE id = ?'
);
$upd->execute([$verdict, $comment ?: null, $user['id'], $reportId]);

$notified = false;
if (!empty($report['max_chat_id']) && !empty($report['max_message_id'])) {
    $verdictText = match ($verdict) {
        'approved' => 'Отчёт принят.',
        'rejected' => 'Отчёт не принят: ' . $comment,
        'partial' => 'Принято частично: ' . $comment,
    };
    $notified = max_send_reply($report['max_chat_id'], $verdictText, $report['max_message_id']);
}

$flashMessage = 'Проверка сохранена.';
if (!empty($report['max_chat_id']) && !$notified) {
    $flashMessage .= ' Не удалось отправить ответ в MAX — смотрите лог сайта.';
}
flash('success', $flashMessage);
redirect($returnTo);
