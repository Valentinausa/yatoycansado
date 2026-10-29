<?php
// FORMULARIO HTML DE INICIO DE SESIÓN Y REGISTRO.
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= $register ? 'Crear cuenta' : 'Iniciar sesión' ?> · Prossor AI</title>
    <link rel="stylesheet" href="../../assets/css/app.css">
</head>
<body class="auth-body">
    <main class="auth-card">
        <a class="brand" href="../../index.html">prossor<span>ai</span></a>
        <p class="eyebrow">TU ESPACIO DE APRENDIZAJE</p>
        <h1><?= $register ? 'Crea tu cuenta' : 'Qué bueno verte' ?></h1>
        <p class="muted">Aprende con un plan que avanza contigo.</p>

        <!-- Alertas de inicio de sesión y registro: error de acceso o confirmación del registro. -->
        <?php if ($error): ?>
            <div class="notice error"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if (isset($_GET['creada'])): ?>
            <div class="notice">Cuenta creada. Ya puedes ingresar.</div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="notice error">No pudimos crear la cuenta. Verifica si ese correo ya está registrado.</div>
        <?php endif; ?>

        <form method="post" action="<?= $action ?>" class="stack">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

            <?php if ($register): ?>
                <label>
                    Nombre completo
                    <input name="name" required minlength="2" maxlength="100" autocomplete="name">
                </label>
            <?php endif; ?>

            <label>
                Correo electrónico
                <input type="email" name="email" required autocomplete="email">
            </label>

            <label>
                Contraseña
                <input type="password" name="password" required minlength="<?= $passwordMinLength ?>" maxlength="200" autocomplete="<?= $register ? 'new-password' : 'current-password' ?>">
            </label>

            <?php if ($register): ?>
                <label>
                    Tu perfil
                    <select name="role">
                        <option value="student">Estudiante</option>
                        <option value="parent">Padre o acudiente</option>
                        <option value="teacher">Docente</option>
                    </select>
                </label>

                <label>
                    Grado (estudiantes)
                    <select name="grade">
                        <option value="">Selecciona</option>
                        <?php for ($i = 5; $i <= 11; $i++): ?>
                            <option value="<?= $i ?>"><?= $i ?>°</option>
                        <?php endfor; ?>
                    </select>
                </label>

                <small>Las cuentas nuevas no pueden registrarse como administradoras.</small>
            <?php endif; ?>

            <button class="button primary" type="submit">
                <?= $register ? 'Crear cuenta' : 'Iniciar sesión' ?> <span>→</span>
            </button>
        </form>

        <p class="muted"><a href="forgot-password.php">¿Olvidaste tu contraseña?</a></p>
        <p class="muted">
            <?= $register ? '¿Ya tienes cuenta?' : '¿Primera vez aquí?' ?>
            <a href="<?= $register ? 'login.php' : 'login.php?registro=1' ?>"><?= $register ? 'Inicia sesión' : 'Crea una cuenta' ?></a>
        </p>
    </main>
</body>
</html>
