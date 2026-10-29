<?php
declare(strict_types=1);

// Formulario para pedir recuperación de contraseña.
// El sistema genera un token y envía un enlace válido por 30 minutos para evitar que cualquiera reconfigure la cuenta.
require __DIR__.'/../../includes/auth.php'; require __DIR__.'/../../config/database.php'; require __DIR__.'/../../includes/mailer.php'; csrf_check();
$sent=false;
if($_SERVER['REQUEST_METHOD']==='POST'){
    // Normalizamos el correo para evitar duplicados por mayúsculas y lo convertimos a hash para no guardar el valor real en la base.
    $email=strtolower(trim((string)($_POST['email']??''))); $emailHash=hash('sha256',$email); $ipHash=hash('sha256',(string)($_SERVER['REMOTE_ADDR']??'unknown'));

    // Este INSERT/UPDATE lleva un límite de solicitudes por correo + IP para frenar abuso o fuerza bruta.
    // request_count se reinicia cada hora, de modo que no se bloquea permanentemente una cuenta por un uso normal.
    $pdo->prepare('INSERT INTO password_reset_limits(email_hash,ip_hash,request_count,window_started_at) VALUES(?,?,1,NOW()) ON DUPLICATE KEY UPDATE request_count=IF(window_started_at<NOW()-INTERVAL 1 HOUR,1,request_count+1),window_started_at=IF(window_started_at<NOW()-INTERVAL 1 HOUR,NOW(),window_started_at)')->execute([$emailHash,$ipHash]);
    $limit=$pdo->prepare('SELECT request_count FROM password_reset_limits WHERE email_hash=? AND ip_hash=?'); $limit->execute([$emailHash,$ipHash]); $allowed=(int)$limit->fetchColumn()<=3;
    if($allowed&&filter_var($email,FILTER_VALIDATE_EMAIL)){
        // Solo buscamos un usuario activo. Si no existe, se mantiene la misma respuesta genérica para no filtrar correos válidos.
        $q=$pdo->prepare("SELECT id,email FROM users WHERE email=? AND is_active=1 LIMIT 1"); $q->execute([$email]); $user=$q->fetch();
        if($user){
            // random_bytes() genera un token aleatorio seguro. Luego se guarda solo el hash del token para no exponerlo en la base.
            $token=bin2hex(random_bytes(32)); $pdo->beginTransaction();
            try { 
                // Invalidamos cualquier token previo aún no usado para que solo exista una recuperación activa por usuario.
                $pdo->prepare('UPDATE password_reset_tokens SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')->execute([$user['id']]); 
                $pdo->prepare('INSERT INTO password_reset_tokens(user_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))')->execute([$user['id'],hash('sha256',$token)]); 
                $pdo->commit(); 
            }
            catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}

            // Se arma la URL de recuperación con la base configurable del servidor.
            $base = app_base_url();
            try { 
                // deliver_reset_link() es una capa de correo; si no hay SMTP, se guarda en un buzón local de desarrollo.
                deliver_reset_link((string)$user['email'],$base.'/Registro/Inicio/reset-password.php#'.$token); 
                audit_event($pdo,(int)$user['id'],'auth.password_reset_requested'); 
            }
            catch(Throwable $e){error_log('Prossor reset message unavailable; recipient hash='.hash('sha256',strtolower((string)$user['email'])));}
        }
    }
    // Alerta de recuperación: siempre se muestra el mismo mensaje para evitar enumeración de cuentas.
    $sent=true;
}
require __DIR__ . '/Formularios/formulario-recuperacion.php';
