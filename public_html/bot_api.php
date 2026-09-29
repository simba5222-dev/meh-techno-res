<?php
/**
 * Приём отчётов от бота MAX. Каждое сообщение с триггером — отдельная
 * строка в max_reports (проверяется по одному, не склеивается за день).
 * daily_reports обновляется только для отметки присутствия (is_present).
 * Вызывается ботом (/home/claude/max), не браузером — вместо сессии/CSRF
 * проверяется заголовок X-Bot-Token (см. BOT_API_TOKEN в config.php).
 * Поддерживает ?dry_run=1 — прогоняет все проверки, ничего не пишет в БД.
 */
require __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function json_fail(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = $_SERVER['HTTP_X_BOT_TOKEN'] ?? '';
if (!defined('BOT_API_TOKEN') || BOT_API_TOKEN === '' || !hash_equals(BOT_API_TOKEN, $token)) {
    json_fail('unauthorized', 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail('method_not_allowed', 405);
}

$action = $_POST['action'] ?? '';
$dryRun = ($_GET['dry_run'] ?? '') === '1';

if ($action !== 'submit_report') {
    json_fail('unknown_action', 400);
}

$maxUserId = trim((string) ($_POST['max_user_id'] ?? ''));
$text = trim((string) ($_POST['text'] ?? ''));
$maxMessageId = trim((string) ($_POST['max_message_id'] ?? ''));
$maxChatId = trim((string) ($_POST['max_chat_id'] ?? ''));

if ($maxUserId === '' || $text === '' || $maxMessageId === '' || $maxChatId === '') {
    json_fail('missing_fields', 400);
}

$stmt = db()->prepare(
    "SELECT * FROM users WHERE max_user_id = ? AND role IN ('mechanic','fitter') AND is_active = 1"
);
$stmt->execute([$maxUserId]);
$sender = $stmt->fetch();

if (!$sender) {
    json_fail('unlinked_sender', 404);
}

if ($dryRun) {
    echo json_encode([
        'ok' => true,
        'dry_run' => true,
        'would_record_for' => $sender['full_name'],
        'user_id' => (int) $sender['id'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
$userId = (int) $sender['id'];
$today = date('Y-m-d');

// Отмечаем присутствие на сегодня, текст сюда больше не пишем — он теперь
// живёт отдельной строкой в max_reports (см. ниже), чтобы каждое сообщение
// проверялось по отдельности.
$pdo->prepare(
    "INSERT INTO daily_reports (user_id, report_date, is_present) VALUES (?, ?, 1)
     ON DUPLICATE KEY UPDATE is_present = 1"
)->execute([$userId, $today]);

try {
    $ins = $pdo->prepare(
        'INSERT INTO max_reports (user_id, report_date, text, max_chat_id, max_message_id)
         VALUES (?, ?, ?, ?, ?)'
    );
    $ins->execute([$userId, $today, $text, $maxChatId, $maxMessageId]);
    $reportId = (int) $pdo->lastInsertId();
} catch (PDOException $ex) {
    if ($ex->getCode() === '23000') {
        // Дубликат max_message_id — это сообщение уже приходило раньше
        // (например, бот переопросил его после перезапуска). Не ошибка.
        echo json_encode(['ok' => true, 'duplicate' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }
    throw $ex;
}

$filesResult = save_max_report_files($reportId, $_FILES['files'] ?? []);

echo json_encode([
    'ok' => true,
    'report_id' => $reportId,
    'files_saved' => $filesResult['saved'],
    'file_errors' => $filesResult['errors'],
], JSON_UNESCAPED_UNICODE);
