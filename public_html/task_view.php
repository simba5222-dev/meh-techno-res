<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

if ($user['role'] === 'manager') {
    redirect('equipment.php');
}

$isAdmin = $user['role'] === 'admin';

$id = (int) ($_GET['id'] ?? 0);

$stmt = db()->prepare(
    'SELECT t.*, e.inventory_number, e.name AS equipment_name, e.id AS eq_id,
            u1.full_name AS assignee_name, u2.full_name AS creator_name,
            o.name AS object_name
     FROM tasks t
     JOIN equipment e ON e.id = t.equipment_id
     LEFT JOIN users u1 ON u1.id = t.assignee_id
     LEFT JOIN users u2 ON u2.id = t.created_by
     LEFT JOIN objects o ON o.id = t.object_id
     WHERE t.id = ?'
);
$stmt->execute([$id]);
$task = $stmt->fetch();

if (!$task) {
    flash('error', 'Задача не найдена.');
    redirect('tasks.php');
}

if (!$isAdmin && (int) $task['assignee_id'] !== (int) $user['id']) {
    http_response_code(403);
    die('Доступ запрещён: эта задача назначена не вам.');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_status') {
        $newStatus = $_POST['new_status'] ?? '';
        $comment = trim($_POST['comment'] ?? '');

        if (!can_set_status($user['role'], $newStatus)) {
            $errors[] = 'Вам не разрешено переводить задачи в этот статус.';
        } elseif ($newStatus === $task['status']) {
            $errors[] = 'Задача уже в этом статусе.';
        } else {
            try {
                change_task_status($task, $newStatus, $user, $comment);

                if (!empty($_FILES['photos']['name'][0])) {
                    $result = save_task_photos($id, $user['id'], $_FILES['photos']);
                    foreach ($result['errors'] as $e) {
                        $errors[] = $e;
                    }
                }

                flash('success', 'Статус задачи обновлён: ' . task_status_label($newStatus) . '.');
                redirect('task_view.php?id=' . $id);
            } catch (Exception $ex) {
                $errors[] = 'Ошибка: ' . $ex->getMessage();
            }
        }
    } elseif ($action === 'comment_only') {
        $comment = trim($_POST['comment'] ?? '');
        if ($comment === '' && empty($_FILES['photos']['name'][0])) {
            $errors[] = 'Добавьте комментарий или фото.';
        } else {
            $hist = db()->prepare('INSERT INTO task_history (task_id, user_id, old_status, new_status, comment) VALUES (?,?,?,?,?)');
            $hist->execute([$id, $user['id'], $task['status'], $task['status'], $comment ?: null]);

            if (!empty($_FILES['photos']['name'][0])) {
                $result = save_task_photos($id, $user['id'], $_FILES['photos']);
                foreach ($result['errors'] as $e) {
                    $errors[] = $e;
                }
            }
            if (!$errors) {
                flash('success', 'Комментарий добавлен.');
                redirect('task_view.php?id=' . $id);
            }
        }
    } elseif ($action === 'assign_fitters') {
        $canAssign = $isAdmin || (int) $task['assignee_id'] === (int) $user['id'];
        if (!$canAssign) {
            $errors[] = 'Назначать слесарей может главный механик или механик, которому поручена задача.';
        } else {
            $fitterIds = array_map('intval', (array) ($_POST['fitter_ids'] ?? []));
            set_task_assignees($id, $fitterIds, (int) $user['id']);

            $names = [];
            foreach (task_assignees($id) as $f) {
                $names[] = $f['full_name'];
            }

            $hist = db()->prepare('INSERT INTO task_history (task_id, user_id, old_status, new_status, comment) VALUES (?,?,?,?,?)');
            $hist->execute([
                $id, $user['id'], $task['status'], $task['status'],
                $names ? ('Назначены слесари: ' . implode(', ', $names)) : 'Слесари сняты с задачи',
            ]);

            flash('success', 'Исполнители обновлены.');
            redirect('task_view.php?id=' . $id);
        }
    } elseif ($action === 'reassign' && $isAdmin) {
        $newAssignee = $_POST['assignee_id'] !== '' ? (int) $_POST['assignee_id'] : null;
        $stmt2 = db()->prepare('UPDATE tasks SET assignee_id = ? WHERE id = ?');
        $stmt2->execute([$newAssignee, $id]);

        $mechName = 'не назначен';
        if ($newAssignee) {
            $mstmt = db()->prepare('SELECT full_name FROM users WHERE id=?');
            $mstmt->execute([$newAssignee]);
            $mechName = $mstmt->fetchColumn() ?: 'не назначен';
        }

        $hist = db()->prepare('INSERT INTO task_history (task_id, user_id, old_status, new_status, comment) VALUES (?,?,?,?,?)');
        $hist->execute([$id, $user['id'], $task['status'], $task['status'], 'Назначен исполнитель: ' . $mechName]);

        flash('success', 'Исполнитель обновлён.');
        redirect('task_view.php?id=' . $id);
    }

    // перечитать задачу после возможного обновления статуса (если были ошибки и редиректа не было)
    $stmt->execute([$id]);
    $task = $stmt->fetch();
}

