<?php
/**
 * Смена статуса задачи перетаскиванием на доске.
 * Принимает POST: task_id, new_status, csrf_token. Отвечает JSON.
 */
require __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function json_fail(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = current_user();
if (!$user) {
    json_fail('Сессия истекла, войдите заново.', 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail('Некорректный запрос.', 405);
}

$token = $_POST['csrf_token'] ?? '';
if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    json_fail('Ошибка проверки формы. Обновите страницу.', 400);
}

$taskId = (int) ($_POST['task_id'] ?? 0);
$newStatus = (string) ($_POST['new_status'] ?? '');

if (!$taskId || $newStatus === '') {
    json_fail('Не переданы данные задачи.');
}

$stmt = db()->prepare(
    'SELECT t.*, e.inventory_number, e.name AS equipment_name
       FROM tasks t JOIN equipment e ON e.id = t.equipment_id
      WHERE t.id = ?'
);
$stmt->execute([$taskId]);
$task = $stmt->fetch();

if (!$task) {
    json_fail('Задача не найдена.', 404);
}

// Механик двигает только свои задачи, главный механик — любые
if ($user['role'] !== 'admin' && (int) $task['assignee_id'] !== (int) $user['id']) {
    json_fail('Эта задача назначена не вам.', 403);
}

if (!can_set_status($user['role'], $newStatus)) {
    json_fail('Вам не разрешено переводить задачи в статус «' . task_status_label($newStatus) . '».', 403);
}

if ($task['status'] === $newStatus) {
    echo json_encode(['ok' => true, 'unchanged' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    change_task_status($task, $newStatus, $user, '');
} catch (Exception $ex) {
    json_fail('Не удалось сохранить: ' . $ex->getMessage(), 500);
}

echo json_encode([
    'ok' => true,
    'task_id' => $taskId,
    'status' => $newStatus,
    'status_label' => task_status_label($newStatus),
], JSON_UNESCAPED_UNICODE);
