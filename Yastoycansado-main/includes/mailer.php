<?php
declare(strict_types=1);

// Envío de correos para recuperación de contraseña.
// Si SMTP no está configurado, guarda el mensaje en una carpeta local de prueba.
function mail_configured(): bool { return getenv('PROSSOR_SMTP_HOST') && getenv('PROSSOR_SMTP_FROM'); }

function mail_reset_link(string $recipient, string $link): bool {
    $host=(string)(getenv('PROSSOR_SMTP_HOST')?:''); $from=(string)(getenv('PROSSOR_SMTP_FROM')?:'');
    $user=(string)(getenv('PROSSOR_SMTP_USER')?:''); $password=(string)(getenv('PROSSOR_SMTP_PASSWORD')?:'');
    $port=(int)(getenv('PROSSOR_SMTP_PORT')?:587); $tls=strtolower((string)(getenv('PROSSOR_SMTP_TLS')?:'starttls'));
    if (!filter_var($recipient,FILTER_VALIDATE_EMAIL)||!filter_var($from,FILTER_VALIDATE_EMAIL)||$host==='') return false;
    if(!in_array($tls,['implicit','starttls','none'],true)||($user!==''&&$tls==='none'))throw new RuntimeException('Unsafe SMTP encryption configuration');
    $timeout=10; $errno=0; $errstr=''; $target=($tls==='implicit'?'ssl://':'').$host.':'.$port;
    $socket=@stream_socket_client($target,$errno,$errstr,$timeout,STREAM_CLIENT_CONNECT,stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host]]));
    if (!$socket) throw new RuntimeException('SMTP connection failed'); stream_set_timeout($socket,10);
    try {
        $read=static function() use($socket): array { $line=fgets($socket,2048); if($line===false) throw new RuntimeException('SMTP response missing'); $code=(int)substr($line,0,3); while(isset($line[3])&&$line[3]==='-'){ $line=fgets($socket,2048); if($line===false)throw new RuntimeException('SMTP response incomplete'); } return [$code,$line]; };
        $send=static function(string $command,array $allowed) use($socket,$read): void { if(fwrite($socket,$command."\r\n")===false)throw new RuntimeException('SMTP write failed'); [$code]=$read(); if(!in_array($code,$allowed,true))throw new RuntimeException('SMTP command rejected'); };
        [$code]=$read(); if($code!==220)throw new RuntimeException('SMTP greeting rejected');
        $hostLabel=preg_replace('/[^A-Za-z0-9.-]/','',gethostname()?:'localhost')?:'localhost'; $send('EHLO '.$hostLabel,[250]);
        if($tls==='starttls'){ $send('STARTTLS',[220]); if(!stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_CLIENT))throw new RuntimeException('SMTP TLS failed'); $send('EHLO '.$hostLabel,[250]); }
        if($user!==''){ $send('AUTH LOGIN',[334]); $send(base64_encode($user),[334]); $send(base64_encode($password),[235]); }
        $send('MAIL FROM:<'.$from.'>',[250]); $send('RCPT TO:<'.$recipient.'>',[250,251]); $send('DATA',[354]);
        $body="From: Prossor AI <{$from}>\r\nTo: <{$recipient}>\r\nSubject: Recupera tu contrasena de Prossor AI\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nSolicitaste cambiar la contrasena de tu cuenta. Abre este enlace, valido durante 30 minutos:\r\n{$link}\r\n\r\nSi no solicitaste el cambio, ignora este mensaje.\r\n";
        $body=preg_replace('/\r?\n\./',"\r\n..",$body)??$body; if(fwrite($socket,$body."\r\n.\r\n")===false)throw new RuntimeException('SMTP message failed'); [$code]=$read(); if($code!==250)throw new RuntimeException('SMTP message rejected'); $send('QUIT',[221]); return true;
    } finally { fclose($socket); }
}

function spool_reset_link(string $recipient,string $link): void {
    $dir=(string)(getenv('PROSSOR_MAIL_SPOOL_DIR')?:dirname(__DIR__,3).DIRECTORY_SEPARATOR.'Prossor_private'.DIRECTORY_SEPARATOR.'mail');
    if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Unable to create private mail spool');
    $path=rtrim($dir,'\\/').DIRECTORY_SEPARATOR.'reset-outbox.jsonl';
    $entry=json_encode(['created_at'=>date(DATE_ATOM),'to'=>$recipient,'subject'=>'Recupera tu contrasena de Prossor AI','link'=>$link],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $written=file_put_contents($path,$entry.PHP_EOL,FILE_APPEND|LOCK_EX); if($written===false)throw new RuntimeException('Unable to write private mail spool');
    @chmod($path,0600);
}

function deliver_reset_link(string $recipient,string $link): void {
    try { if(mail_configured()&&mail_reset_link($recipient,$link))return; } catch(Throwable $e) { error_log('Prossor reset mail delivery failed; recipient hash='.hash('sha256',strtolower($recipient))); }
    $environment=strtolower((string)(getenv('PROSSOR_APP_ENV')?:'local'));
    if(in_array($environment,['local','development'],true)) spool_reset_link($recipient,$link);
}
