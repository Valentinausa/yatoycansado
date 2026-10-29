<?php
declare(strict_types=1);

// Muestra los correos de recuperación guardados en el buzón local de desarrollo.
// Útil para pruebas sin SMTP real.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$dir=(string)(getenv('PROSSOR_MAIL_SPOOL_DIR')?:dirname(__DIR__,3).DIRECTORY_SEPARATOR.'Prossor_private'.DIRECTORY_SEPARATOR.'mail');
$path=rtrim($dir,'\\/').DIRECTORY_SEPARATOR.'reset-outbox.jsonl';
if(!is_file($path)){fwrite(STDOUT,"No hay mensajes pendientes en el buzon local.\n");exit(0);}
if(in_array('--clear',$argv,true)){if(file_put_contents($path,'',LOCK_EX)===false){fwrite(STDERR,"No se pudo vaciar el buzon.\n");exit(1);}fwrite(STDOUT,"Buzon local vaciado.\n");exit(0);}
$rows=file($path,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[];
foreach($rows as $line){$mail=json_decode($line,true);if(!is_array($mail))continue;fwrite(STDOUT,"A: ".($mail['to']??'[sin destinatario]')."\nAsunto: ".($mail['subject']??'')."\nEnlace de prueba: ".($mail['link']??'')."\nCreado: ".($mail['created_at']??'')."\n---\n");}
fwrite(STDOUT,"Solo CLI local: este comando revela enlaces de recuperacion. Para borrar los mensajes: php scripts/read-reset-mails.php --clear\n");
