<?php

// Punto de entrada del dashboard: cargar autenticación primero garantiza que
// ninguna consulta de negocio se ejecute antes de validar sesión y token CSRF.
require __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/role-flows.php';
require __DIR__ . '/../includes/ai-tutor.php';
csrf_check();

$me = $_SESSION['user'];
// El identificador y el rol provienen de la sesión autenticada, nunca de POST/GET.
$uid = (int) $me['id'];
$role = $me['role'];
$flash = '';

// Las acciones mutables se procesan antes de renderizar; csrf_check() ya rechazó
// solicitudes POST sin token válido y cada rama verifica además el rol propietario.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    // Permite que un estudiante complete o actualice su grado académico.
    if ($role === 'student' && $action === 'set_grade') {
        $grade = filter_var($_POST['grade'] ?? null, FILTER_VALIDATE_INT);

        if ($grade !== false && $grade >= 5 && $grade <= 11) {
            $pdo->prepare('UPDATE users SET grade_level=? WHERE id=?')
                ->execute([$grade, $uid]);
            $_SESSION['user']['grade_level'] = $grade;
            $me['grade_level'] = $grade;
            $flash = 'Listo. Tu grado quedó actualizado.';
        } else {
            $flash = 'Selecciona un grado entre quinto y undécimo.';
        }
    }

    // Fija el tamaño del diagnóstico al iniciarlo para que la interfaz represente
    // el conjunto aprobado disponible para el grado del estudiante.
    if ($role === 'student' && $action === 'diagnostic_start' && (int) $me['grade_level'] >= 5) {
        $count = $pdo->prepare(
            "SELECT COUNT(*) FROM questions WHERE grade_level=? AND review_status='approved'"
        );
        $count->execute([(int) $me['grade_level']]);
        $total = (int) $count->fetchColumn();

        if ($total > 0) {
            $pdo->prepare(
                'INSERT INTO diagnostic_attempts(student_id,grade_level,total_questions,started_at) VALUES(?,?,?,NOW())'
            )->execute([$uid, (int) $me['grade_level'], $total]);
            $flash = 'Diagnóstico iniciado. Responde las preguntas y envía tus respuestas al terminar.';
        } else {
            $flash = 'Todavía no hay preguntas aprobadas para tu grado.';
        }
    }

    // Confirma que el intento siga abierto y pertenezca al estudiante actual.
    // Cada opción se coteja contra el catálogo de esa pregunta para no confiar en el navegador.
    if ($role === 'student' && $action === 'diagnostic_submit') {
        $attemptId = (int) ($_POST['attempt_id'] ?? 0);
        $owned = $pdo->prepare(
            'SELECT id,grade_level FROM diagnostic_attempts WHERE id=? AND student_id=? AND finished_at IS NULL'
        );
        $owned->execute([$attemptId, $uid]);
        $attempt = $owned->fetch();

        if ($attempt) {
            $questions = $pdo->prepare(
                "SELECT q.id,q.answer_key,c.id competency_id
                FROM questions q
                JOIN topics t ON t.id=q.topic_id
                JOIN academic_competencies c ON c.id=t.competency_id
                WHERE q.grade_level=? AND q.review_status='approved'"
            );
            $questions->execute([$attempt['grade_level']]);
            $questionRows = $questions->fetchAll();
            $postedAnswers = $_POST['answers'] ?? [];

            // Las respuestas y los acumulados de competencia se escriben como una sola unidad:
            // o se registra el diagnóstico completo o se revierte todo el progreso parcial.
            $pdo->beginTransaction();
            $correctCount = 0;
            $answered = 0;

            foreach ($questionRows as $diagnosticQuestion) {
                $answer = trim((string) ($postedAnswers[$diagnosticQuestion['id']] ?? ''));

                if ($answer === '') {
                    continue;
                }

                $option = $pdo->prepare(
                    'SELECT 1 FROM question_options WHERE question_id=? AND label=?'
                );
                $option->execute([$diagnosticQuestion['id'], $answer]);

                if (!$option->fetchColumn()) {
                    continue;
                }

                $correct = strcasecmp($answer, (string) $diagnosticQuestion['answer_key']) === 0;
                $pdo->prepare(
                    'INSERT INTO diagnostic_answers(attempt_id,question_id,answer,is_correct) VALUES(?,?,?,?)'
                )->execute([$attemptId, $diagnosticQuestion['id'], $answer, $correct]);
                $pdo->prepare(
                    'INSERT INTO student_competency_progress(student_id,competency_id,attempts,correct_count)
                    VALUES(?,?,1,?)
                    ON DUPLICATE KEY UPDATE attempts=attempts+1,correct_count=correct_count+VALUES(correct_count)'
                )->execute([
                    $uid,
                    $diagnosticQuestion['competency_id'],
                    $correct ? 1 : 0,
                ]);

                $correctCount += (int) $correct;
                $answered++;
            }

            if ($answered === count($questionRows) && $answered > 0) {
                $pdo->prepare(
                    'UPDATE diagnostic_attempts
                    SET total_questions=?,correct_count=?,finished_at=NOW()
                    WHERE id=? AND student_id=?'
                )->execute([$answered, $correctCount, $attemptId, $uid]);
                $pdo->commit();
                $flash = 'Diagnóstico terminado: ' . $correctCount . ' de ' . $answered
                    . ' respuestas correctas. Este resultado orienta tu práctica y no equivale a un puntaje oficial.';
            } else {
                $pdo->rollBack();
                $flash = 'No se finalizó: responde todas las preguntas válidas del diagnóstico.';
            }
        }
    }

    // La clave correcta y la competencia se recuperan desde la base, no desde el formulario.
    // El tiempo se limita a una hora para ignorar marcas de sesión antiguas o manipuladas.
    if ($role === 'student' && $action === 'answer') {
        $questionId = (int) ($_POST['question_id'] ?? 0);
        $answer = trim((string) ($_POST['answer'] ?? ''));
        $questionQuery = $pdo->prepare(
            "SELECT q.id,q.answer_key,q.explanation,q.topic_id,c.id competency_id
            FROM questions q
            JOIN topics t ON t.id=q.topic_id
            JOIN academic_competencies c ON c.id=t.competency_id
            WHERE q.id=? AND q.grade_level=? AND q.review_status='approved'"
        );
        $questionQuery->execute([$questionId, (int) $me['grade_level']]);
        $question = $questionQuery->fetch();

        if ($question && $answer !== '') {
            $correct = strcasecmp(trim($answer), trim($question['answer_key'])) === 0;
            $elapsed = max(
                0,
                min(3600, time() - (int) ($_SESSION['question_started_at'][$questionId] ?? time()))
            );
            $pdo->prepare(
                'INSERT INTO activity_attempts(student_id,question_id,answer,is_correct,duration_seconds) VALUES(?,?,?,?,?)'
            )->execute([$uid, $questionId, $answer, $correct, $elapsed]);
            $pdo->prepare(
                'INSERT INTO student_competency_progress(student_id,competency_id,attempts,correct_count)
                VALUES(?,?,1,?)
                ON DUPLICATE KEY UPDATE attempts=attempts+1,correct_count=correct_count+VALUES(correct_count)'
            )->execute([$uid, $question['competency_id'], $correct ? 1 : 0]);

            unset($_SESSION['question_started_at'][$questionId]);
            $flash = $correct
                ? '¡Correcto! ' . $question['explanation']
                : 'Aún no. Revisa la explicación y prueba otra vez.';
        }
    }

    // El dominio es correct_count / attempts; NULLIF evita dividir por cero y
    // COALESCE coloca las competencias sin intentos al inicio de la ruta sugerida.
    if ($role === 'student' && $action === 'plan') {
        $weakTopics = $pdo->prepare(
            'SELECT c.id competency_id,t.id topic_id,s.name subject_name,t.name topic_name,
                COALESCE(p.correct_count/NULLIF(p.attempts,0),0) mastery
            FROM topics t
            JOIN academic_competencies c ON c.id=t.competency_id
            JOIN subjects s ON s.id=c.subject_id
            LEFT JOIN student_competency_progress p ON p.competency_id=c.id AND p.student_id=?
            WHERE t.grade_level=?
            ORDER BY mastery ASC
            LIMIT 3'
        );
        $weakTopics->execute([$uid, (int) $me['grade_level']]);
        $rows = $weakTopics->fetchAll();

        if ($rows) {
            $pdo->prepare(
                "INSERT INTO learning_plans(student_id,title,objective,source)
                VALUES(?, 'Ruta de refuerzo',
                    'Fortalecer las competencias que muestran menor dominio según tus respuestas registradas.',
                    'rules')"
            )->execute([$uid]);
            $planId = (int) $pdo->lastInsertId();

            foreach ($rows as $row) {
                $pdo->prepare(
                    'INSERT INTO learning_plan_items(plan_id,topic_id,title,minutes_estimate,priority) VALUES(?,?,?,?,?)'
                )->execute([
                    $planId,
                    $row['topic_id'],
                    $row['subject_name'] . ' · ' . $row['topic_name'],
                    20,
                    1,
                ]);
            }

            $flash = 'Creamos una ruta automática basada en tus resultados registrados.';
        } else {
            $flash = 'Aún faltan respuestas para recomendar una ruta. Empieza con una práctica.';
        }
    }

    // El UPDATE une el ítem con el plan y exige el mismo student_id de la sesión,
    // para impedir que alguien marque actividades de otra cuenta.
    if ($role === 'student' && $action === 'complete') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $pdo->prepare(
            'UPDATE learning_plan_items i
            JOIN learning_plans p ON p.id=i.plan_id
            SET i.is_completed=1,i.completed_at=NOW()
            WHERE i.id=? AND p.student_id=?'
        )->execute([$itemId, $uid]);
        $flash = 'Actividad marcada como completada.';
    }

    // Delega la conversación al tutor y los demás flujos según su rol.
    if ($role === 'student' && in_array($action, ['chat', 'new_chat'], true)) {
        $flash = tutor_handle_post($pdo, $me, $action);
    }

    role_flow_handle($pdo, $me, $action, $flash);
}

