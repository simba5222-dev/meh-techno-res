<?php
/**
 * Проверка ОДНОГО сообщения-отчёта из чата MAX (принято/частично/не принято
 * + комментарий). Каждое сообщение проверяется отдельно — см. bot_api.php,
 * где каждое сообщение с триггером создаёт свою строку в max_reports.
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/max_client.php';

$user = require_login();
if (!in_array($user['role'], ['admin', 'mechanic'], true)) {
    http_response_code(403);
    die('Доступ запрещён.');
}

$returnTo = (string) ($_POST['return_to'] ?? 'report_today.php');
$allowedReturn = '/^(report_today\.php|timesheet\.php(\?year=\d{4}&month=\d{1,2})?|daily_report_view\.php\?user_id=\d+&date=\d{4}-\d{2}-\d{2})$/';
if (!preg_match($allowedReturn, $returnTo)) {
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

$stmt = db()->prepare('SELECT * FROM max_reports WHERE id = ?');
$stmt->execute([$reportId]);
$report = $stmt->fetch();

if (!$report) {
    flash('error', 'Сообщение не найдено.');
    redirect($returnTo);
}
if ((int) $report['user_id'] === (int) $user['id']) {
    flash('error', 'Нельзя проверять собственное сообщение.');
    redirect($returnTo);
}

$upd = db()->prepare(
    'UPDATE max_reports SET review_status = ?, review_comment = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?'
);
$upd->execute([$verdict, $comment ?: null, $user['id'], $reportId]);

$verdictText = match ($verdict) {
    'approved' => 'Отчёт принят.',
    'rejected' => 'Отчёт не принят: ' . $comment,
    'partial' => 'Принято частично: ' . $comment,
};
$notified = max_send_reply($report['max_chat_id'], $verdictText, $report['max_message_id']);

$flashMessage = 'Проверка сохранена.';
if (!$notified) {
    $flashMessage .= ' Не удалось отправить ответ в MAX — смотрите лог сайта.';
}
flash('success', $flashMessage);
redirect($returnTo);
