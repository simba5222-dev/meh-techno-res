<?php
/**
 * Авторизация и работа с текущим пользователем.
 * Подключать после db.php и functions.php.
 */

/** Найти пользователя по логину */
function find_user_by_username(string $username): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    return $user ?: null;
}

/** Попытка входа. Возвращает true при успехе. */
function attempt_login(string $username, string $password): bool
{
    $user = find_user_by_username($username);

    if (!$user || !$user['is_active']) {
        return false;
    }

    // Учётки без доступа на сайт (слесари) не пускаем, даже если у них
    // почему-то оказался заполнен пароль.
    if (isset($user['can_login']) && !$user['can_login']) {
        return false;
    }

    if (($user['password_hash'] ?? '') === '' || $user['password_hash'] === null) {
        return false;
    }

    if (!password_verify($password, $user['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    reset_current_user_cache();
    return true;
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    reset_current_user_cache();
}

/**
 * Сбросить кэш current_user() в рамках текущего запроса.
 * Нужно вызывать сразу после любого изменения $_SESSION['user_id']
 * (вход/выход), иначе более ранний вызов current_user() в том же
 * запросе (например, проверка "уже авторизован?" в начале login.php)
 * оставит закэшированным старое значение.
 */
function reset_current_user_cache(): void
{
    current_user(true);
}

/** Текущий авторизованный пользователь (или null) */
function current_user(bool $forceReset = false): ?array
{
    static $computed = false;
    static $cached = null;

    if ($forceReset) {
        $computed = false;
        $cached = null;
        return null;
    }

    if ($computed) {
        return $cached;
    }

    $computed = true;

    if (empty($_SESSION['user_id'])) {
        $cached = null;
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    $cached = $user ?: null;
    return $cached;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_admin(): bool
{
    $user = current_user();
    return $user !== null && $user['role'] === 'admin';
}

function is_manager(): bool
{
    $user = current_user();
    return $user !== null && $user['role'] === 'manager';
}

function is_mechanic(): bool
{
    $user = current_user();
    return $user !== null && $user['role'] === 'mechanic';
}

function is_fitter(): bool
{
    $user = current_user();
    return $user !== null && $user['role'] === 'fitter';
}

/** Требовать авторизацию; иначе редирект на страницу входа */
function require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect('login.php');
    }
    return $user;
}

/** Требовать роль главного механика; иначе 403 */
function require_admin(): array
{
    $user = require_login();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        die('Доступ запрещён. Раздел доступен только главному механику.');
    }
    return $user;
}
