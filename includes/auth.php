<?php
if (session_status() === PHP_SESSION_NONE) {
    $isSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $cookieParams = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookieParams['path'] ?? '/',
        'domain' => $cookieParams['domain'] ?? '',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

const SESSION_TIMEOUT_SECONDS = 1800; // 30 minutos de inactividad

function isSessionExpired(): bool {
    if (!isset($_SESSION['last_activity'])) {
        return false;
    }

    return (time() - (int) $_SESSION['last_activity']) > SESSION_TIMEOUT_SECONDS;
}

function resetSession(): void {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? '', (bool) ($params['secure'] ?? false), (bool) ($params['httponly'] ?? false));
    }

    session_destroy();
}

function checkAuth() {
    if (isSessionExpired()) {
        resetSession();
        header('Location: ../public/login.php?timeout=1');
        exit;
    }

    if (!isset($_SESSION['usuario'])) {
        header('Location: ../public/login.php');
        exit;
    }

    $_SESSION['last_activity'] = time();
}

function checkAdmin() {
    checkAuth();
    if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') {
        header('Location: ../public/index.php?error=permiso');
        exit;
    }
}
?>