$name = explode(' ', trim($me['name']))[0];

// Un intento pendiente tiene prioridad sobre los resultados históricos; solo se
// carga el último resultado cuando no existe un diagnóstico abierto para la cuenta.
$activeDiagnostic = null;
$diagnosticQuestions = [];
$lastDiagnostic = null;

if ($role === 'student' && (int) $me['grade_level'] >= 5) {
    $diagnosticQuery = $pdo->prepare(
        'SELECT id FROM diagnostic_attempts
        WHERE student_id=? AND finished_at IS NULL
        ORDER BY id DESC
        LIMIT 1'
    );
    $diagnosticQuery->execute([$uid]);
    $activeDiagnostic = $diagnosticQuery->fetchColumn();

    if ($activeDiagnostic) {
        $diagnosticQuery = $pdo->prepare(
            "SELECT q.id,q.prompt,s.name subject
            FROM questions q
            JOIN topics t ON t.id=q.topic_id
            JOIN academic_competencies c ON c.id=t.competency_id
            JOIN subjects s ON s.id=c.subject_id
            WHERE q.grade_level=? AND q.review_status='approved'
            ORDER BY q.id"
        );
        $diagnosticQuery->execute([(int) $me['grade_level']]);
        $diagnosticQuestions = $diagnosticQuery->fetchAll();
        $optionsQuery = $pdo->prepare(
            'SELECT label,body FROM question_options WHERE question_id=? ORDER BY label'
        );

        foreach ($diagnosticQuestions as &$diagnosticQuestion) {
            $optionsQuery->execute([$diagnosticQuestion['id']]);
            $diagnosticQuestion['options'] = $optionsQuery->fetchAll();
        }
        unset($diagnosticQuestion);
    } else {
        $diagnosticQuery = $pdo->prepare(
            'SELECT correct_count,total_questions,finished_at
            FROM diagnostic_attempts
            WHERE student_id=? AND finished_at IS NOT NULL
            ORDER BY finished_at DESC
            LIMIT 1'
        );
        $diagnosticQuery->execute([$uid]);
        $lastDiagnostic = $diagnosticQuery->fetch();
    }
}

