<?php
declare(strict_types=1);

// Cargamos la autenticación y la conexión a la base de datos.
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../config/database.php';

// Si alguien entra directo a este archivo sin enviar el formulario, lo redirigimos al login del registro.
// Esto evita que se cree un usuario con una petición GET o un acceso directo a la ruta del script.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php?registro=1');
    exit;
}

// Validamos el token CSRF para impedir solicitudes falsas desde fuera del sitio.
// Un token oculto es una firma temporal que confirma que el formulario fue generado por nuestra aplicación.
csrf_check();

// Leemos y limpiamos los datos que vienen del formulario de registro.
// trim() elimina espacios vacíos; strtolower() normaliza el correo para evitar duplicados por mayúsculas.
$name = trim((string) ($_POST['name'] ?? ''));
$email = strtolower(trim((string) ($_POST['email'] ?? '')));
$password = (string) ($_POST['password'] ?? '');
$role = (string) ($_POST['role'] ?? 'student');
$grade = filter_var($_POST['grade'] ?? null, FILTER_VALIDATE_INT);

// Variables para validar cada campo antes de guardar en la base de datos.
// mb_strlen() sirve para contar caracteres Unicode y evitar nombres demasiado cortos o largos.
$validName = mb_strlen($name) >= 2 && mb_strlen($name) <= 100;
$validEmail = filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
$validPassword = strlen($password) >= 12 && strlen($password) <= 200;
$validRole = in_array($role, ['student', 'parent', 'teacher'], true);
$validGrade = true;

// Si el rol es estudiante, el grado tiene que estar dentro del rango permitido.
// Se valida que el valor sea un entero entre 5° y 11° para que el sistema no reciba grados inválidos.
if ($role === 'student') {
    $validGrade = $grade !== false && $grade >= 5 && $grade <= 11;
}

// Si algo está mal, cortamos la ejecución y devolvemos un error 422.
// 422 significa que la petición llegó bien, pero los datos no cumplen las reglas del servidor.
if (!$validName || !$validEmail || !$validPassword || !$validRole || !$validGrade) {
    http_response_code(422);
    // Alerta de registro: informa que los datos enviados no cumplen las reglas de validación.
    exit('Revisa los datos. Usa una contraseña de 12 caracteres o más.');
}

try {
    // Intentamos guardar al usuario en la base de datos.
    // password_hash() genera un hash seguro con bcrypt/argon2 internamente; no es una contraseña legible.
    $pdo->prepare(
        'INSERT INTO users(name, email, password_hash, role, grade_level)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([
        $name,
        $email,
        password_hash($password, PASSWORD_DEFAULT),
        $role,
        $role === 'student' ? $grade : null,
    ]);
} catch (PDOException $e) {
    // Alerta de registro: redirige al formulario para mostrar el aviso de correo ya registrado.
    // En MySQL, el código 23000 corresponde a una violación de restricción, como UNIQUE o PRIMARY KEY.
    if ($e->getCode() === '23000') {
        header('Location: login.php?registro=1&error=correo');
        exit;
    }

    // Si es otro error, lo dejamos para que se vea y no lo ocultamos.
    // Re-lanzar la excepción ayuda a depurar problemas reales del sistema sin ocultarlos.
    throw $e;
}

// Alerta de registro exitoso: ?creada=1 activa la confirmación en el formulario de acceso.
header('Location: login.php?creada=1');
exit;
