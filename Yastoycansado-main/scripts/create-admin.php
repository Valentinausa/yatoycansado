<?php
declare(strict_types=1);

// Script de consola para crear un usuario administrador.
// Se ejecuta desde CLI con un nombre, correo y contraseña segura.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../config/database.php';
if($argc<3){fwrite(STDERR,"Uso: php scripts/create-admin.php \"Nombre\" correo@institucion.edu\n");exit(1);}
$name=trim($argv[1]);$email=strtolower(trim($argv[2]));$password=(string)(getenv('PROSSOR_ADMIN_PASSWORD')?:'');
if(mb_strlen($name)<2||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($password)<12){fwrite(STDERR,"Nombre/correo inválidos. La contraseña debe tener al menos 12 caracteres.\n");exit(1);}
$pdo->prepare("INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,'admin')")->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT)]);
fwrite(STDOUT,"Administrador creado. Guarda la contraseña fuera del proyecto.\n");
