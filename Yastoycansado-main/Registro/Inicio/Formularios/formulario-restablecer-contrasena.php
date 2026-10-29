<?php
// FORMULARIO HTML PARA DEFINIR UNA NUEVA CONTRASEÑA.
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Nueva contraseña · Prossor AI</title>
    <link rel="stylesheet" href="../../assets/css/app.css">
</head>
<body class="auth-body">
    <main class="auth-card">
        <a class="brand" href="../../index.html">prossor<span>ai</span></a>
        <p class="eyebrow">SEGURIDAD DE CUENTA</p>
        <h1><?= $done ? 'Contraseña actualizada' : 'Elige una contraseña nueva' ?></h1>

        <?php if ($done): ?>
            <!-- Alerta de éxito: confirma que la contraseña fue actualizada. -->
            <div class="notice">Ya puedes iniciar sesión con tu nueva contraseña. El enlace de recuperación quedó invalidado.</div>
            <a class="button primary" href="login.php">Ir a iniciar sesión</a>
        <?php else: ?>
            <p class="muted">El enlace es de un solo uso y vence en 30 minutos.</p>
            <!-- Alerta de error: muestra problemas de validación o del enlace de recuperación. -->
            <?php if ($error): ?>
                <div class="notice error"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="post" class="stack" id="reset-form">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="token" id="reset-token" value="<?= h($token) ?>">
                <label>
                    Nueva contraseña (12 caracteres mínimo)
                    <input type="password" name="password" minlength="12" maxlength="200" autocomplete="new-password" required>
                </label>
                <label>
                    Confirmar contraseña
                    <input type="password" name="confirm_password" minlength="12" maxlength="200" autocomplete="new-password" required>
                </label>
                <button class="button primary">Guardar contraseña</button>
            </form>

            <p class="muted" id="token-help" hidden>Abre el enlace completo del correo para cargar el token de recuperación.</p>
            <script>
                // JavaScript: obtiene y valida el token del enlace de recuperacion.
                (() => {
                    const token = location.hash.slice(1);
                    const field = document.getElementById('reset-token');
                    if (/^[a-f0-9]{64}$/i.test(token)) {
                        field.value = token;
                        history.replaceState(null, '', location.pathname);
                    } else if (!/^[a-f0-9]{64}$/i.test(field.value)) {
                        document.getElementById('reset-form').hidden = true;
                        document.getElementById('token-help').hidden = false;
                    }
                })();
            </script>
        <?php endif; ?>

        <p class="muted"><a href="login.php">Volver al inicio de sesión</a></p>
    </main>
</body>
</html>