$historyStmt = db()->prepare(
    'SELECT h.*, u.full_name FROM task_history h JOIN users u ON u.id = h.user_id WHERE h.task_id = ? ORDER BY h.created_at ASC'
);
$historyStmt->execute([$id]);
$history = $historyStmt->fetchAll();

$photosStmt = db()->prepare(
    'SELECT p.*, u.full_name FROM task_photos p JOIN users u ON u.id = p.uploaded_by WHERE p.task_id = ? ORDER BY p.uploaded_at DESC'
);
$photosStmt->execute([$id]);
$photos = $photosStmt->fetchAll();

$allowedNow = statuses_allowed_for_role($user['role']);
unset($allowedNow[$task['status']]);

$assignedFitters = task_assignees($id);
$assignedFitterIds = array_map(static fn ($f) => (int) $f['id'], $assignedFitters);

$canAssignFitters = $isAdmin || (int) $task['assignee_id'] === (int) $user['id'];
$fittersForObject = $canAssignFitters
    ? users_of_role('fitter', $task['object_id'] ? (int) $task['object_id'] : null)
    : [];
$mechanicsForReassign = $isAdmin ? db()->query("SELECT id, full_name FROM users WHERE role='mechanic' AND is_active=1 ORDER BY full_name")->fetchAll() : [];

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>#<?= (int) $task['id'] ?> <?= e($task['title']) ?> <span class="badge" style="<?= e(task_status_style($task['status'])) ?>"><?= task_status_label($task['status']) ?></span></h1>
    <div>
        <?php if ($isAdmin): ?><a href="task_edit.php?id=<?= (int) $task['id'] ?>" class="btn-link">Изменить</a> · <?php endif; ?>
        <a href="tasks.php" class="btn-link">← К задачам</a>
    </div>
</div>

<?php foreach ($errors as $err): ?>
    <div class="flash flash-error"><?= e($err) ?></div>
<?php endforeach; ?>

<div class="detail-grid">
    <div><span class="label">Техника</span><a href="equipment_view.php?id=<?= (int) $task['eq_id'] ?>"><?= e($task['inventory_number'] . ' — ' . $task['equipment_name']) ?></a></div>
    <div><span class="label">Приоритет</span><span class="badge <?= priority_class($task['priority']) ?>"><?= priority_label($task['priority']) ?></span></div>
    <div><span class="label">Объект</span><?= e($task['object_name'] ?? '—') ?></div>
    <div><span class="label">Механик участка</span><?= e($task['assignee_name'] ?? 'не назначен') ?></div>
    <div><span class="label">Слесари</span><?= $assignedFitters ? e(implode(', ', array_column($assignedFitters, 'full_name'))) : 'не назначены' ?></div>
    <div><span class="label">Постановщик</span><?= e($task['creator_name'] ?? '—') ?></div>
    <div><span class="label">Срок</span><span class="<?= task_is_overdue($task) ? 'overdue-text' : '' ?>"><?= fmt_date($task['due_date']) ?><?= task_is_overdue($task) ? ' ⚠ просрочено' : '' ?></span></div>
    <div><span class="label">Создана</span><?= fmt_datetime($task['created_at']) ?></div>
