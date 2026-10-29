<?php
// FORMULARIO HTML PARA SOLICITAR LA RECUPERACIÓN DE CONTRASEÑA.
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Recuperar acceso · Prossor AI</title>
    <link rel="stylesheet" href="../../assets/css/app.css">
</head>
<body class="auth-body">
    <main class="auth-card">
        <a class="brand" href="../../index.html">prossor<span>ai</span></a>
        <p class="eyebrow">RECUPERAR ACCESO</p>
        <h1>Restablece tu contraseña</h1>
        <p class="muted">Escribe el correo de tu cuenta. Si puede recibir mensajes, enviaremos un enlace que vence en 30 minutos.</p>

        <!-- Alerta de recuperación: mensaje genérico para no revelar si el correo está registrado. -->
        <?php if ($sent): ?>
            <div class="notice">Si la cuenta existe y se pudo procesar la solicitud, recibirás instrucciones. Revisa tu correo o consulta el buzón local de desarrollo.</div>
        <?php else: ?>
            <form method="post" class="stack">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <label>
                    Correo electrónico
                    <input type="email" name="email" maxlength="190" autocomplete="email" required>
                </label>
                <button class="button primary">Enviar instrucciones</button>
            </form>
        <?php endif; ?>

        <p class="muted"><a href="login.php">Volver al inicio de sesión</a></p>
    </main>
</body>
</html>
