<?php
declare(strict_types=1);

// Configuración central de la base de datos.
// Acá se conecta PDO a MySQL y se fija la zona horaria del sistema.
$host = getenv('PROSSOR_DB_HOST') ?: '127.0.0.1';
$database = getenv('PROSSOR_DB_NAME') ?: 'prossor_colegio';
$username = getenv('PROSSOR_DB_USER') ?: 'root';
$password = getenv('PROSSOR_DB_PASSWORD') ?: '';
// La app muestra y calcula fechas de una institución en Colombia. Mantén
// PHP y NOW() de MySQL en la misma zona para el temporizador de simulacros.
date_default_timezone_set('America/Bogota');
try {
    $pdo = new PDO("mysql:host={$host};dbname={$database};charset=utf8mb4", $username, $password, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec("SET time_zone = '-05:00'");
} catch (PDOException $e) { http_response_code(500); exit('No se pudo conectar con MySQL. Revisa database/schema.sql y config/database.php.'); }
