<?php
/** Ожидается, что $user = current_user() уже определена вызывающей страницей. */
$user = $user ?? current_user();
$currentPage = basename($_SERVER['SCRIPT_NAME']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__ . '/../assets/style.css') ?: time() ?>">
<link rel="stylesheet" href="assets/board.css?v=<?= @filemtime(__DIR__ . '/../assets/board.css') ?: time() ?>">
</head>
<body>
<?php if ($user): ?>
<div class="app">
  <aside class="sidebar">
    <div class="brand"><?= BRAND_PREFIX ?><span><?= BRAND_SUFFIX ?></span></div>
    <nav id="mainNav">
        <?php if ($user['role'] === 'admin'): ?>
            <a href="dashboard.php" class="<?= $currentPage === 'dashboard.php' ? 'active' : '' ?>">Диспетчерская</a>
            <a href="equipment.php" class="<?= in_array($currentPage, ['equipment.php','equipment_view.php','equipment_edit.php']) ? 'active' : '' ?>">Парк техники</a>
            <a href="objects.php" class="<?= $currentPage === 'objects.php' ? 'active' : '' ?>">Объекты</a>
            <a href="statuses.php" class="<?= $currentPage === 'statuses.php' ? 'active' : '' ?>">Статусы</a>
            <a href="tasks.php" class="<?= in_array($currentPage, ['tasks.php','task_view.php','task_create.php','task_edit.php']) ? 'active' : '' ?>">Задачи</a>
            <a href="timesheet.php" class="<?= $currentPage === 'timesheet.php' ? 'active' : '' ?>">Табель</a>
            <a href="reports.php" class="<?= $currentPage === 'reports.php' ? 'active' : '' ?>">Отчёты</a>
            <a href="users.php" class="<?= in_array($currentPage, ['users.php','user_edit.php']) ? 'active' : '' ?>">Сотрудники</a>
        <?php elseif ($user['role'] === 'manager'): ?>
            <a href="equipment.php" class="<?= in_array($currentPage, ['equipment.php','equipment_view.php']) ? 'active' : '' ?>">Парк техники</a>
        <?php else: ?>
            <a href="tasks.php" class="<?= in_array($currentPage, ['tasks.php','task_view.php']) ? 'active' : '' ?>">Мои задачи</a>
            <a href="report_today.php" class="<?= $currentPage === 'report_today.php' ? 'active' : '' ?>">Мой отчёт</a>
            <a href="equipment.php" class="<?= in_array($currentPage, ['equipment.php','equipment_view.php']) ? 'active' : '' ?>">Парк техники</a>
        <?php endif; ?>
    </nav>
    <div class="userbox">
        <span class="userbox-info"><b><?= e($user['full_name']) ?></b><br>
        <?= e(role_label($user['role'])) ?></span>
        <a href="logout.php" class="userbox-logout">Выйти</a>
    </div>
  </aside>
  <main class="main">
<?php endif; ?>
<div class="container">
<?php foreach (get_flashes() as $f): ?>
    <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
<?php endforeach; ?>
