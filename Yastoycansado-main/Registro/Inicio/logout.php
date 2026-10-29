<?php
declare(strict_types=1);

// Cierra la sesión del usuario actual y lo devuelve a la pantalla inicial.
// hash_equals() compara de forma segura el token CSRF para evitar cierres falsos sin origen válido.
require __DIR__.'/../../includes/auth.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) { http_response_code(405); exit('Solicitud inválida.'); }

// $_SESSION = [] limpia los datos que habían sido guardados del usuario actual.
$_SESSION=[];

// Si la sesión usa cookies, se borra también la cookie del navegador para cerrar la sesión del lado cliente.
if (ini_get('session.use_cookies')) { $params=session_get_cookie_params(); setcookie(session_name(),'', ['expires'=>time()-42000,'path'=>$params['path'],'domain'=>$params['domain'],'secure'=>$params['secure'],'httponly'=>$params['httponly'],'samesite'=>$params['samesite']??'Lax']); }

// session_destroy() invalida la sesión del servidor y redirige al inicio público.
session_destroy(); header('Location: ../../index.html'); exit;
