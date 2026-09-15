<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_admin();

$errors = [];

function status_form_values(array $src): array
{
    return [
        'name'           => trim($src['name'] ?? ''),
        'color_bg'       => trim($src['color_bg'] ?? '#eef1f5'),
        'color_text'     => trim($src['color_text'] ?? '#172033'),
        'is_initial'     => !empty($src['is_initial']) ? 1 : 0,
        'is_final'       => !empty($src['is_final']) ? 1 : 0,
        'writes_report'  => !empty($src['writes_report']) ? 1 : 0,
        'allow_admin'    => !empty($src['allow_admin']) ? 1 : 0,
        'allow_mechanic' => !empty($src['allow_mechanic']) ? 1 : 0,
        'allow_fitter'   => !empty($src['allow_fitter']) ? 1 : 0,
    ];
}

// --- Создание статуса ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    csrf_verify();
    $v = status_form_values($_POST);

    if ($v['name'] === '') {
        $errors[] = 'Укажите название статуса.';
    }

    if (!$errors) {
        $pdo = db();
        try {
            $maxOrder = (int) $pdo->query('SELECT COALESCE(MAX(sort_order),0) FROM task_statuses')->fetchColumn();
            $code = 'st_' . bin2hex(random_bytes(4));

            if ($v['is_initial']) {
                $pdo->exec('UPDATE task_statuses SET is_initial = 0');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO task_statuses
                    (code, name, color_bg, color_text, sort_order, is_initial, is_final, writes_report,
                     allow_admin, allow_mechanic, allow_fitter)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $code, $v['name'], $v['color_bg'], $v['color_text'], $maxOrder + 10,
                $v['is_initial'], $v['is_final'], $v['writes_report'],
                $v['allow_admin'], $v['allow_mechanic'], $v['allow_fitter'],
            ]);

            flash('success', 'Статус добавлен.');
            redirect('statuses.php');
        } catch (PDOException $ex) {
            $errors[] = 'Ошибка: ' . $ex->getMessage();
        }
    }
}

// --- Изменение статуса ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    csrf_verify();
    $statusId = (int) ($_POST['status_id'] ?? 0);
    $v = status_form_values($_POST);

    if (!$statusId) $errors[] = 'Статус не найден.';
    if ($v['name'] === '') $errors[] = 'Название не может быть пустым.';

    if (!$errors) {
        $pdo = db();
        try {
            if ($v['is_initial']) {
                $pdo->exec('UPDATE task_statuses SET is_initial = 0');
            }

            $stmt = $pdo->prepare(
                'UPDATE task_statuses SET name=?, color_bg=?, color_text=?, is_initial=?, is_final=?,
                        writes_report=?, allow_admin=?, allow_mechanic=?, allow_fitter=?
                 WHERE id=?'
            );
            $stmt->execute([
                $v['name'], $v['color_bg'], $v['color_text'], $v['is_initial'], $v['is_final'],
                $v['writes_report'], $v['allow_admin'], $v['allow_mechanic'], $v['allow_fitter'],
                $statusId,
            ]);

            flash('success', 'Статус обновлён.');
            redirect('statuses.php');
        } catch (PDOException $ex) {
            $errors[] = 'Ошибка: ' . $ex->getMessage();
        }
    }
}

// --- Порядок колонок ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'move') {
    csrf_verify();
    $statusId = (int) ($_POST['status_id'] ?? 0);
    $direction = $_POST['direction'] === 'up' ? 'up' : 'down';

    $pdo = db();
    $rows = $pdo->query('SELECT id FROM task_statuses ORDER BY sort_order, id')->fetchAll(PDO::FETCH_COLUMN);
    $pos = array_search($statusId, array_map('intval', $rows), true);

    if ($pos !== false) {
        $swapWith = $direction === 'up' ? $pos - 1 : $pos + 1;
        if ($swapWith >= 0 && $swapWith < count($rows)) {
            $tmp = $rows[$pos];
            $rows[$pos] = $rows[$swapWith];
            $rows[$swapWith] = $tmp;

            $upd = $pdo->prepare('UPDATE task_statuses SET sort_order = ? WHERE id = ?');
            foreach ($rows as $index => $rowId) {
                $upd->execute([($index + 1) * 10, $rowId]);
            }
        }
    }
    redirect('statuses.php');
}

