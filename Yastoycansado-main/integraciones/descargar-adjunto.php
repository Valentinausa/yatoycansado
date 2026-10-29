<?php
declare(strict_types=1);

// Descarga un archivo adjunto con reglas de permisos por rol.
// Solo permite ver archivos a quien esté autorizado según la tarea o la entrega.
require __DIR__.'/../includes/auth.php';require_login();require_once __DIR__.'/../config/database.php';require __DIR__.'/../includes/uploads.php';
$uid=(int)$_SESSION['user']['id'];$role=(string)$_SESSION['user']['role'];$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT);
if(!$id){http_response_code(404);exit;}
$q=$pdo->prepare('SELECT f.*,a.teacher_id,a.status assignment_status,s.student_id FROM assignment_files f JOIN assignments a ON a.id=f.assignment_id LEFT JOIN assignment_submissions s ON s.id=f.submission_id WHERE f.id=?');$q->execute([$id]);$file=$q->fetch();
if(!$file){http_response_code(404);exit;}
$allowed=$role==='teacher'&&(int)$file['teacher_id']===$uid;
if($role==='student'){
 if($file['submission_id']!==null)$allowed=(int)$file['student_id']===$uid;
 else{$t=$pdo->prepare("SELECT 1 FROM assignment_targets t WHERE t.assignment_id=? AND (t.student_id=? OR (t.grade_level=? AND t.group_id IS NULL) OR (t.group_id IS NOT NULL AND EXISTS(SELECT 1 FROM group_students gs WHERE gs.group_id=t.group_id AND gs.student_id=?)))");$t->execute([$file['assignment_id'],$uid,$_SESSION['user']['grade_level']??null,$uid]);$allowed=$file['assignment_status']==='published'&&(bool)$t->fetchColumn();}
}
if(!$allowed){http_response_code(404);exit;}
try{$path=private_upload_dir().DIRECTORY_SEPARATOR.$file['storage_name'];if(!is_file($path)){http_response_code(404);exit;}header('Content-Type: '.$file['mime_type']);header('Content-Length: '.filesize($path));header('Content-Disposition: attachment; filename="'.rawurlencode((string)$file['original_name']).'"');header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store');readfile($path);}catch(Throwable $e){http_response_code(404);}
