<?php
declare(strict_types=1);

// Permite cambiar la contraseña con el token recibido por correo.
// El enlace solo funciona si aún no fue usado y no venció; esto evita que un link viejo siga funcionando.
require __DIR__.'/../../includes/auth.php';
require __DIR__.'/../../config/database.php';
csrf_check();

$done = false;
$error = '';
$token = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // El token llega como una cadena hexadecimal de 64 caracteres, equivalente al hash SHA-256 del enlace original.
    $token = strtolower(trim((string) ($_POST['token'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    // Se valida primero el formato del token y luego la longitud de la contraseña para evitar cambios con datos inválidos.
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        // Alerta de restablecimiento: el enlace recibido no es válido o ya venció.
        $error = 'El enlace no es válido o venció. Solicita uno nuevo.';
    } elseif (strlen($password) < 12 || strlen($password) > 200) {
        // Alerta de restablecimiento: la contraseña no cumple el límite requerido.
        $error = 'La contraseña debe tener entre 12 y 200 caracteres.';
    } elseif (!hash_equals($password, $confirm)) {
        // Alerta de restablecimiento: las contraseñas ingresadas no coinciden.
        $error = 'Las contraseñas no coinciden.';
    } else {
        // La transacción asegura que el token se valide y se consuma de forma consistente en una sola operación.
        $pdo->beginTransaction();
        try {
            // FOR UPDATE bloquea la fila del token para evitar dos cambios simultáneos con el mismo enlace.
            $q = $pdo->prepare('SELECT id, user_id FROM password_reset_tokens WHERE token_hash=? AND used_at IS NULL AND expires_at>NOW() FOR UPDATE');
            $q->execute([hash('sha256', $token)]);
            $reset = $q->fetch();

            if (!$reset) {
                $pdo->rollBack();
                $error = 'El enlace no es válido o venció. Solicita uno nuevo.';
            } else {
                // password_hash() genera un hash seguro y nunca guarda la contraseña en texto plano en la base.
                $updated = $pdo->prepare('UPDATE users SET password_hash=? WHERE id=? AND is_active=1');
                $updated->execute([password_hash($password, PASSWORD_DEFAULT), $reset['user_id']]);

                if (!$updated->rowCount()) {
                    $pdo->rollBack();
                    $error = 'El enlace no es válido o venció. Solicita uno nuevo.';
                } else {
                    // Cuando la contraseña cambia, se marca el token como usado para impedir reutilizaciones.
                    $pdo->prepare('UPDATE password_reset_tokens SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')->execute([$reset['user_id']]);
                    audit_event($pdo, (int) $reset['user_id'], 'auth.password_reset_completed');
                    $pdo->commit();
                    $done = true;
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
require __DIR__ . '/Formularios/formulario-restablecer-contrasena.php';