// --- Включить / отключить ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_active') {
    csrf_verify();
    $statusId = (int) ($_POST['status_id'] ?? 0);

    $stmt = db()->prepare('SELECT * FROM task_statuses WHERE id = ?');
    $stmt->execute([$statusId]);
    $row = $stmt->fetch();

    if ($row) {
        if ($row['is_active']) {
            $inUse = db()->prepare('SELECT COUNT(*) FROM tasks WHERE status = ?');
            $inUse->execute([$row['code']]);
            $count = (int) $inUse->fetchColumn();

            if ($count > 0) {
                flash('error', 'Нельзя отключить статус: в нём сейчас ' . $count . ' задач(и). Сначала переведите их в другой статус.');
                redirect('statuses.php');
            }
            if ($row['is_initial']) {
                flash('error', 'Нельзя отключить стартовый статус — сначала назначьте стартовым другой.');
                redirect('statuses.php');
            }
        }

        $upd = db()->prepare('UPDATE task_statuses SET is_active = NOT is_active WHERE id = ?');
        $upd->execute([$statusId]);
        flash('success', 'Статус изменён.');
    }
    redirect('statuses.php');
}

$editId = (int) ($_GET['edit'] ?? 0);
$editRow = null;
if ($editId) {
    $stmt = db()->prepare('SELECT * FROM task_statuses WHERE id = ?');
    $stmt->execute([$editId]);
    $editRow = $stmt->fetch() ?: null;
}

$statuses = db()->query(
    'SELECT s.*, (SELECT COUNT(*) FROM tasks t WHERE t.status = s.code) AS tasks_count
       FROM task_statuses s ORDER BY s.sort_order, s.id'
)->fetchAll();

$hasInitial = false;
foreach ($statuses as $s) {
    if ($s['is_initial'] && $s['is_active']) {
        $hasInitial = true;
    }
}

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>Статусы задач</h1>
</div>

<?php foreach ($errors as $err): ?>
    <div class="flash flash-error"><?= e($err) ?></div>
<?php endforeach; ?>

<?php if (!$hasInitial): ?>
    <div class="flash flash-error">Не задан стартовый статус — новые задачи создать не получится. Отметьте один из статусов как стартовый.</div>
<?php endif; ?>

<p class="hint">Порядок в этом списке задаёт порядок колонок на доске задач.
Галочки в колонке «Кто может ставить» определяют, кто вправе перетащить задачу в этот статус.</p>

