<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && is_admin()) {
    csrf_verify();
    $deleteId = (int) ($_POST['equipment_id'] ?? 0);
    if ($deleteId) {
        $stmt = db()->prepare('UPDATE equipment SET is_active = 0 WHERE id = ?');
        $stmt->execute([$deleteId]);
        flash('success', 'Техника удалена из парка.');
    }
    redirect('equipment.php');
}

$search = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? '';

$sql = 'SELECT * FROM equipment WHERE is_active = 1';
$params = [];

if ($search !== '') {
    $sql .= ' AND (inventory_number LIKE ? OR name LIKE ? OR reg_number LIKE ? OR location LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}

if (in_array($statusFilter, ['working', 'repair', 'idle'], true)) {
    $sql .= ' AND status = ?';
    $params[] = $statusFilter;
}

$sql .= ' ORDER BY name ASC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$equipmentList = $stmt->fetchAll();

// Количество активных (незакрытых) задач по каждой единице техники — для быстрого взгляда
$activeCounts = [];
$countStmt = db()->query("SELECT equipment_id, COUNT(*) AS cnt FROM tasks WHERE " . sql_not_final() . " GROUP BY equipment_id");
foreach ($countStmt->fetchAll() as $row) {
    $activeCounts[(int) $row['equipment_id']] = (int) $row['cnt'];
}

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>Техника</h1>
    <?php if (is_admin()): ?>
        <a href="equipment_edit.php" class="btn btn-primary">+ Добавить технику</a>
    <?php endif; ?>
</div>

<form method="get" class="filter-bar">
    <input type="text" name="q" placeholder="Поиск: инв. номер, модель, гос.номер, объект" value="<?= e($search) ?>">
    <select name="status">
        <option value="">Все статусы</option>
        <option value="working" <?= $statusFilter === 'working' ? 'selected' : '' ?>>В работе</option>
        <option value="repair" <?= $statusFilter === 'repair' ? 'selected' : '' ?>>На ремонте</option>
        <option value="idle" <?= $statusFilter === 'idle' ? 'selected' : '' ?>>Простой</option>
    </select>
    <button type="submit" class="btn">Найти</button>
    <?php if ($search !== '' || $statusFilter !== ''): ?>
        <a href="equipment.php" class="btn-link">Сбросить</a>
    <?php endif; ?>
</form>

<div class="table-wrap">
<table class="data-table">
    <thead>
        <tr>
            <th>Инв. №</th>
            <th>Наименование</th>
            <th>Тип</th>
            <th>Гос. номер</th>
            <th>Локация</th>
            <th>Статус</th>
            <th>Активных задач</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php if (!$equipmentList): ?>
        <tr><td colspan="8" class="empty-cell">Техника не найдена.</td></tr>
    <?php endif; ?>
    <?php foreach ($equipmentList as $eq): ?>
        <tr>
            <td><?= e($eq['inventory_number']) ?></td>
            <td><a href="equipment_view.php?id=<?= (int) $eq['id'] ?>"><?= e($eq['name']) ?></a></td>
            <td><?= e($eq['type'] ?? '—') ?></td>
            <td><?= e($eq['reg_number'] ?? '—') ?></td>
            <td><?= e($eq['location'] ?? '—') ?></td>
            <td><span class="badge <?= equipment_status_class($eq['status']) ?>"><?= equipment_status_label($eq['status']) ?></span></td>
            <td><?= $activeCounts[(int) $eq['id']] ?? 0 ?></td>
            <td class="actions-cell">
                <a href="equipment_view.php?id=<?= (int) $eq['id'] ?>">Открыть</a>
                <?php if (is_admin()): ?>
                    · <a href="equipment_edit.php?id=<?= (int) $eq['id'] ?>">Изменить</a>
                    · <form method="post" class="inline-form" onsubmit="return confirm('Удалить эту технику из парка? Действие можно отменить только через администратора БД.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="equipment_id" value="<?= (int) $eq['id'] ?>">
                        <button type="submit" class="btn-link btn-link-danger">Удалить</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
