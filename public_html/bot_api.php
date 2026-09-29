<?php
/**
 * Приём ежедневных отчётов от бота MAX.
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

if ($maxUserId === '' || $text === '') {
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

// append_daily_report_note() создаёт строку за сегодня (is_present=1),
// либо дописывает текст к уже существующей — см. includes/functions.php.
append_daily_report_note($userId, $text);

// Дополнительно проставляем данные для ответа в MAX и форсируем повторную
// проверку, если отчёт за сегодня уже был проверен, а теперь пришёл ещё кусок.
$upd = $pdo->prepare(
    "UPDATE daily_reports
        SET max_chat_id = ?, max_message_id = ?, review_status = 'pending'
      WHERE user_id = ? AND report_date = ?"
);
$upd->execute([$maxChatId ?: null, $maxMessageId ?: null, $userId, $today]);

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