<div class="table-wrap">
<table class="data-table">
    <thead>
        <tr>
            <th>Порядок</th>
            <th>Статус</th>
            <th>Кто может ставить</th>
            <th>Свойства</th>
            <th>Задач</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($statuses as $i => $s): ?>
        <tr>
            <td class="actions-cell">
                <form method="post" class="inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="move">
                    <input type="hidden" name="status_id" value="<?= (int) $s['id'] ?>">
                    <input type="hidden" name="direction" value="up">
                    <button type="submit" class="btn-link" <?= $i === 0 ? 'disabled' : '' ?>>↑</button>
                </form>
                <form method="post" class="inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="move">
                    <input type="hidden" name="status_id" value="<?= (int) $s['id'] ?>">
                    <input type="hidden" name="direction" value="down">
                    <button type="submit" class="btn-link" <?= $i === count($statuses) - 1 ? 'disabled' : '' ?>>↓</button>
                </form>
            </td>
            <td>
                <span class="badge" style="background:<?= e($s['color_bg']) ?>;color:<?= e($s['color_text']) ?>;"><?= e($s['name']) ?></span>
                <?php if (!$s['is_active']): ?><br><span class="hint">отключён</span><?php endif; ?>
            </td>
            <td>
                <?php
                $who = [];
                if ($s['allow_admin']) $who[] = 'главный механик';
                if ($s['allow_mechanic']) $who[] = 'механик';
                if ($s['allow_fitter']) $who[] = 'слесарь';
                echo $who ? e(implode(', ', $who)) : '<span class="hint">никто</span>';
                ?>
            </td>
            <td>
                <?php
                $props = [];
                if ($s['is_initial']) $props[] = 'стартовый';
                if ($s['is_final']) $props[] = 'завершающий';
                if ($s['writes_report']) $props[] = 'пишет в отчёт';
                echo $props ? e(implode(', ', $props)) : '—';
                ?>
            </td>
            <td><?= (int) $s['tasks_count'] ?></td>
            <td class="actions-cell">
                <a href="statuses.php?edit=<?= (int) $s['id'] ?>">Изменить</a>
                · <form method="post" class="inline-form" onsubmit="return confirm('Изменить статус колонки?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle_active">
                    <input type="hidden" name="status_id" value="<?= (int) $s['id'] ?>">
                    <button type="submit" class="btn-link"><?= $s['is_active'] ? 'Отключить' : 'Включить' ?></button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<h2><?= $editRow ? 'Изменение статуса' : 'Новый статус' ?></h2>
<form method="post" class="form-card">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">
    <?php if ($editRow): ?>
        <input type="hidden" name="status_id" value="<?= (int) $editRow['id'] ?>">
    <?php endif; ?>

    <div class="form-row">
        <label>Название *
            <input type="text" name="name" required maxlength="100" value="<?= e($editRow['name'] ?? '') ?>" placeholder="напр. Ожидает запчасть">
        </label>
        <label>Цвет фона
            <input type="color" name="color_bg" value="<?= e($editRow['color_bg'] ?? '#eef1f5') ?>">
        </label>
        <label>Цвет текста
            <input type="color" name="color_text" value="<?= e($editRow['color_text'] ?? '#172033') ?>">
        </label>
    </div>

    <label>Кто может переводить задачу в этот статус</label>
    <div class="checkbox-grid">
        <label class="checkbox-label">
            <input type="checkbox" name="allow_admin" value="1" <?= !empty($editRow['allow_admin']) || !$editRow ? 'checked' : '' ?>>
            Главный механик
        </label>
        <label class="checkbox-label">
            <input type="checkbox" name="allow_mechanic" value="1" <?= !empty($editRow['allow_mechanic']) ? 'checked' : '' ?>>
            Механик участка
        </label>
        <label class="checkbox-label">
            <input type="checkbox" name="allow_fitter" value="1" <?= !empty($editRow['allow_fitter']) ? 'checked' : '' ?>>
            Слесарь
        </label>
    </div>

    <label>Свойства статуса</label>
    <div class="checkbox-grid">
        <label class="checkbox-label">
            <input type="checkbox" name="is_initial" value="1" <?= !empty($editRow['is_initial']) ? 'checked' : '' ?>>
            Стартовый — в нём создаются новые задачи
        </label>
        <label class="checkbox-label">
            <input type="checkbox" name="is_final" value="1" <?= !empty($editRow['is_final']) ? 'checked' : '' ?>>
            Завершающий — задача закрыта, не считается просроченной
        </label>
        <label class="checkbox-label">
            <input type="checkbox" name="writes_report" value="1" <?= !empty($editRow['writes_report']) ? 'checked' : '' ?>>
            Пишет строку в ежедневный отчёт исполнителя
        </label>
    </div>

    <button type="submit" class="btn btn-primary"><?= $editRow ? 'Сохранить' : 'Добавить статус' ?></button>
    <?php if ($editRow): ?><a href="statuses.php" class="btn-link">Отмена</a><?php endif; ?>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