</div>

<?php if ($task['description']): ?>
    <p class="notes-block"><?= nl2br(e($task['description'])) ?></p>
<?php endif; ?>

<?php if ($isAdmin): ?>
<div class="card">
    <h3>Переназначить исполнителя</h3>
    <form method="post" class="inline-form-row">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reassign">
        <select name="assignee_id">
            <option value="">Не назначен</option>
            <?php foreach ($mechanicsForReassign as $m): ?>
                <option value="<?= (int) $m['id'] ?>" <?= (int) $task['assignee_id'] === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn">Сохранить</button>
    </form>
</div>
<?php endif; ?>

<?php if ($canAssignFitters): ?>
<div class="card">
    <h3>Слесари на задаче</h3>
    <?php if (!$fittersForObject): ?>
        <p class="hint">Для этого объекта пока нет закреплённых слесарей. Заведите их в разделе «Сотрудники» и закрепите за объектом.</p>
    <?php else: ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="assign_fitters">
        <div class="checkbox-grid">
            <?php foreach ($fittersForObject as $f): ?>
                <label class="checkbox-label">
                    <input type="checkbox" name="fitter_ids[]" value="<?= (int) $f['id'] ?>"
                        <?= in_array((int) $f['id'], $assignedFitterIds, true) ? 'checked' : '' ?>>
                    <?= e($f['full_name']) ?>
                </label>
            <?php endforeach; ?>
        </div>
        <p class="hint">Можно выбрать нескольких — тогда задача считается общей. Если работы нужно разделить, создайте отдельные задачи на каждого.</p>
        <button type="submit" class="btn">Сохранить исполнителей</button>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($allowedNow): ?>
<div class="card">
    <h3>Изменить статус</h3>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_status">
        <div class="status-buttons">
            <?php foreach ($allowedNow as $stCode => $stRow): ?>
                <label class="radio-pill">
                    <input type="radio" name="new_status" value="<?= e($stCode) ?>" required>
                    <?= e($stRow['name']) ?>
                </label>
            <?php endforeach; ?>
        </div>
        <label>Комментарий
            <textarea name="comment" rows="2" placeholder="Что сделано / что мешает выполнить"></textarea>
        </label>
        <label>Фото (можно несколько)
            <input type="file" name="photos[]" accept="image/*" multiple capture="environment">
        </label>
        <button type="submit" class="btn btn-primary">Применить</button>
    </form>
</div>
<?php else: ?>
    <p class="hint">Вам не разрешено менять статус этой задачи.</p>
<?php endif; ?>

<div class="card">
    <h3>Добавить комментарий / фото без смены статуса</h3>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="comment_only">
        <textarea name="comment" rows="2" placeholder="Комментарий"></textarea>
        <label>Фото
            <input type="file" name="photos[]" accept="image/*" multiple capture="environment">
        </label>
        <button type="submit" class="btn">Отправить</button>
    </form>
</div>

<?php if ($photos): ?>
<div class="card">
    <h3>Фотографии (<?= count($photos) ?>)</h3>
    <div class="photo-grid">
        <?php foreach ($photos as $p): ?>
            <a href="<?= e($p['file_path']) ?>" target="_blank" class="photo-thumb">
                <img src="<?= e($p['file_path']) ?>" alt="фото" loading="lazy">
                <span><?= e($p['full_name']) ?>, <?= fmt_datetime($p['uploaded_at']) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <h3>История</h3>
    <ul class="history-list">
        <?php foreach (array_reverse($history) as $h): ?>
            <li>
                <div class="history-meta">
                    <strong><?= e($h['full_name']) ?></strong> · <?= fmt_datetime($h['created_at']) ?>
                    <?php if ($h['old_status'] !== $h['new_status']): ?>
                        · <?= task_status_label($h['old_status'] ?? '') ?> → <span class="badge" style="<?= e(task_status_style($h['new_status'])) ?>"><?= task_status_label($h['new_status']) ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($h['comment']): ?><div class="history-comment"><?= nl2br(e($h['comment'])) ?></div><?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
