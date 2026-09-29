<?php
/**
 * Общие вспомогательные функции.
 */

/** Безопасный вывод строки в HTML */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/** CSRF-токен: получить (создать при необходимости) */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** CSRF-токен: скрытое поле для формы */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** CSRF-токен: проверка при обработке POST-запроса */
function csrf_verify(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(400);
        die('Ошибка проверки формы (CSRF). Обновите страницу и попробуйте снова.');
    }
}

/** Редирект с последующим завершением скрипта */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/** Положить flash-сообщение в сессию */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** Забрать и очистить flash-сообщения */
function get_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

/**
 * Все статусы задач из справочника, ключ — code.
 * Результат кэшируется в рамках запроса.
 */
function task_statuses_all(bool $onlyActive = true): array
{
    static $cache = [];
    $key = $onlyActive ? 'active' : 'all';

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $sql = 'SELECT * FROM task_statuses';
    if ($onlyActive) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY sort_order, id';

    $rows = db()->query($sql)->fetchAll();
    $byCode = [];
    foreach ($rows as $row) {
        $byCode[$row['code']] = $row;
    }

    $cache[$key] = $byCode;
    return $byCode;
}

/** Один статус по коду (в том числе отключённый — чтобы старые задачи не ломались) */
function task_status_get(string $code): ?array
{
    $all = task_statuses_all(false);
    return $all[$code] ?? null;
}

/** Человекочитаемое название статуса */
function task_status_label(string $status): string
{
    $row = task_status_get($status);
    return $row ? $row['name'] : $status;
}

/** Inline-стиль бейджа под статус (цвета берутся из справочника) */
function task_status_style(string $status): string
{
    $row = task_status_get($status);
    if (!$row) {
        return '';
    }
    return 'background:' . $row['color_bg'] . ';color:' . $row['color_text'] . ';';
}

/**
 * Оставлено для совместимости со старой разметкой: класса больше нет,
 * оформление задаётся через task_status_style().
 */
function task_status_class(string $status): string
{
    return '';
}

/** Статус, в котором создаётся новая задача */
function task_status_initial(): string
{
    foreach (task_statuses_all() as $code => $row) {
        if ($row['is_initial']) {
            return $code;
        }
    }
    $all = task_statuses_all();
    return $all ? array_key_first($all) : 'new';
}

/** Коды завершающих статусов (задача считается закрытой) */
function final_status_codes(): array
{
    $codes = [];
    foreach (task_statuses_all(false) as $code => $row) {
        if ($row['is_final']) {
            $codes[] = $code;
        }
    }
    return $codes;
}

/**
 * SQL-условие «задача ещё в работе» для подстановки в запросы.
 * $alias — префикс таблицы, например 't' или ''.
 */
function sql_not_final(string $alias = ''): string
{
    $col = ($alias !== '' ? $alias . '.' : '') . 'status';
    $codes = final_status_codes();
    if (!$codes) {
        return '1=1';
    }
    $quoted = array_map(static fn ($c) => db()->quote($c), $codes);
    return $col . ' NOT IN (' . implode(',', $quoted) . ')';
}

/** Обратное условие — задача закрыта */
function sql_is_final(string $alias = ''): string
{
    $col = ($alias !== '' ? $alias . '.' : '') . 'status';
    $codes = final_status_codes();
    if (!$codes) {
        return '0=1';
    }
    $quoted = array_map(static fn ($c) => db()->quote($c), $codes);
    return $col . ' IN (' . implode(',', $quoted) . ')';
}

/** Статусы, в которые пользователь с данной ролью вправе перевести задачу */
function statuses_allowed_for_role(string $role): array
{
    $column = match ($role) {
        'admin' => 'allow_admin',
        'mechanic' => 'allow_mechanic',
        'fitter' => 'allow_fitter',
        default => null,
    };

    if ($column === null) {
        return [];
    }

    $result = [];
    foreach (task_statuses_all() as $code => $row) {
        if ($row[$column]) {
            $result[$code] = $row;
        }
    }
    return $result;
}

