<?php
declare(strict_types=1);

// Helpers de autenticación y seguridad global.
// Aquí se inicia la sesión, se bloquean páginas según el rol y se valida el CSRF.
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params(['httponly' => true, 'secure' => $secure, 'samesite' => 'Lax', 'path' => '/']);
    session_start();
}
function app_base_url(): string {
    $configured = getenv('PROSSOR_BASE_URL');
    if ($configured !== false && $configured !== '') {
        $base = rtrim($configured, '/');
        if (!preg_match('#^https?://(?:[A-Za-z0-9.-]+|\[[A-Fa-f0-9:]+\])(?::[0-9]+)?(?:/[A-Za-z0-9._~!$&\'()*+,;=:@%/-]*)?$#', $base)) {
            throw new RuntimeException('PROSSOR_BASE_URL no es una URL válida.');
        }
        return $base;
    }

    $host = (string) ($_SERVER['SERVER_NAME'] ?? '');
    if (!preg_match('/^(?:[A-Za-z0-9.-]+|\[[A-Fa-f0-9:]+\])$/', $host)) {
        throw new RuntimeException('No se pudo determinar el servidor para construir la URL de la aplicación.');
    }

    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $port = (int) ($_SERVER['SERVER_PORT'] ?? ($https ? 443 : 80));
    $portSuffix = ($https && $port !== 443) || (!$https && $port !== 80) ? ':' . $port : '';
    $basePath = dirname(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
    $basePath = $basePath === DIRECTORY_SEPARATOR || $basePath === '.' ? '' : '/' . trim(str_replace('\\', '/', $basePath), '/');

    return $scheme . '://' . $host . $portSuffix . $basePath;
}
function require_login(): void {
    if (empty($_SESSION['user']) || !is_array($_SESSION['user'])) {
        header('Location: ' . app_base_url() . '/Registro/Inicio/login.php');
        exit;
    }

    // Revalida estado y rol en cada petición protegida para que una suspensión
    // o cambio de privilegios no deje una sesión antigua con acceso vigente.
    global $pdo;
    require_once __DIR__ . '/../config/database.php';
    $userId = filter_var($_SESSION['user']['id'] ?? null, FILTER_VALIDATE_INT);

    if ($userId === false || $userId < 1) {
        $_SESSION = [];
        session_destroy();
        header('Location: ' . app_base_url() . '/Registro/Inicio/login.php');
        exit;
    }

    $accountQuery = $pdo->prepare('SELECT role,is_active FROM users WHERE id=?');
    $accountQuery->execute([$userId]);
    $account = $accountQuery->fetch();

    if (!$account || !(bool) $account['is_active']) {
        $_SESSION = [];
        session_destroy();
        header('Location: ' . app_base_url() . '/Registro/Inicio/login.php');
        exit;
    }

    $_SESSION['user']['role'] = $account['role'];
}
function require_role(array $roles): void { require_login(); if (!in_array($_SESSION['user']['role'], $roles, true)) { http_response_code(403); exit('No tienes permiso para acceder a esta seccion.'); } }
function h(?string $value): string { return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function csrf_check(): void { if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit("La sesion del formulario vencio. Recarga e intenta de nuevo."); } }
function audit_event(PDO $pdo, ?int $userId, string $event, array $details = []): void {
    $stmt = $pdo->prepare('INSERT INTO audit_logs(user_id,event,details,ip_address) VALUES(?,?,?,?)');
    $stmt->execute([$userId, $event, $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
}
