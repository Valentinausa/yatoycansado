<?php
declare(strict_types=1);

// Flujos específicos por rol.
// Aquí se gestionan perfiles, códigos de acceso, grupos, notificaciones y administración.
function role_flow_handle(PDO $pdo, array $me, string $action, string &$flash): void {
    $uid=(int)$me['id']; $role=(string)$me['role'];
    if ($action==='profile_save') {
        $name=trim((string)($_POST['name']??''));
        if (mb_strlen($name)<2 || mb_strlen($name)>100) { $flash='El nombre debe tener entre 2 y 100 caracteres.'; return; }
        if ($role==='student') { $g=filter_var($_POST['grade']??null,FILTER_VALIDATE_INT); if ($g===false || $g<5 || $g>11) { $flash='Selecciona un grado entre 5 y 11.'; return; } $q=$pdo->prepare('UPDATE users SET name=?,grade_level=? WHERE id=?'); $q->execute([$name,$g,$uid]); $_SESSION['user']['grade_level']=$g; }
        else { $q=$pdo->prepare('UPDATE users SET name=? WHERE id=?'); $q->execute([$name,$uid]); }
        $_SESSION['user']['name']=$name; audit_event($pdo,$uid,'profile.updated'); $flash='Perfil actualizado.'; return;
    }
    if ($action==='password_change') {
        $current=(string)($_POST['current_password']??''); $next=(string)($_POST['new_password']??'');
        $q=$pdo->prepare('SELECT password_hash FROM users WHERE id=?'); $q->execute([$uid]); $hash=$q->fetchColumn();
        if (!$hash || !password_verify($current,(string)$hash)) { $flash='La contraseña actual no coincide.'; return; }
        if (strlen($next)<12 || strlen($next)>200) { $flash='La nueva contraseña debe tener entre 12 y 200 caracteres.'; return; }
        $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($next,PASSWORD_DEFAULT),$uid]); session_regenerate_id(true); audit_event($pdo,$uid,'auth.password_changed'); $flash='Contraseña actualizada. Usa la nueva la próxima vez que ingreses.'; return;
    }
    if ($action==='access_code_redeem' && in_array($role,['parent','student'],true)) {
        $code=strtoupper(preg_replace('/[^A-Z0-9]/','',(string)($_POST['access_code']??''))??'');
        if (strlen($code)<8 || strlen($code)>32) { $flash='Revisa el código e inténtalo de nuevo.'; return; }
        $pdo->beginTransaction();
        try {
            $q=$pdo->prepare('SELECT * FROM access_codes WHERE code_hash=? AND used_at IS NULL AND expires_at>NOW() FOR UPDATE'); $q->execute([hash('sha256',$code)]); $invite=$q->fetch();
            if (!$invite) { $pdo->rollBack(); $flash='El código no existe, venció o ya fue utilizado.'; return; }
            if ($invite['code_type']==='parent_student' && $role==='parent') {
                $student=$pdo->prepare("SELECT id,role FROM users WHERE id=? AND role='student' AND is_active=1"); $student->execute([$invite['entity_id']]);
                if (!$student->fetch()) { $pdo->rollBack(); $flash='No se pudo validar el vínculo.'; return; }
                $pdo->prepare("INSERT INTO parent_student_relationships(parent_id,student_id,status) VALUES(?,?,'active') ON DUPLICATE KEY UPDATE status='active'")->execute([$uid,$invite['entity_id']]);
            } elseif ($invite['code_type']==='teacher_group' && $role==='student') {
                $group=$pdo->prepare('SELECT id,grade_level FROM teacher_groups WHERE id=?'); $group->execute([$invite['entity_id']]); $groupRow=$group->fetch();
                if (!$groupRow || (int)$groupRow['grade_level']!==(int)($me['grade_level']??0)) { $pdo->rollBack(); $flash='El grupo no corresponde a tu grado.'; return; }
                $pdo->prepare('INSERT IGNORE INTO group_students(group_id,student_id) VALUES(?,?)')->execute([$groupRow['id'],$uid]);
            } else { $pdo->rollBack(); $flash='Este código no corresponde a tu tipo de cuenta.'; return; }
            $pdo->prepare('UPDATE access_codes SET used_by=?,used_at=NOW() WHERE id=?')->execute([$uid,$invite['id']]);
            audit_event($pdo,$uid,'access_code.redeemed',['type'=>$invite['code_type']]); $pdo->commit(); $flash='Vínculo confirmado. Ya tienes acceso autorizado.';
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        return;
    }
    if ($action==='parent_code_create' && $role==='student') {
        $code=strtoupper(bin2hex(random_bytes(6))); $q=$pdo->prepare("INSERT INTO access_codes(code_hash,code_type,entity_id,created_by,expires_at) VALUES(?,'parent_student',?,?,DATE_ADD(NOW(),INTERVAL 3 DAY))"); $q->execute([hash('sha256',$code),$uid,$uid]); audit_event($pdo,$uid,'parent_link.code_created'); $flash='Comparte una sola vez este código con tu acudiente (vence en 3 días): '.$code; return;
    }
    if ($action==='group_create' && $role==='teacher') {
        $title=trim((string)($_POST['group_name']??'')); $grade=filter_var($_POST['group_grade']??null,FILTER_VALIDATE_INT);
        if (mb_strlen($title)<2 || mb_strlen($title)>120 || $grade===false || $grade<5 || $grade>11) { $flash='Ingresa un nombre de grupo y un grado válido.'; return; }
        $pdo->beginTransaction(); try { $pdo->prepare('INSERT INTO teacher_groups(teacher_id,name,grade_level) VALUES(?,?,?)')->execute([$uid,$title,$grade]); $gid=(int)$pdo->lastInsertId(); $code=strtoupper(bin2hex(random_bytes(6))); $pdo->prepare("INSERT INTO access_codes(code_hash,code_type,entity_id,created_by,expires_at) VALUES(?,'teacher_group',?,?,DATE_ADD(NOW(),INTERVAL 14 DAY))")->execute([hash('sha256',$code),$gid,$uid]); audit_event($pdo,$uid,'teacher_group.created',['group_id'=>$gid]); $pdo->commit(); $flash='Grupo creado. Comparte el código de inscripción (vence en 14 días): '.$code; } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; } return;
    }
    if ($action==='notification_read') { $nid=(int)($_POST['notification_id']??0); $pdo->prepare('UPDATE notifications SET read_at=NOW() WHERE id=? AND user_id=? AND read_at IS NULL')->execute([$nid,$uid]); $flash='Notificación marcada como leída.'; return; }
    if ($action==='admin_toggle_user' && in_array($role,['admin','superadmin'],true)) {
        $target=(int)($_POST['user_id']??0);
        if ($target===$uid) { $flash='No puedes cambiar el estado de tu propia cuenta.'; return; }

        // El bloqueo evita cambios concurrentes sobre la misma cuenta; el rol se
        // vuelve a comprobar dentro de la transacción, no solo desde la tabla HTML.
        $pdo->beginTransaction();
        try {
            $account=$pdo->prepare('SELECT role,is_active FROM users WHERE id=? FOR UPDATE');
            $account->execute([$target]);
            $targetAccount=$account->fetch();
            $protected=$role==='superadmin'
                ? ($targetAccount&&$targetAccount['role']==='superadmin')
                : ($targetAccount&&in_array($targetAccount['role'],['admin','superadmin'],true));

            if (!$targetAccount||$protected) {
                $pdo->rollBack();
                $flash='No se encontró una cuenta modificable.';
                return;
            }

            $pdo->prepare('UPDATE users SET is_active=NOT is_active WHERE id=?')->execute([$target]);
            $event=$role==='superadmin'?'superadmin.user_status_changed':'admin.user_status_changed';
            audit_event($pdo,$uid,$event,[
                'target_id'=>$target,
                'role'=>$targetAccount['role'],
                'is_active'=>!(bool)$targetAccount['is_active'],
            ]);
            $pdo->commit();
            $flash='Estado de la cuenta actualizado.';
        } catch(Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return;
    }
    if ($action==='admin_question_status' && in_array($role,['admin','superadmin'],true)) {
        $qid=(int)($_POST['question_id']??0); $status=(string)($_POST['review_status']??'');
        if (!in_array($status,['draft','approved'],true)) { $flash='Estado inválido.'; return; }
        $q=$pdo->prepare('UPDATE questions SET review_status=? WHERE id=?'); $q->execute([$status,$qid]);
        if ($q->rowCount()) { audit_event($pdo,$uid,'admin.question_status_changed',['question_id'=>$qid,'status'=>$status]); $flash='Estado de publicación actualizado.'; } else $flash='No se encontró la pregunta.'; return;
    }
}

