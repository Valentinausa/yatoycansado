<?php
declare(strict_types=1);

// Tutor de estudio con soporte de IA.
// Genera respuestas personalizadas según el nivel, competencias y errores del estudiante.
require_once __DIR__.'/subscriptions.php';

function tutor_provider_ready(): bool { return (bool)(getenv('PROSSOR_AI_API_KEY') && getenv('PROSSOR_AI_MODEL') && (function_exists('curl_init') || ini_get('allow_url_fopen'))); }

function tutor_context(PDO $pdo,int $uid): string {
    $u=$pdo->prepare("SELECT grade_level FROM users WHERE id=? AND role='student'");$u->execute([$uid]);$grade=(int)$u->fetchColumn();
    $p=$pdo->prepare('SELECT s.name subject,c.name competency,p.attempts,p.correct_count FROM student_competency_progress p JOIN academic_competencies c ON c.id=p.competency_id JOIN subjects s ON s.id=c.subject_id WHERE p.student_id=? ORDER BY p.correct_count/NULLIF(p.attempts,0),p.attempts DESC LIMIT 3');$p->execute([$uid]);$weak=$p->fetchAll();
    $context='Grado escolar: '.($grade?:'sin especificar').'.';
    if($weak){$parts=[];foreach($weak as $r)$parts[]=$r['subject'].' / '.$r['competency'].' ('.$r['correct_count'].' de '.$r['attempts'].' correctas)';$context.=' Competencias con menor dominio observadas: '.implode('; ',$parts).'.';}
    $wrong=$pdo->prepare('SELECT q.prompt,s.name subject FROM activity_attempts a JOIN questions q ON q.id=a.question_id JOIN topics t ON t.id=q.topic_id JOIN academic_competencies c ON c.id=t.competency_id JOIN subjects s ON s.id=c.subject_id WHERE a.student_id=? AND a.is_correct=0 ORDER BY a.created_at DESC LIMIT 3');$wrong->execute([$uid]);$errs=$wrong->fetchAll();if($errs){$parts=[];foreach($errs as $r)$parts[]=$r['subject'].': '.mb_substr($r['prompt'],0,100);$context.=' Errores recientes para orientar explicaciones: '.implode(' | ',$parts);}
    return mb_substr($context,0,1400);
}

function tutor_generate_reply(PDO $pdo,int $uid,int $conversationId,string $message): array {
    if(!tutor_provider_ready())return ['provider'=>'local-guided','content'=>'Modo de demostración local (sin IA): intenta explicar con tus palabras qué parte entiendes y dónde te atascaste. Luego podemos dividir el tema en pasos pequeños. Para activar respuestas generadas, configura PROSSOR_AI_API_KEY y PROSSOR_AI_MODEL en el servidor.'];
    if(!user_has_feature($pdo,$uid,'ai_tutor'))return ['provider'=>'local-guided','content'=>'La IA está configurada, pero ninguna de tus suscripciones vigentes incluye ai_tutor. Este modo de demostración local no utiliza el proveedor.'];
    $pdo->prepare('INSERT INTO tutor_usage_limits(user_id,request_count,window_started_at) VALUES(?,1,NOW()) ON DUPLICATE KEY UPDATE request_count=IF(window_started_at<NOW()-INTERVAL 1 HOUR,1,request_count+1),window_started_at=IF(window_started_at<NOW()-INTERVAL 1 HOUR,NOW(),window_started_at)')->execute([$uid]);
    $limit=$pdo->prepare('SELECT request_count FROM tutor_usage_limits WHERE user_id=?');$limit->execute([$uid]);if((int)$limit->fetchColumn()>12)return ['provider'=>'local-guided','content'=>'Llegaste al límite de 12 consultas por hora. Sigue practicando y vuelve a intentar más tarde.'];
    $history=$pdo->prepare("SELECT role,content FROM chat_messages WHERE conversation_id=? ORDER BY id DESC LIMIT 10");$history->execute([$conversationId]);$rows=array_reverse($history->fetchAll());
    $messages=[['role'=>'system','content'=>'Eres un tutor escolar en español para estudiantes colombianos de grados 5 a 11. Enseña paso a paso, ofrece pistas antes de dar respuestas finales, pide al estudiante que razone y usa ejemplos adecuados a su edad. Explica incertidumbre con transparencia; no inventes hechos, y sugiere consultar a un docente si el tema requiere certeza que no tienes. No solicites información personal. Contexto académico agregado del estudiante: '.tutor_context($pdo,$uid)]];
    foreach($rows as $r){if(in_array($r['role'],['user','assistant'],true))$messages[]=['role'=>$r['role'],'content'=>mb_substr((string)$r['content'],0,1400)];}
    $endpoint=(string)(getenv('PROSSOR_AI_ENDPOINT')?:'https://api.openai.com/v1/chat/completions');$urlParts=parse_url($endpoint);$isLocal=in_array(strtolower((string)($urlParts['host']??'')),['localhost','127.0.0.1','::1'],true);if(!isset($urlParts['scheme'])||($urlParts['scheme']!=='https'&&!($urlParts['scheme']==='http'&&$isLocal)))return ['provider'=>'provider-error','content'=>'El endpoint del tutor debe usar HTTPS. Solo localhost puede usar HTTP en pruebas.'];$apiKey=(string)getenv('PROSSOR_AI_API_KEY');$payload=json_encode(['model'=>(string)getenv('PROSSOR_AI_MODEL'),'messages'=>$messages,'temperature'=>0.4,'max_tokens'=>600,'stream'=>false,'store'=>false],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    try{
        if(function_exists('curl_init')){$ch=curl_init($endpoint);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$apiKey],CURLOPT_POSTFIELDS=>$payload,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true]);$body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);if($body===false||$status<200||$status>=300)throw new RuntimeException('Tutor provider request failed '.$status.' '.$err);}
        else{$ctx=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\nAuthorization: Bearer {$apiKey}\r\n",'content'=>$payload,'timeout'=>30,'ignore_errors'=>true],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);$body=@file_get_contents($endpoint,false,$ctx);if($body===false)throw new RuntimeException('Tutor provider request failed');$status=0;foreach($http_response_header??[] as $h)if(preg_match('/^HTTP\/\S+\s+(\d+)/',$h,$m))$status=(int)$m[1];if($status<200||$status>=300)throw new RuntimeException('Tutor provider rejected request');}
        $data=json_decode((string)$body,true,512,JSON_THROW_ON_ERROR);$reply=trim((string)($data['choices'][0]['message']['content']??''));if($reply==='')throw new RuntimeException('Tutor provider returned empty content');return ['provider'=>'openai-compatible','content'=>mb_substr($reply,0,6000)];
    }catch(Throwable $e){error_log('Prossor tutor provider error: '.get_class($e));return ['provider'=>'provider-error','content'=>'El tutor de IA no pudo responder en este momento. Tu pregunta quedó guardada; inténtalo de nuevo en unos minutos o consulta a tu docente.'];}
}

