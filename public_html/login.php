<?php
require __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) {
    redirect(is_admin() ? 'dashboard.php' : (is_manager() ? 'equipment.php' : 'tasks.php'));
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Введите логин и пароль.';
    } elseif (attempt_login($username, $password)) {
        redirect(is_admin() ? 'dashboard.php' : (is_manager() ? 'equipment.php' : 'tasks.php'));
    } else {
        $error = 'Неверный логин или пароль, либо учётная запись отключена.';
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e(APP_NAME) ?> — вход</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-body">
<div class="login-box">
    <h1>⚙ <?= e(APP_NAME) ?></h1>
    <?php if ($error): ?>
        <div class="flash flash-error"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <label>Логин
            <input type="text" name="username" required autofocus value="<?= e($_POST['username'] ?? '') ?>">
        </label>
        <label>Пароль
            <input type="password" name="password" required>
        </label>
        <button type="submit" class="btn btn-primary btn-block">Войти</button>
    </form>
</div>
</body>
</html>
