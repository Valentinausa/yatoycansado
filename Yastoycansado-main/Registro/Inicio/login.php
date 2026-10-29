<?php
declare(strict_types=1);

// Cargamos las funciones de sesión/autenticación y la conexión a la base de datos.
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../config/database.php';

// Si ya hay un usuario logueado, lo mandamos al dashboard para que no vuelva al login.
if (!empty($_SESSION['user'])) {
    header('Location: ../../panel/dashboard.php');
    exit;
}

// Variable para mostrar mensajes de error o éxito.
$error = '';

// Si llega ?registro=1, mostramos el formulario de registro en vez del de login.
// Esto permite reutilizar una misma vista para ambos flujos y cambiar solo el comportamiento.
$register = isset($_GET['registro']);

// Si el usuario envió el formulario por POST, validamos y procesamos el login.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF: token oculto que evita que un sitio externo envíe formularios falsos.
    // Si el token no coincide, la petición se rechaza antes de tocar la base de datos.
    csrf_check();

    // Limpiamos los datos del formulario para evitar basura o espacios extra.
    // trim() quita espacios, strtolower() normaliza el correo y nos ayuda a comparar emails sin distinguir mayúsculas/minúsculas.
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    // SHA-256 sirve para crear un identificador irreversible del correo y de la IP.
    // No guardamos los valores reales, solo su hash, para controlar bloqueos sin exponer datos sensibles.
    $emailHash = hash('sha256', $email);
    $ipHash = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

    // Consultamos si ya hubo varios fallos recientes para este usuario/IP.
    // La lógica de throttling evita fuerza bruta: si el usuario intenta demasiadas veces, se bloquea temporalmente.
    $throttle = $pdo->prepare(
        'SELECT failures, window_started_at, blocked_until
         FROM login_throttles
         WHERE email_hash = ? AND ip_hash = ?'
    );
    $throttle->execute([$emailHash, $ipHash]);
    $limit = $throttle->fetch();

    // Si hubo 5 fallos en 15 minutos y aún está bloqueado, no permite seguir intentando.
    // El cálculo usa timestamps para saber si el periodo de bloqueo sigue vigente.
    $blocked = $limit
        && (int) $limit['failures'] >= 5
        && strtotime((string) $limit['window_started_at']) > time() - 900
        && !empty($limit['blocked_until'])
        && strtotime((string) $limit['blocked_until']) > time();

    // Intentamos buscar al usuario activo con ese correo.
    // LIMIT 1 ayuda a optimizar la consulta porque solo necesitamos un registro válido.
    $user = null;
    if (!$blocked) {
        $query = $pdo->prepare(
            'SELECT id, name, email, password_hash, role, grade_level
             FROM users
             WHERE email = ? AND is_active = 1
             LIMIT 1'
        );
        $query->execute([$email]);
        $user = $query->fetch();
    }

    // Si el usuario existe y la contraseña coincide, se crea la sesión.
    // password_verify() compara la contraseña ingresada con el hash almacenado, sin devolver el hash original.
    if ($user && password_verify($password, (string) $user['password_hash'])) {
        // Renovamos la sesión para evitar fijación de sesión.
        // Esto genera un nuevo identificador para que un atacante no pueda reutilizar una sesión capturada.
        session_regenerate_id(true);

        // Quitamos el hash de la contraseña antes de guardar el usuario en sesión.
        // Eso evita que la contraseña quede expuesta en memoria o en la sesión del usuario.
        unset($user['password_hash']);
        $_SESSION['user'] = $user;

        // Actualizamos la fecha del último login y borramos los registros de bloqueo.
        // El sistema deja limpia la evidencia de fallos cuando el acceso fue exitoso.
        $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
        $pdo->prepare('DELETE FROM login_throttles WHERE email_hash = ? AND ip_hash = ?')->execute([$emailHash, $ipHash]);
        audit_event($pdo, (int) $user['id'], 'auth.login');

        // Si todo está bien, mandamos al dashboard.
        header('Location: ../../panel/dashboard.php');
        exit;
    }

    // Si la contraseña no coincide, registramos el intento fallido.
    // Este bloque lleva un contador por correo e IP para limitar ataques de fuerza bruta.
    if (!$blocked) {
        // window_started_at representa el momento en que comenzó la ventana de 15 minutos.
        $within = $limit && strtotime((string) $limit['window_started_at']) > time() - 900;
        $failures = $within ? (int) $limit['failures'] + 1 : 1;
        $blockedUntil = $failures >= 5 ? date('Y-m-d H:i:s', time() + 900) : null;
        $window = $within ? $limit['window_started_at'] : date('Y-m-d H:i:s');

        // Guardamos el contador de errores para evitar brute force.
        // ON DUPLICATE KEY UPDATE hace que la misma combinación correo/IP se actualice en lugar de crear un nuevo registro duplicado.
        $save = $pdo->prepare(
            'INSERT INTO login_throttles(email_hash, ip_hash, failures, window_started_at, blocked_until)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 failures = VALUES(failures),
                 window_started_at = VALUES(window_started_at),
                 blocked_until = VALUES(blocked_until)'
        );
        $save->execute([$emailHash, $ipHash, $failures, $window, $blockedUntil]);
    }

    // Mensaje que se muestra al usuario según el tipo de error.
    $error = $blocked
        ? 'Demasiados intentos. Espera unos minutos e inténtalo de nuevo.'
        : 'Correo o contraseña incorrectos.';
}

// La acción del formulario cambia según si es login o registro.
// Cuando $register es true, el mismo archivo renderiza el formulario de alta; de lo contrario, el formulario se envía a sí mismo.
$action = $register ? 'registro.php' : 'login.php';
// passwordMinLength se usa solo en el registro para exigir más seguridad en las contraseñas.
$passwordMinLength = $register ? 12 : 1;
require __DIR__ . '/Formularios/formulario-acceso-registro.php';