// El catálogo de asignaturas es común a las tarjetas del panel y las métricas
// comienzan en valores vacíos para mantener el render seguro en roles no estudiantes.
$subjects = $pdo->query('SELECT id,name,icon FROM subjects ORDER BY id')->fetchAll();
$stats = ['attempts' => 0, 'correct' => 0, 'minutes' => 0];
$recent = [];
$items = [];
$weak = [];

// Las consultas de historial quedan acotadas por student_id. El tiempo total se
// deriva de segundos guardados y las recomendaciones ordenan por menor dominio.
if ($role === 'student') {
    $statsQuery = $pdo->prepare(
        'SELECT COUNT(*) attempts,COALESCE(SUM(is_correct),0) correct,
            COALESCE(SUM(duration_seconds),0) seconds
        FROM activity_attempts
        WHERE student_id=?'
    );
    $statsQuery->execute([$uid]);
    $stats = $statsQuery->fetch();
    $stats['minutes'] = (int) round($stats['seconds'] / 60);

    $recentQuery = $pdo->prepare(
        'SELECT aa.created_at,aa.is_correct,q.prompt,s.name subject
        FROM activity_attempts aa
        JOIN questions q ON q.id=aa.question_id
        JOIN topics t ON t.id=q.topic_id
        JOIN academic_competencies c ON c.id=t.competency_id
        JOIN subjects s ON s.id=c.subject_id
        WHERE aa.student_id=?
        ORDER BY aa.created_at DESC
        LIMIT 5'
    );
    $recentQuery->execute([$uid]);
    $recent = $recentQuery->fetchAll();

    $itemsQuery = $pdo->prepare(
        'SELECT i.*,p.title
        FROM learning_plan_items i
        JOIN learning_plans p ON p.id=i.plan_id
        WHERE p.student_id=? AND p.status="active"
        ORDER BY i.is_completed,i.priority
        LIMIT 6'
    );
    $itemsQuery->execute([$uid]);
    $items = $itemsQuery->fetchAll();

    $weakQuery = $pdo->prepare(
        'SELECT s.name subject,c.name competency,p.attempts,p.correct_count
        FROM student_competency_progress p
        JOIN academic_competencies c ON c.id=p.competency_id
        JOIN subjects s ON s.id=c.subject_id
        WHERE p.student_id=?
        ORDER BY p.correct_count/p.attempts ASC
        LIMIT 3'
    );
    $weakQuery->execute([$uid]);
    $weak = $weakQuery->fetchAll();
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Inicio · Prossor AI</title>
    <link rel="stylesheet" href="../assets/css/app.css">
</head>
<body class="app-body">
    <aside class="sidebar">
        <a class="brand" href="dashboard.php">prossor<span>ai</span></a>
        <div class="side-label">ESPACIO DE APRENDIZAJE</div>

        <!-- La navegación cambia según las herramientas disponibles para cada rol. -->
        <nav>
            <?php if ($role === 'student'): ?>
                <a class="nav-item active" href="dashboard.php"><i>⌂</i> Inicio</a>
                <a class="nav-item" href="#subjects"><i>◈</i> Mis asignaturas</a>
                <a class="nav-item" href="#practice"><i>✳</i> Practicar</a>
                <a class="nav-item" href="#plan"><i>▤</i> Mi plan</a>
                <a class="nav-item" href="#tutor"><i>✦</i> Tutor de estudio</a>
            <?php elseif ($role === 'parent'): ?>
                <a class="nav-item active" href="dashboard.php">⌂ Resumen familiar</a>
                <a class="nav-item" href="#link">+ Vincular estudiante</a>
            <?php elseif ($role === 'teacher'): ?>
                <a class="nav-item active" href="dashboard.php">⌂ Mis cursos</a>
                <a class="nav-item" href="#groups">▤ Grupos</a>
            <?php else: ?>
                <a class="nav-item active" href="dashboard.php">⌂ Administración</a>
                <a class="nav-item" href="#users">♙ Usuarios</a>
            <?php endif; ?>
        </nav>

        <div class="side-bottom">
            <div class="avatar"><?= h(mb_strtoupper(mb_substr($name, 0, 1))) ?></div>
            <div class="user-mini">
                <b><?= h($me['name']) ?></b>
                <small><?= h(ucfirst($role)) ?></small>
            </div>
            <form method="post" action="../Registro/Inicio/logout.php">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <button class="logout" aria-label="Cerrar sesión">↗</button>
            </form>
        </div>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <span>Mi espacio <b>/ Inicio</b></span>
            <div class="top-actions">
                <a href="reportes.php">Reportes</a>
                <a href="../pagos/suscripciones.php">Planes</a>
                <?php if (in_array($role, ['student', 'teacher'], true)): ?>
                    <a href="tareas.php">Tareas</a>
                    <a href="simulacros.php">Simulacros</a>
                <?php endif; ?>
                <?php if (in_array($role, ['teacher', 'admin', 'superadmin'], true)): ?>
                    <a href="preguntas.php">Preguntas</a>
                <?php endif; ?>
                <?php if ($role === 'superadmin'): ?>
                    <a href="superadmin.php">Control global</a>
                <?php endif; ?>
                <span class="role-pill">
                    <?= $role === 'student'
                        ? 'Grado ' . (int) $me['grade_level']
                        : 'Cuenta ' . ucfirst($role) ?>
                </span>
                <form method="post" action="../Registro/Inicio/logout.php">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <button class="logout">Salir</button>
                </form>
            </div>
        </header>

        <?php if ($flash): ?>
            <div class="notice flash"><?= h($flash) ?></div>
        <?php endif; ?>

        <!-- La rama del estudiante compone el flujo pedagógico: diagnóstico de entrada,
             resumen cuantitativo, catálogo, ruta recomendada, práctica y tutor. -->
        <?php if ($role === 'student'): ?>
            <section class="welcome">
                <div>
                    <p class="eyebrow">TU PROGRESO, PASO A PASO</p>
                    <h1>Hola, <?= h($name) ?> <span>✦</span></h1>
                    <p class="muted">
                        Este es tu espacio para aprender con intención. Elige un tema y empieza cuando quieras.
                    </p>
                    <div class="welcome-actions">
                        <a class="button primary" href="#practice">Practicar ahora <span>→</span></a>
                        <form method="post">
                            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                            <button class="button light" name="action" value="plan">Crear mi ruta de refuerzo</button>
                        </form>
                    </div>
                </div>
                <div class="welcome-art">
                    <div class="orb">✦</div>
                    <small>APRENDER ES<br>AVANZAR</small>
                </div>
            </section>

            <?php if ($activeDiagnostic): ?>
                <section class="panel">
                    <div class="panel-head">
                        <div>
                            <p class="eyebrow">DIAGNÓSTICO · GRADO <?= (int) $me['grade_level'] ?></p>
                            <h2>Responde a tu ritmo</h2>
                        </div>
                        <span class="soft-tag"><?= count($diagnosticQuestions) ?> preguntas</span>
                    </div>
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="attempt_id" value="<?= (int) $activeDiagnostic ?>">
                        <?php foreach ($diagnosticQuestions as $index => $diagnosticQuestion): ?>
                            <fieldset class="diagnostic-question">
                                <legend>
                                    <small>
                                        <?= h($diagnosticQuestion['subject']) ?> · Pregunta <?= $index + 1 ?>
                                    </small>
                                    <br>
                                    <?= h($diagnosticQuestion['prompt']) ?>
                                </legend>
                                <?php foreach ($diagnosticQuestion['options'] as $option): ?>
                                    <label class="option">
                                        <input
                                            type="radio"
                                            name="answers[<?= (int) $diagnosticQuestion['id'] ?>]"
                                            value="<?= h($option['label']) ?>"
                                            required
                                        >
                                        <span>
                                            <b><?= h($option['label']) ?></b>
                                            <?= h($option['body']) ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                        <?php endforeach; ?>
                        <button class="button primary" name="action" value="diagnostic_submit">
                            Finalizar diagnóstico →
                        </button>
                    </form>
                </section>
            <?php else: ?>
                <section class="panel">
                    <div class="panel-head">
                        <div>
                            <p class="eyebrow">CONOCE TU PUNTO DE PARTIDA</p>
                            <h2>Diagnóstico académico</h2>
                        </div>
                        <span class="soft-tag">
                            <?= $lastDiagnostic
                                ? (int) $lastDiagnostic['correct_count'] . ' / '
                                    . (int) $lastDiagnostic['total_questions'] . ' último intento'
                                : 'Sin diagnóstico' ?>
                        </span>
                    </div>
                    <p class="muted">
                        Un breve conjunto de preguntas de tu grado ayuda a orientar la práctica.
                        El resultado es interno y no representa un puntaje oficial.
                    </p>
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <button class="button light" name="action" value="diagnostic_start">
                            <?= $lastDiagnostic ? 'Repetir diagnóstico' : 'Iniciar diagnóstico' ?> →
                        </button>
                    </form>
                </section>
            <?php endif; ?>

            <section class="stats-grid">
                <?php if (empty($me['grade_level'])): ?>
                    <div class="panel" style="grid-column:1/-1">
                        <h2>Completa tu perfil académico</h2>
                        <p class="muted">Selecciona tu grado para mostrar preguntas apropiadas.</p>
                        <form method="post" class="chat-form">
                            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                            <select name="grade" required>
                                <option value="">Selecciona grado</option>
                                <?php for ($grade = 5; $grade <= 11; $grade++): ?>
                                    <option value="<?= $grade ?>"><?= $grade ?>°</option>
                                <?php endfor; ?>
                            </select>
                            <button class="button primary" name="action" value="set_grade">Guardar grado</button>
                        </form>
                    </div>
                <?php endif; ?>

                <article class="stat-card">
                    <span>Respuestas registradas</span>
                    <strong><?= (int) $stats['attempts'] ?></strong>
                    <small>Tu actividad guardada</small>
                </article>
                <article class="stat-card">
                    <span>Respuestas correctas</span>
                    <strong><?= (int) $stats['correct'] ?></strong>
                    <small>
                        <?= $stats['attempts']
                            ? (int) round($stats['correct'] * 100 / $stats['attempts']) . '% de tus intentos'
                            : 'Se calculará con tus prácticas' ?>
                    </small>
                </article>
                <article class="stat-card">
                    <span>Tiempo de práctica</span>
                    <strong><?= (int) $stats['minutes'] ?><small> min</small></strong>
                    <small>Tiempo registrado en actividades</small>
                </article>
                <article class="stat-card">
                    <span>Enfoque sugerido</span>
                    <strong class="stat-word"><?= $weak ? h($weak[0]['subject']) : 'Por conocer' ?></strong>
                    <small>
                        <?= $weak ? 'Basado en tus respuestas' : 'Practica para descubrir tus fortalezas' ?>
                    </small>
                </article>
            </section>

            <section class="content-grid">
                <div class="panel" id="subjects">
                    <div class="panel-head">
                        <div>
                            <p class="eyebrow">EXPLORA</p>
                            <h2>Tus asignaturas</h2>
                        </div>
                        <span class="soft-tag">Grado <?= (int) $me['grade_level'] ?></span>
                    </div>
                    <div class="subject-grid">
                        <?php foreach ($subjects as $subject): ?>
                            <a href="#practice" class="subject-card">
                                <span><?= h($subject['icon']) ?></span>
                                <b><?= h($subject['name']) ?></b>
                                <small>Ver práctica <i>→</i></small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="panel" id="plan">
                    <div class="panel-head">
                        <div>
                            <p class="eyebrow">SIGUIENTE PASO</p>
                            <h2>Tu ruta de estudio</h2>
                        </div>
                    </div>
                    <?php if ($items): ?>
                        <?php foreach ($items as $item): ?>
                            <div class="plan-row">
                                <span class="check-dot <?= $item['is_completed'] ? 'done' : '' ?>">✓</span>
                                <div>
                                    <b><?= h($item['title']) ?></b>
                                    <small>
                                        <?= (int) $item['minutes_estimate'] ?>
                                        min · recomendación automática basada en reglas
                                    </small>
                                </div>
                                <?php if (!$item['is_completed']): ?>
                                    <form method="post">
                                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                        <button class="mini-button" name="action" value="complete">Completar</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty">
                            <span>✧</span>
                            <b>Tu ruta se construye contigo</b>
                            <p>
                                Registra algunas respuestas y generaremos recomendaciones según lo que necesites practicar.
                            </p>
                            <form method="post">
                                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                <button class="text-button" name="action" value="plan">
                                    Generar recomendación →
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="content-grid lower-grid">
                <div class="panel" id="practice">
                    <div class="panel-head">
                        <div>
                            <p class="eyebrow">PRÁCTICA CORTA</p>
                            <h2>Un reto para hoy</h2>
                        </div>
                    </div>
                    <?php
                    $practiceQuery = $pdo->prepare(
                        "SELECT q.id,q.prompt,q.type,s.name subject
                        FROM questions q
                        JOIN topics t ON t.id=q.topic_id
                        JOIN academic_competencies c ON c.id=t.competency_id
                        JOIN subjects s ON s.id=c.subject_id
                        WHERE q.grade_level=? AND q.review_status='approved'
                        ORDER BY RAND()
                        LIMIT 1"
                    );
                    $practiceQuery->execute([(int) $me['grade_level']]);
                    $question = $practiceQuery->fetch();
                    ?>

                    <?php if ($question): ?>
                        <?php
                        $_SESSION['question_started_at'][$question['id']] = time();
                        $optionsQuery = $pdo->prepare(
                            'SELECT label,body FROM question_options WHERE question_id=? ORDER BY label'
                        );
                        $optionsQuery->execute([$question['id']]);
                        ?>
                        <div class="question-label">
                            <?= h($question['subject']) ?> ·
                            <?= h($question['type'] === 'single_choice' ? 'Selección única' : 'Respuesta') ?>
                        </div>
                        <p class="question-text"><?= h($question['prompt']) ?></p>
                        <form method="post" class="answer-form">
                            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="question_id" value="<?= (int) $question['id'] ?>">
                            <?php if ($question['type'] === 'single_choice'): ?>
                                <div class="options">
                                    <?php foreach ($optionsQuery as $option): ?>
                                        <label class="option">
                                            <input
                                                type="radio"
                                                name="answer"
                                                value="<?= h($option['label']) ?>"
                                                required
                                            >
                                            <span>
                                                <b><?= h($option['label']) ?></b>
                                                <?= h($option['body']) ?>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <input name="answer" required placeholder="Escribe tu respuesta">
                            <?php endif; ?>
                            <button class="button primary" name="action" value="answer">
                                Comprobar respuesta →
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="empty">
                            <b>Aún no hay preguntas para tu grado</b>
                            <p>
                                Pide al administrador que cargue contenido aprobado para grado
                                <?= (int) $me['grade_level'] ?>.
                            </p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="panel" id="tutor">
                    <div class="panel-head">
                        <div>
                            <p class="eyebrow">ACOMPAÑAMIENTO</p>
                            <h2>Tutor de estudio</h2>
                        </div>
                        <span class="status-dot">Modo local</span>
                    </div>
                    <div class="tutor-note">
                        <span>✦</span>
                        <div>
                            <b>Aprende paso a paso</b>
                            <p>
                                Guarda la conversación y usa contexto académico agregado. Sin credenciales funciona
                                en modo de demostración; con el proveedor configurado genera respuestas personalizadas.
                            </p>
                        </div>
                    </div>
                    <form method="post" class="chat-form">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <input
                            name="message"
                            required
                            maxlength="1200"
                            placeholder="¿Qué tema quieres entender mejor?"
                        >
                        <button class="button primary" name="action" value="chat">Enviar →</button>
                    </form>
                </div>
            </section>

            <section class="panel recent">
                <div class="panel-head">
                    <div>
                        <p class="eyebrow">TU HISTORIAL</p>
                        <h2>Actividad reciente</h2>
                    </div>
                </div>
                <?php if ($recent): ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Asignatura</th>
                                    <th>Pregunta</th>
                                    <th>Resultado</th>
                                    <th>Fecha</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent as $activity): ?>
                                    <tr>
                                        <td><?= h($activity['subject']) ?></td>
                                        <td><?= h(mb_strimwidth($activity['prompt'], 0, 72, '…')) ?></td>
                                        <td>
                                            <span class="<?= $activity['is_correct'] ? 'result-good' : 'result-bad' ?>">
                                                <?= $activity['is_correct'] ? 'Correcta' : 'Para repasar' ?>
                                            </span>
                                        </td>
                                        <td><?= h(date('d/m/Y', strtotime($activity['created_at']))) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="muted">Tus prácticas completadas aparecerán aquí.</p>
                <?php endif; ?>
            </section>

        <!-- Las cuentas familiares, docentes y administradoras tienen paneles propios.
             El superadmin ve el resumen administrativo aquí y el centro global en superadmin.php. -->
        <?php elseif ($role === 'parent'): ?>
            <section class="welcome">
                <div>
                    <p class="eyebrow">ACOMPAÑAMIENTO FAMILIAR</p>
                    <h1>Bienvenido, <?= h($name) ?></h1>
                    <p class="muted">
                        Vincula al estudiante con un código seguro para consultar su progreso autorizado.
                    </p>
                </div>
            </section>
            <section class="panel" id="parent-intro">
                <h2>Vincular estudiante</h2>
                <p>Solicita un código temporal al estudiante y úsalo en el panel de vinculación.</p>
                <div class="notice">
                    No basta con conocer un correo electrónico para acceder a la información de un estudiante.
                </div>
            </section>
        <?php elseif ($role === 'teacher'): ?>
            <section class="welcome">
                <div>
                    <p class="eyebrow">ESPACIO DOCENTE</p>
                    <h1>Hola, <?= h($name) ?></h1>
                    <p class="muted">Crea grupos y consulta su avance desde el panel inferior.</p>
                </div>
            </section>
            <section class="panel" id="teacher-intro">
                <h2>Mis grupos</h2>
                <p class="muted">Crea un grupo y comparte su código con tus estudiantes.</p>
            </section>
        <?php else: ?>
            <section class="welcome">
                <div>
                    <p class="eyebrow">ADMINISTRACIÓN</p>
                    <h1>Panel de <?= h($name) ?></h1>
                    <p class="muted">
                        Gestiona el estado de la plataforma y consulta la actividad registrada.
                    </p>
                </div>
            </section>
            <?php $counts = $pdo->query('SELECT role,COUNT(*) total FROM users GROUP BY role')->fetchAll(); ?>
            <section class="stats-grid">
                <?php foreach ($counts as $accountCount): ?>
                    <article class="stat-card">
                        <span>Cuentas · <?= h(ucfirst($accountCount['role'])) ?></span>
                        <strong><?= (int) $accountCount['total'] ?></strong>
                    </article>
                <?php endforeach; ?>
            </section>
            <section class="panel">
                <h2>Contenido académico</h2>
                <p>
                    <?= (int) $pdo->query('SELECT COUNT(*) FROM questions')->fetchColumn() ?>
                    preguntas originales registradas ·
                    <?= (int) $pdo->query('SELECT COUNT(*) FROM subjects')->fetchColumn() ?>
                    asignaturas
                </p>
                <?php if ($role === 'superadmin'): ?>
                    <a class="button primary" href="superadmin.php">Abrir control global →</a>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <!-- role_flow_render incluye perfil, cambio de contraseña y herramientas de gestión
             autorizadas en servidor; tutor_render decide internamente si aplica a esta cuenta. -->
        <?php role_flow_render($pdo, $me); ?>
        <?php tutor_render($pdo, $me); ?>
    </main>
</body>
</html>