/** Может ли роль перевести задачу в конкретный статус */
function can_set_status(string $role, string $code): bool
{
    return array_key_exists($code, statuses_allowed_for_role($role));
}

/** Человекочитаемые приоритеты */
function priority_label(string $priority): string
{
    return match ($priority) {
        'low' => 'Низкий',
        'normal' => 'Обычный',
        'high' => 'Высокий',
        'urgent' => 'Срочно',
        default => $priority,
    };
}

function priority_class(string $priority): string
{
    return match ($priority) {
        'low' => 'prio-low',
        'normal' => 'prio-normal',
        'high' => 'prio-high',
        'urgent' => 'prio-urgent',
        default => '',
    };
}

/** Человекочитаемый статус техники */
function equipment_status_label(string $status): string
{
    return match ($status) {
        'working' => 'В работе',
        'repair' => 'На ремонте',
        'idle' => 'Простой',
        default => $status,
    };
}

function equipment_status_class(string $status): string
{
    return match ($status) {
        'working' => 'badge-verified',
        'repair' => 'badge-rejected',
        'idle' => 'badge-new',
        default => '',
    };
}

/** Формат даты для отображения */
function fmt_date(?string $datetime): string
{
    if (!$datetime) {
        return '—';
    }
    $ts = strtotime($datetime);
    return $ts ? date('d.m.Y', $ts) : '—';
}

/** Формат даты и времени для отображения */
function fmt_datetime(?string $datetime): string
{
    if (!$datetime) {
        return '—';
    }
    $ts = strtotime($datetime);
    return $ts ? date('d.m.Y H:i', $ts) : '—';
}

/** Просрочена ли задача (есть срок, он прошёл, задача не закрыта) */
function task_is_overdue(array $task): bool
{
    if (empty($task['due_date'])) {
        return false;
    }
    $statusRow = task_status_get($task['status']);
    if ($statusRow && $statusRow['is_final']) {
        return false;
    }
    return strtotime($task['due_date']) < strtotime(date('Y-m-d'));
}

/**
 * Сохранить загруженные фотографии к задаче.
 * Возвращает массив: ['saved' => int, 'errors' => string[]]
 */
