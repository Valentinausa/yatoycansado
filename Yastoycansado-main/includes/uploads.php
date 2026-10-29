<?php
declare(strict_types=1);

// Manejador de archivos privados.
// Guarda adjuntos de tareas en una carpeta segura fuera del documento web público.
function private_upload_dir(): string {
    $dir=(string)(getenv('PROSSOR_PRIVATE_FILES_DIR')?:dirname(__DIR__,3).DIRECTORY_SEPARATOR.'Prossor_private'.DIRECTORY_SEPARATOR.'files');
    if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Private upload directory unavailable');
    return rtrim($dir,'\\/');
}

function store_assignment_upload(array $file): array {
    if(($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)return [];
    if(($file['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK||(int)($file['size']??0)>5*1024*1024)throw new RuntimeException('Archivo inválido o superior a 5 MB.');
    $original=basename((string)($file['name']??''));$ext=strtolower(pathinfo($original,PATHINFO_EXTENSION));$allowed=['pdf'=>['application/pdf'],'png'=>['image/png'],'jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],'txt'=>['text/plain'],'docx'=>['application/vnd.openxmlformats-officedocument.wordprocessingml.document']];
    if(!isset($allowed[$ext])||!is_uploaded_file((string)$file['tmp_name']))throw new RuntimeException('Tipo de archivo no permitido.');
    $finfo=new finfo(FILEINFO_MIME_TYPE);$mime=$finfo->file((string)$file['tmp_name']);if(!in_array($mime,$allowed[$ext],true))throw new RuntimeException('El tipo MIME no coincide con la extensión.');
    $storage=bin2hex(random_bytes(32));$target=private_upload_dir().DIRECTORY_SEPARATOR.$storage;
    if(!move_uploaded_file((string)$file['tmp_name'],$target))throw new RuntimeException('No se pudo guardar el archivo.');@chmod($target,0600);
    return ['storage_name'=>$storage,'original_name'=>mb_substr($original,0,255),'mime_type'=>$mime,'size_bytes'=>(int)$file['size']];
}

function link_assignment_upload(PDO $pdo,int $assignmentId,?int $submissionId,int $uid,array $file): void {
    if(!$file)return;$q=$pdo->prepare('INSERT INTO assignment_files(assignment_id,submission_id,uploaded_by,storage_name,original_name,mime_type,size_bytes) VALUES(?,?,?,?,?,?,?)');$q->execute([$assignmentId,$submissionId,$uid,$file['storage_name'],$file['original_name'],$file['mime_type'],$file['size_bytes']]);
}