function tutor_handle_post(PDO $pdo,array $me,string $action): string {
    $uid=(int)$me['id'];if($action==='new_chat'){$pdo->prepare('INSERT INTO chat_conversations(user_id,title) VALUES(?,?)')->execute([$uid,'Nueva conversacion']);return 'Iniciaste una conversación nueva.';}
    $message=trim((string)($_POST['message']??''));if($message===''||mb_strlen($message)>1200)return 'Escribe una consulta de hasta 1200 caracteres.';
    $cid=(int)($_POST['conversation_id']??0);$check=$pdo->prepare('SELECT id FROM chat_conversations WHERE id=? AND user_id=?');$check->execute([$cid,$uid]);if(!$check->fetchColumn()){$pdo->prepare('INSERT INTO chat_conversations(user_id,title) VALUES(?,?)')->execute([$uid,mb_substr($message,0,70)]);$cid=(int)$pdo->lastInsertId();}
    $pdo->prepare("INSERT INTO chat_messages(conversation_id,role,content,provider) VALUES(?,'user',?,'student')")->execute([$cid,$message]);$reply=tutor_generate_reply($pdo,$uid,$cid,$message);$pdo->prepare('INSERT INTO chat_messages(conversation_id,role,content,provider) VALUES(?,?,?,?)')->execute([$cid,'assistant',$reply['content'],$reply['provider']]);
    return $reply['provider']==='provider-error'?'Consulta guardada. El proveedor de IA informó un error.':($reply['provider']==='local-guided'?'Consulta guardada en modo de demostración local.':'Respuesta del tutor guardada.');
}

function tutor_render(PDO $pdo,array $me): void {
    if(($me['role']??'')!=='student')return;$uid=(int)$me['id'];$q=$pdo->prepare('SELECT id,title FROM chat_conversations WHERE user_id=? ORDER BY id DESC LIMIT 15');$q->execute([$uid]);$conversations=$q->fetchAll();$cid=(int)($_GET['conversation']??($conversations[0]['id']??0));$owner=$pdo->prepare('SELECT id,title FROM chat_conversations WHERE id=? AND user_id=?');$owner->execute([$cid,$uid]);$current=$owner->fetch();if(!$current&&$conversations){$cid=(int)$conversations[0]['id'];$owner->execute([$cid,$uid]);$current=$owner->fetch();}
    echo '<section class="panel" id="tutor-history"><div class="panel-head"><div><p class="eyebrow">TUTOR</p><h2>'.(tutor_provider_ready()?'IA configurada':'Modo de demostración local').'</h2></div><form method="post"><input type="hidden" name="csrf" value="'.h(csrf_token()).'"><button class="mini-button" name="action" value="new_chat">Nueva conversación</button></form></div>';
    if($conversations){echo '<form method="get" class="chat-form"><label for="conversation">Conversación</label><select id="conversation" name="conversation">';foreach($conversations as $c)echo '<option value="'.(int)$c['id'].'"'.((int)$c['id']===$cid?' selected':'').'>'.h($c['title']).'</option>';echo '</select><button class="mini-button">Abrir</button></form>';}
    if($current){$m=$pdo->prepare('SELECT role,content,created_at,provider FROM chat_messages WHERE conversation_id=? ORDER BY id DESC LIMIT 12');$m->execute([$cid]);$msgs=array_reverse($m->fetchAll());foreach($msgs as $row)echo '<article class="plan-row"><div><b>'.($row['role']==='user'?'Tú':'Tutor').'</b><small>'.nl2br(h($row['content'])).'</small></div></article>';}
    else echo '<p class="muted">Aún no tienes consultas guardadas.</p>';
    echo '<p class="muted">El proveedor recibe el mensaje, el grado y un resumen de competencias/errores; no se envían nombre ni correo. Límite: 12 consultas por hora cuando la IA está configurada.</p></section>';
}