function save_task_photos(int $taskId, int $userId, array $filesInput): array
{
    $saved = 0;
    $errors = [];

    if (empty($filesInput['name'][0])) {
        return ['saved' => 0, 'errors' => []];
    }

    $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $targetDir = rtrim(UPLOAD_DIR, '/') . '/' . $taskId;

    if (!is_dir($targetDir)) {
        if (!mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            return ['saved' => 0, 'errors' => ['Не удалось создать папку для загрузки файлов.']];
        }
    }

    $count = count($filesInput['name']);
    for ($i = 0; $i < $count; $i++) {
        if ($filesInput['error'][$i] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($filesInput['error'][$i] !== UPLOAD_ERR_OK) {
            $errors[] = 'Ошибка загрузки файла «' . $filesInput['name'][$i] . '».';
            continue;
        }
        if ($filesInput['size'][$i] > MAX_UPLOAD_SIZE) {
            $errors[] = 'Файл «' . $filesInput['name'][$i] . '» превышает допустимый размер (10 МБ).';
            continue;
        }

        $ext = strtolower(pathinfo($filesInput['name'][$i], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            $errors[] = 'Файл «' . $filesInput['name'][$i] . '»: недопустимый формат (разрешены JPG, PNG, WEBP, GIF).';
            continue;
        }

        $newName = bin2hex(random_bytes(8)) . '.' . $ext;
        $destPath = $targetDir . '/' . $newName;

        if (move_uploaded_file($filesInput['tmp_name'][$i], $destPath)) {
            $relativePath = UPLOAD_URL . '/' . $taskId . '/' . $newName;
            $stmt = db()->prepare('INSERT INTO task_photos (task_id, file_path, uploaded_by) VALUES (?,?,?)');
            $stmt->execute([$taskId, $relativePath, $userId]);
            $saved++;
        } else {
            $errors[] = 'Не удалось сохранить файл «' . $filesInput['name'][$i] . '».';
        }
    }

    return ['saved' => $saved, 'errors' => $errors];
}

/**
 * Добавить строку в ежедневный отчёт пользователя за сегодня
 * (например, автоматическую отметку о выполненной задаче).
 * Если отчёта за сегодня ещё нет — создаёт его (считая, что раз задача
 * выполняется, значит человек присутствует на работе).
 */
function append_daily_report_note(int $userId, string $note): void
{
    $today = date('Y-m-d');
    $pdo = db();

    $stmt = $pdo->prepare('SELECT id, summary FROM daily_reports WHERE user_id = ? AND report_date = ?');
    $stmt->execute([$userId, $today]);
    $existing = $stmt->fetch();

    if ($existing) {
        $newSummary = trim(($existing['summary'] ?? '') !== '' ? $existing['summary'] . "\n" . $note : $note);
        $upd = $pdo->prepare('UPDATE daily_reports SET summary = ?, is_present = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $upd->execute([$newSummary, $existing['id']]);
    } else {
        $ins = $pdo->prepare('INSERT INTO daily_reports (user_id, report_date, is_present, summary) VALUES (?, ?, 1, ?)');
        $ins->execute([$userId, $today, $note]);
    }
}

/** Человекочитаемый статус проверки ежедневного отчёта */
function daily_report_review_label(string $status): string
{
    return match ($status) {
        'pending' => 'На проверке',
        'approved' => 'Принято',
        'rejected' => 'Не принято',
        'partial' => 'Частично',
        default => $status,
    };
}

/** CSS-класс бейджа под статус проверки отчёта */
function daily_report_review_class(string $status): string
{
    return match ($status) {
        'approved' => 'badge-verified',
        'rejected' => 'badge-rejected',
        'partial' => 'badge-progress',
        default => 'badge-new',
    };
}

/** Человекочитаемое название роли */
function role_label(string $role): string
{
    return match ($role) {
        'admin' => 'Главный механик',
        'mechanic' => 'Механик участка',
        'fitter' => 'Слесарь',
        'manager' => 'Менеджер',
        default => $role,
    };
}

/** Список объектов (участков). По умолчанию только активные. */
function objects_list(bool $onlyActive = true): array
{
    $sql = 'SELECT * FROM objects';
    if ($onlyActive) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY name';
    return db()->query($sql)->fetchAll();
}

/** Один объект по id */
function object_get(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM objects WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** id объектов, за которыми закреплён пользователь */
function user_object_ids(int $userId): array
{
    $stmt = db()->prepare('SELECT object_id FROM user_objects WHERE user_id = ?');
    $stmt->execute([$userId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** Названия объектов пользователя одной строкой (для таблиц) */
function user_objects_label(int $userId): string
{
    $stmt = db()->prepare(
        'SELECT o.name FROM user_objects uo JOIN objects o ON o.id = uo.object_id
         WHERE uo.user_id = ? ORDER BY o.name'
    );
    $stmt->execute([$userId]);
    $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return $names ? implode(', ', $names) : '—';
}

/**
 * Переписать закрепление пользователя за объектами.
 * $objectIds — массив id; пустой массив снимает все привязки.
 */
function set_user_objects(int $userId, array $objectIds): void
{
    $pdo = db();
    $del = $pdo->prepare('DELETE FROM user_objects WHERE user_id = ?');
    $del->execute([$userId]);

    if (!$objectIds) {
        return;
    }

    $ins = $pdo->prepare('INSERT IGNORE INTO user_objects (user_id, object_id) VALUES (?,?)');
    foreach ($objectIds as $objectId) {
        $objectId = (int) $objectId;
        if ($objectId > 0) {
            $ins->execute([$userId, $objectId]);
        }
    }
}

/**
 * Активные пользователи заданной роли, закреплённые за объектом.
 * Если $objectId не указан — все активные пользователи этой роли.
 */
function users_of_role(string $role, ?int $objectId = null): array
{
    if ($objectId) {
        $stmt = db()->prepare(
            'SELECT u.* FROM users u
             JOIN user_objects uo ON uo.user_id = u.id AND uo.object_id = ?
             WHERE u.role = ? AND u.is_active = 1
             ORDER BY u.full_name'
        );
        $stmt->execute([$objectId, $role]);
        return $stmt->fetchAll();
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE role = ? AND is_active = 1 ORDER BY full_name');
    $stmt->execute([$role]);
    return $stmt->fetchAll();
}

/**
 * Сменить статус задачи: пишет tasks.status, историю, при необходимости
 * закрывает задачу и добавляет строку в ежедневный отчёт исполнителя.
 * Права должны быть проверены вызывающим кодом.
 * Возвращает true при успехе, бросает исключение при ошибке БД.
 */
function change_task_status(array $task, string $newStatus, array $actor, string $comment = ''): bool
{
    $statusRow = task_status_get($newStatus);
    if (!$statusRow) {
        return false;
    }

    $taskId = (int) $task['id'];
    $pdo = db();
    $pdo->beginTransaction();

    try {
        if ($statusRow['is_final']) {
            $upd = $pdo->prepare('UPDATE tasks SET status=?, closed_at=? WHERE id=?');
            $upd->execute([$newStatus, date('Y-m-d H:i:s'), $taskId]);
        } else {
            $upd = $pdo->prepare('UPDATE tasks SET status=?, closed_at=NULL WHERE id=?');
            $upd->execute([$newStatus, $taskId]);
        }

        $hist = $pdo->prepare(
            'INSERT INTO task_history (task_id, user_id, old_status, new_status, comment) VALUES (?,?,?,?,?)'
        );
        $hist->execute([$taskId, $actor['id'], $task['status'], $newStatus, $comment !== '' ? $comment : null]);

        if ($statusRow['writes_report']) {
            $noteLine = 'Задача #' . $taskId . ' «' . $task['title'] . '»';
            if (!empty($task['inventory_number'])) {
                $noteLine .= ' (' . $task['inventory_number']
                    . (!empty($task['equipment_name']) ? ' — ' . $task['equipment_name'] : '') . ')';
            }
            $noteLine .= ' — ' . mb_strtolower($statusRow['name']) . '.';
            if ($comment !== '') {
                $noteLine .= ' ' . $comment;
            }

            // Строка попадает в отчёт исполнителей-слесарей, если они назначены,
            // иначе — в отчёт того, кто сменил статус.
            $recipients = task_assignee_ids($taskId);
            if (!$recipients) {
                $recipients = [(int) $actor['id']];
            }
            foreach ($recipients as $recipientId) {
                append_daily_report_note($recipientId, $noteLine);
            }
        }

        $pdo->commit();
        return true;
    } catch (Exception $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

/** id слесарей, назначенных на задачу */
function task_assignee_ids(int $taskId): array
{
    $stmt = db()->prepare('SELECT user_id FROM task_assignees WHERE task_id = ?');
    $stmt->execute([$taskId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** Слесари, назначенные на задачу, с именами */
function task_assignees(int $taskId): array
{
    $stmt = db()->prepare(
        'SELECT u.id, u.full_name FROM task_assignees ta
         JOIN users u ON u.id = ta.user_id
         WHERE ta.task_id = ? ORDER BY u.full_name'
    );
    $stmt->execute([$taskId]);
    return $stmt->fetchAll();
}

/** Переписать список слесарей на задаче */
function set_task_assignees(int $taskId, array $userIds, int $assignedBy): void
{
    $pdo = db();
    $del = $pdo->prepare('DELETE FROM task_assignees WHERE task_id = ?');
    $del->execute([$taskId]);

    if (!$userIds) {
        return;
    }

    $ins = $pdo->prepare('INSERT IGNORE INTO task_assignees (task_id, user_id, assigned_by) VALUES (?,?,?)');
    foreach ($userIds as $userId) {
        $userId = (int) $userId;
        if ($userId > 0) {
            $ins->execute([$taskId, $userId, $assignedBy]);
        }
    }
}
