<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_admin();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$equipmentRow = [
    'inventory_number' => '', 'name' => '', 'type' => '', 'reg_number' => '', 'pts' => '', 'sts' => '',
    'location' => '', 'object_id' => null, 'status' => 'working', 'engine_hours' => '', 'notes' => '',
];

$allObjects = objects_list(true);

if ($id) {
    $stmt = db()->prepare('SELECT * FROM equipment WHERE id = ?');
    $stmt->execute([$id]);
    $found = $stmt->fetch();
    if (!$found) {
        flash('error', 'Техника не найдена.');
        redirect('equipment.php');
    }
    $equipmentRow = $found;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $equipmentRow['inventory_number'] = trim($_POST['inventory_number'] ?? '');
    $equipmentRow['name'] = trim($_POST['name'] ?? '');
    $equipmentRow['type'] = trim($_POST['type'] ?? '');
    $equipmentRow['reg_number'] = trim($_POST['reg_number'] ?? '');
    $equipmentRow['pts'] = trim($_POST['pts'] ?? '');
    $equipmentRow['sts'] = trim($_POST['sts'] ?? '');
    $equipmentRow['location'] = trim($_POST['location'] ?? '');
    $equipmentRow['object_id'] = ($_POST['object_id'] ?? '') !== '' ? (int) $_POST['object_id'] : null;
    $equipmentRow['status'] = $_POST['status'] ?? 'working';
    $equipmentRow['engine_hours'] = trim($_POST['engine_hours'] ?? '');
    $equipmentRow['notes'] = trim($_POST['notes'] ?? '');

    if ($equipmentRow['inventory_number'] === '') {
        $errors[] = 'Укажите инвентарный номер.';
    }
    if ($equipmentRow['name'] === '') {
        $errors[] = 'Укажите наименование/модель.';
    }
    if (!in_array($equipmentRow['status'], ['working', 'repair', 'idle'], true)) {
        $errors[] = 'Некорректный статус.';
    }

    $engineHours = $equipmentRow['engine_hours'] === '' ? null : (int) $equipmentRow['engine_hours'];

    if (!$errors) {
        try {
            if ($id) {
                $stmt = db()->prepare(
                    'UPDATE equipment SET inventory_number=?, name=?, type=?, reg_number=?, pts=?, sts=?, location=?, object_id=?, status=?, engine_hours=?, notes=? WHERE id=?'
                );
                $stmt->execute([
                    $equipmentRow['inventory_number'], $equipmentRow['name'], $equipmentRow['type'] ?: null,
                    $equipmentRow['reg_number'] ?: null, $equipmentRow['pts'] ?: null, $equipmentRow['sts'] ?: null,
                    $equipmentRow['location'] ?: null, $equipmentRow['object_id'], $equipmentRow['status'],
                    $engineHours, $equipmentRow['notes'] ?: null, $id,
                ]);
                flash('success', 'Данные техники обновлены.');
            } else {
                $stmt = db()->prepare(
                    'INSERT INTO equipment (inventory_number, name, type, reg_number, pts, sts, location, object_id, status, engine_hours, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                );
                $stmt->execute([
                    $equipmentRow['inventory_number'], $equipmentRow['name'], $equipmentRow['type'] ?: null,
                    $equipmentRow['reg_number'] ?: null, $equipmentRow['pts'] ?: null, $equipmentRow['sts'] ?: null,
                    $equipmentRow['location'] ?: null, $equipmentRow['object_id'], $equipmentRow['status'],
                    $engineHours, $equipmentRow['notes'] ?: null,
                ]);
                flash('success', 'Техника добавлена в парк.');
            }
            redirect('equipment.php');
        } catch (PDOException $ex) {
            if ($ex->getCode() === '23000') {
                $errors[] = 'Техника с таким инвентарным номером уже существует.';
            } else {
                $errors[] = 'Ошибка сохранения: ' . $ex->getMessage();
            }
        }
    }
}

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1><?= $id ? 'Редактирование техники' : 'Новая техника' ?></h1>
    <a href="equipment.php" class="btn-link">← К списку</a>
</div>

<?php foreach ($errors as $err): ?>
    <div class="flash flash-error"><?= e($err) ?></div>
<?php endforeach; ?>

<form method="post" class="form-card">
    <?= csrf_field() ?>
    <div class="form-row">
        <label>Инвентарный номер *
            <input type="text" name="inventory_number" required value="<?= e($equipmentRow['inventory_number']) ?>">
        </label>
        <label>Наименование / модель *
            <input type="text" name="name" required value="<?= e($equipmentRow['name']) ?>">
        </label>
    </div>
    <div class="form-row">
        <label>Тип техники
            <input type="text" name="type" placeholder="напр. трактор, экскаватор" value="<?= e($equipmentRow['type']) ?>">
        </label>
        <label>Гос. номер
            <input type="text" name="reg_number" value="<?= e($equipmentRow['reg_number']) ?>">
        </label>
    </div>
    <div class="form-row">
        <label>ПТС
            <input type="text" name="pts" value="<?= e($equipmentRow['pts'] ?? '') ?>">
        </label>
        <label>СТС
            <input type="text" name="sts" value="<?= e($equipmentRow['sts'] ?? '') ?>">
        </label>
    </div>
    <div class="form-row">
        <label>Объект
            <select name="object_id">
                <option value="">— не закреплена —</option>
                <?php foreach ($allObjects as $o): ?>
                    <option value="<?= (int) $o['id'] ?>" <?= (int) ($equipmentRow['object_id'] ?? 0) === (int) $o['id'] ? 'selected' : '' ?>>
                        <?= e($o['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Локация <span class="hint">(текстом, из первичной выгрузки)</span>
            <input type="text" name="location" value="<?= e($equipmentRow['location']) ?>">
        </label>
        <label>Моточасы
            <input type="number" min="0" name="engine_hours" value="<?= e((string) $equipmentRow['engine_hours']) ?>">
        </label>
    </div>
    <div class="form-row">
        <label>Статус
            <select name="status">
                <option value="working" <?= $equipmentRow['status'] === 'working' ? 'selected' : '' ?>>В работе</option>
                <option value="repair" <?= $equipmentRow['status'] === 'repair' ? 'selected' : '' ?>>На ремонте</option>
                <option value="idle" <?= $equipmentRow['status'] === 'idle' ? 'selected' : '' ?>>Простой</option>
            </select>
        </label>
    </div>
    <label>Примечания
        <textarea name="notes" rows="3"><?= e($equipmentRow['notes']) ?></textarea>
    </label>
    <button type="submit" class="btn btn-primary"><?= $id ? 'Сохранить' : 'Добавить' ?></button>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
