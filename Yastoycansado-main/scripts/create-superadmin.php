<?php
declare(strict_types=1);

// Provisiona el rol de máxima confianza solo desde consola, nunca desde un formulario web.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/database.php';

if ($argc < 3) {
    fwrite(STDERR, "Uso: php scripts/create-superadmin.php \"Nombre\" correo@prossor.co\n");
    exit(1);
}

$name = trim($argv[1]);
$email = strtolower(trim($argv[2]));
$password = (string) (getenv('PROSSOR_SUPERADMIN_PASSWORD') ?: '');

if (
    mb_strlen($name) < 2
    || mb_strlen($name) > 100
    || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || strlen($password) < 12
) {
    fwrite(
        STDERR,
        "Nombre/correo inválidos. Define PROSSOR_SUPERADMIN_PASSWORD con mínimo 12 caracteres.\n"
    );
    exit(1);
}

$create = $pdo->prepare(
    "INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,'superadmin')"
);
$create->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);

fwrite(STDOUT, "Superadministrador creado. Guarda la contraseña fuera del proyecto.\n");