function role_flow_render(PDO $pdo, array $me): void {
    $uid=(int)$me['id']; $role=(string)$me['role']; $token=h(csrf_token());
    echo '<section class="panel" id="profile"><h2>Mi perfil</h2><form method="post" class="stack"><input type="hidden" name="csrf" value="'.$token.'"><label>Nombre<input name="name" value="'.h((string)$me['name']).'" required minlength="2" maxlength="100"></label>';
    if ($role==='student') { echo '<label>Grado<select name="grade" required>'; for($g=5;$g<=11;$g++) echo '<option value="'.$g.'"'.((int)$me['grade_level']===$g?' selected':'').'>'.$g.'°</option>'; echo '</select></label>'; }
    echo '<button class="button light" name="action" value="profile_save">Guardar perfil</button></form><details><summary>Cambiar contraseña</summary><form method="post" class="stack"><input type="hidden" name="csrf" value="'.$token.'"><label>Contraseña actual<input type="password" name="current_password" required autocomplete="current-password"></label><label>Nueva contraseña (12 caracteres mínimo)<input type="password" name="new_password" minlength="12" maxlength="200" required autocomplete="new-password"></label><button class="button light" name="action" value="password_change">Actualizar contraseña</button></form></details></section>';
    if ($role==='student') {
        echo '<section class="panel"><h2>Vínculos y grupos</h2><p class="muted">Genera un código temporal para tu acudiente o ingresa el código que te compartió tu docente.</p><form method="post"><input type="hidden" name="csrf" value="'.$token.'"><button class="button light" name="action" value="parent_code_create">Crear código para acudiente</button></form><form method="post" class="chat-form"><input type="hidden" name="csrf" value="'.$token.'"><input name="access_code" minlength="8" maxlength="32" required placeholder="Código del grupo"><button class="button light" name="action" value="access_code_redeem">Unirme</button></form></section>';
    } elseif ($role==='parent') {
        echo '<section class="panel" id="link"><h2>Vincular estudiante</h2><p class="muted">Solicita el código directamente al estudiante. El código vence en tres días y se usa una sola vez.</p><form method="post" class="chat-form"><input type="hidden" name="csrf" value="'.$token.'"><input name="access_code" minlength="8" maxlength="32" required placeholder="Código de vinculación"><button class="button primary" name="action" value="access_code_redeem">Vincular</button></form>';
        $q=$pdo->prepare("SELECT u.id,u.name,u.grade_level FROM parent_student_relationships r JOIN users u ON u.id=r.student_id WHERE r.parent_id=? AND r.status='active' ORDER BY u.name"); $q->execute([$uid]); $students=$q->fetchAll();
        if ($students) { foreach($students as $s) { $stats=$pdo->prepare('SELECT COUNT(*) attempts,COALESCE(SUM(is_correct),0) correct FROM activity_attempts WHERE student_id=?'); $stats->execute([$s['id']]); $st=$stats->fetch(); echo '<article class="plan-row"><div><b>'.h($s['name']).' · Grado '.(int)$s['grade_level'].'</b><small>'.(int)$st['correct'].' correctas de '.(int)$st['attempts'].' respuestas registradas</small></div></article>'; } } else echo '<p class="muted">Todavía no hay estudiantes vinculados.</p>';
        echo '</section>';
    } elseif ($role==='teacher') {
        echo '<section class="panel" id="groups"><h2>Mis grupos</h2><form method="post" class="chat-form"><input type="hidden" name="csrf" value="'.$token.'"><input name="group_name" required minlength="2" maxlength="120" placeholder="Nombre del grupo"><select name="group_grade" required><option value="">Grado</option>'; for($g=5;$g<=11;$g++) echo '<option value="'.$g.'">'.$g.'°</option>'; echo '</select><button class="button primary" name="action" value="group_create">Crear grupo</button></form>';
        $q=$pdo->prepare('SELECT g.id,g.name,g.grade_level,COUNT(gs.student_id) students FROM teacher_groups g LEFT JOIN group_students gs ON gs.group_id=g.id WHERE g.teacher_id=? GROUP BY g.id ORDER BY g.created_at DESC'); $q->execute([$uid]); $groups=$q->fetchAll();
        foreach($groups as $g) { echo '<h3>'.h($g['name']).' · Grado '.(int)$g['grade_level'].' <small>('.(int)$g['students'].' estudiantes)</small></h3>'; $st=$pdo->prepare('SELECT u.name,COUNT(a.id) attempts,COALESCE(SUM(a.is_correct),0) correct FROM group_students gs JOIN users u ON u.id=gs.student_id LEFT JOIN activity_attempts a ON a.student_id=u.id WHERE gs.group_id=? GROUP BY u.id ORDER BY u.name'); $st->execute([$g['id']]); $members=$st->fetchAll(); if($members) { echo '<div class="table-wrap"><table><thead><tr><th>Estudiante</th><th>Respuestas</th><th>Correctas</th></tr></thead><tbody>'; foreach($members as $m) echo '<tr><td>'.h($m['name']).'</td><td>'.(int)$m['attempts'].'</td><td>'.(int)$m['correct'].'</td></tr>'; echo '</tbody></table></div>'; } else echo '<p class="muted">Aún no hay estudiantes. Pueden unirse con el código de inscripción.</p>'; }
        if (!$groups) echo '<p class="muted">Crea un grupo para empezar.</p>'; echo '</section>';
    } elseif (in_array($role,['admin','superadmin'],true)) {
        $users=$pdo->query('SELECT id,name,email,role,is_active,created_at FROM users ORDER BY created_at DESC LIMIT 100')->fetchAll();
        echo '<section class="panel" id="users"><h2>Cuentas (100 recientes)</h2><div class="table-wrap"><table><thead><tr><th>Nombre</th><th>Correo</th><th>Perfil</th><th>Estado</th><th>Acción</th></tr></thead><tbody>'; foreach($users as $u) { echo '<tr><td>'.h($u['name']).'</td><td>'.h($u['email']).'</td><td>'.h($u['role']).'</td><td>'.((int)$u['is_active']?'Activa':'Inactiva').'</td><td>'; $canManageUser=(int)$u['id']!==$uid&&$u['role']!=='superadmin'&&($role==='superadmin'||$u['role']!=='admin'); if($canManageUser) echo '<form method="post"><input type="hidden" name="csrf" value="'.$token.'"><input type="hidden" name="user_id" value="'.(int)$u['id'].'"><button class="mini-button" name="action" value="admin_toggle_user">'.((int)$u['is_active']?'Desactivar':'Activar').'</button></form>'; echo '</td></tr>'; } echo '</tbody></table></div></section>';
        $questions=$pdo->query('SELECT q.id,q.prompt,q.grade_level,q.review_status,s.name subject FROM questions q JOIN topics t ON t.id=q.topic_id JOIN academic_competencies c ON c.id=t.competency_id JOIN subjects s ON s.id=c.subject_id ORDER BY q.created_at DESC LIMIT 100')->fetchAll();
        echo '<section class="panel"><h2>Revisión de preguntas (100 recientes)</h2><div class="table-wrap"><table><thead><tr><th>Área</th><th>Grado</th><th>Enunciado</th><th>Estado</th><th></th></tr></thead><tbody>'; foreach($questions as $q) echo '<tr><td>'.h($q['subject']).'</td><td>'.(int)$q['grade_level'].'</td><td>'.h(mb_strimwidth($q['prompt'],0,90,'…')).'</td><td>'.h($q['review_status']).'</td><td><form method="post"><input type="hidden" name="csrf" value="'.$token.'"><input type="hidden" name="question_id" value="'.(int)$q['id'].'"><input type="hidden" name="review_status" value="'.($q['review_status']==='approved'?'draft':'approved').'"><button class="mini-button" name="action" value="admin_question_status">'.($q['review_status']==='approved'?'Retirar':'Aprobar').'</button></form></td></tr>'; echo '</tbody></table></div></section>';
    }
    $n=$pdo->prepare('SELECT id,title,body,created_at FROM notifications WHERE user_id=? AND read_at IS NULL ORDER BY created_at DESC LIMIT 20'); $n->execute([$uid]); $notifications=$n->fetchAll();
    echo '<section class="panel"><h2>Notificaciones</h2>'; if (!$notifications) echo '<p class="muted">No tienes notificaciones pendientes.</p>'; foreach($notifications as $item) echo '<article class="plan-row"><div><b>'.h($item['title']).'</b><small>'.h($item['body']).'</small></div><form method="post"><input type="hidden" name="csrf" value="'.$token.'"><input type="hidden" name="notification_id" value="'.(int)$item['id'].'"><button class="mini-button" name="action" value="notification_read">Leída</button></form></article>'; echo '</section>';
}
