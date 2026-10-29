<?php
declare(strict_types=1);

// Este archivo presenta el centro de control global de Prossor. Su contenido combina
// consultas agregadas, gestión de cuentas y trazabilidad administrativa.
// El acceso se limita en el servidor; ocultar enlaces no es una medida de autorización.
require __DIR__ . '/../includes/auth.php';
require_role(['superadmin']);
require_once __DIR__ . '/../config/database.php';
csrf_check();

$me = $_SESSION['user'];
$userId = (int) $me['id'];
$flash = '';

// ACCIÓN POST: activar o suspender una cuenta desde la tabla de usuarios.
// Es una operación privilegiada, por eso bloquea auto-suspensión y protege a
// otros superadministradores; el cambio y su evento de auditoría comparten transacción.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'superadmin_user_status') {
    $targetId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
    $nextActive = filter_var(
        $_POST['is_active'] ?? null,
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE
    );

    // Los datos llegan como texto desde el formulario; se convierten y validan
    // explícitamente antes de usarlos para buscar o cambiar una cuenta.
    if ($targetId === false || $targetId < 1 || $nextActive === null) {
        http_response_code(422);
        exit('La cuenta o el estado enviados no son válidos.');
    }

    // Evita que el superadmin actual se bloquee a sí mismo y pierda acceso al panel.
    if ($targetId === $userId) {
        $flash = 'No puedes cambiar el estado de tu propia cuenta.';
    } else {
        // FOR UPDATE serializa cambios concurrentes sobre la cuenta y permite
        // comprobar su rol vigente antes de conceder o retirar acceso.
        $pdo->beginTransaction();
        $targetQuery = $pdo->prepare('SELECT role,is_active FROM users WHERE id=? FOR UPDATE');
        $targetQuery->execute([$targetId]);
        $target = $targetQuery->fetch();

        // El rol raíz es intocable desde la interfaz, incluso por otro superadmin.
        if (!$target || $target['role'] === 'superadmin') {
            $pdo->rollBack();
            $flash = 'No se encontró una cuenta modificable.';
        } elseif ((bool) $target['is_active'] === $nextActive) {
            $pdo->rollBack();
            $flash = 'La cuenta ya tiene ese estado.';
        } else {
            // Guarda el nuevo estado y registra quién lo modificó y a qué cuenta.
            $pdo->prepare('UPDATE users SET is_active=? WHERE id=?')
                ->execute([$nextActive, $targetId]);
            audit_event($pdo, $userId, 'superadmin.user_status_changed', [
                'target_id' => $targetId,
                'role' => $target['role'],
                'is_active' => $nextActive,
            ]);
            $pdo->commit();
            $flash = $nextActive
                ? 'La cuenta quedó activada.'
                : 'La cuenta quedó suspendida.';
        }
    }
}

// FILTROS DE CUENTAS: limita el texto de búsqueda y acepta solo los roles/estados
// definidos por el sistema. Los valores desconocidos no se vuelven condiciones SQL.
$search = trim((string) ($_GET['q'] ?? ''));
$search = mb_substr($search, 0, 100);
$validRoles = ['student', 'parent', 'teacher', 'admin', 'superadmin'];
$roleFilter = (string) ($_GET['role'] ?? '');
if (!in_array($roleFilter, $validRoles, true)) {
    $roleFilter = '';
}

$statusFilter = (string) ($_GET['status'] ?? '');
if (!in_array($statusFilter, ['active', 'inactive'], true)) {
    $statusFilter = '';
}

// La página también es entrada del usuario; un valor inválido vuelve a la primera.
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$page = $page === false ? 1 : max(1, $page);
$pageSize = 25;

// El bloque combina condiciones opcionales con AND. Los datos del filtro viajan
// como parámetros PDO; el tamaño de página es constante y página/offset son enteros.
$userConditions = [];
$userParams = [];
if ($search !== '') {
    $userConditions[] = '(name LIKE ? OR email LIKE ?)';
    $userParams[] = '%' . $search . '%';
    $userParams[] = '%' . $search . '%';
}
if ($roleFilter !== '') {
    $userConditions[] = 'role=?';
    $userParams[] = $roleFilter;
}
if ($statusFilter !== '') {
    $userConditions[] = 'is_active=?';
    $userParams[] = $statusFilter === 'active' ? 1 : 0;
}

// COUNT se ejecuta con los mismos filtros para que el total mostrado y la paginación
// representen el conjunto que realmente coincide con la búsqueda.
$userWhere = $userConditions ? ' WHERE ' . implode(' AND ', $userConditions) : '';
$countUsers = $pdo->prepare('SELECT COUNT(*) FROM users' . $userWhere);
$countUsers->execute($userParams);
$filteredUsers = (int) $countUsers->fetchColumn();
$pageCount = max(1, (int) ceil($filteredUsers / $pageSize));
$page = min($page, $pageCount);
$offset = ($page - 1) * $pageSize;

// Orden cronológico descendente facilita encontrar cuentas recién creadas.
$usersQuery = $pdo->prepare(
    'SELECT id,name,email,role,is_active,created_at,last_login_at
    FROM users' . $userWhere . '
    ORDER BY created_at DESC
    LIMIT ' . $pageSize . ' OFFSET ' . $offset
);
$usersQuery->execute($userParams);
$users = $usersQuery->fetchAll();

// Permite cambiar la ventana de la gráfica sin interpolar valores arbitrarios en SQL.
$validActivityWindows = [7, 14, 30];
$activityWindow = filter_var($_GET['activity_window'] ?? 14, FILTER_VALIDATE_INT);
if ($activityWindow === false || !in_array($activityWindow, $validActivityWindows, true)) {
    $activityWindow = 14;
}

// MÉTRICAS DE PLATAFORMA: subconsultas COUNT agregan los datos en la base de datos,
// evitando traer actividades o perfiles completos al proceso PHP.
// "Activos en 7 días" usa last_login_at; prácticas se cuentan por eventos guardados.
$platformStats = $pdo->query(
    'SELECT
        (SELECT COUNT(*) FROM users) total_users,
        (SELECT COUNT(*) FROM users WHERE is_active=1) active_users,
        (SELECT COUNT(*) FROM users WHERE created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)) new_users_30d,
        (SELECT COUNT(*) FROM users WHERE last_login_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)) active_users_7d,
        (SELECT COUNT(*) FROM activity_attempts WHERE created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)) attempts_7d,
        (SELECT COUNT(*) FROM activity_attempts WHERE created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)) attempts_30d,
        (SELECT COUNT(*) FROM teacher_groups) teacher_groups,
        (SELECT COUNT(*) FROM assignments WHERE status="published") published_assignments,
        (SELECT COUNT(*) FROM exam_configs WHERE published=1) published_exams,
        (SELECT COUNT(*) FROM assignment_submissions WHERE status IN ("submitted","late","in_review")) pending_reviews,
        (SELECT COUNT(*) FROM payment_webhook_events WHERE status="rejected") rejected_webhooks'
)->fetch();

// CALIDAD DEL CONTENIDO: se cuentan todas las preguntas y se separan aprobadas
// de borradores para mostrar el tamaño del catálogo y la cola de revisión.
$contentStats = $pdo->query(
    "SELECT
        COUNT(*) total_questions,
        COALESCE(SUM(review_status='approved'),0) approved_questions,
        COALESCE(SUM(review_status='draft'),0) draft_questions
    FROM questions"
)->fetch();

// SUSCRIPCIONES Y PAGOS: solo cuentan suscripciones vigentes (sin vencimiento o
// con vencimiento futuro). Los montos se conservan en centavos hasta mostrarlos.
$paymentStats = $pdo->query(
    "SELECT
        COALESCE(SUM(status='active' AND (ends_at IS NULL OR ends_at>=CURDATE())),0) active_subscriptions
    FROM user_subscriptions"
)->fetch();
// updated_at aproxima cuándo se procesó la orden: created_at representa cuándo
// se solicitó, no cuándo quedó aprobada por el webhook del proveedor.
$orderStats = $pdo->query(
    "SELECT
        COALESCE(SUM(status='pending'),0) pending_orders,
        COALESCE(SUM(status='approved' AND updated_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)),0) paid_orders_30d,
        COALESCE(SUM(CASE WHEN status='approved' AND updated_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)
            THEN amount_in_cents ELSE 0 END),0) paid_cents_30d
    FROM payment_orders"
)->fetch();

// SERIE DIARIA: el período se limita a opciones permitidas; SQL entrega solo días
// con actividad y el bucle posterior rellena con cero los días vacíos.
$dailyQuery = $pdo->query(
    'SELECT DATE(created_at) activity_date,COUNT(*) attempts
    FROM activity_attempts
    WHERE created_at>=DATE_SUB(CURDATE(),INTERVAL ' . ($activityWindow - 1) . ' DAY)
    GROUP BY DATE(created_at)
    ORDER BY activity_date'
);
$dailyCounts = [];
// Indexa el resultado por fecha para recuperar cada total en tiempo constante.
foreach ($dailyQuery->fetchAll() as $dailyRow) {
    $dailyCounts[$dailyRow['activity_date']] = (int) $dailyRow['attempts'];
}

// Construye todas las fechas de la ventana seleccionada, incluida hoy, aunque
// la consulta no haya devuelto registros para algunos días.
$activityDays = [];
$today = new DateTimeImmutable('today');
for ($dayOffset = $activityWindow - 1; $dayOffset >= 0; $dayOffset--) {
    $day = $today->modify('-' . $dayOffset . ' days');
    $key = $day->format('Y-m-d');
    $activityDays[] = [
        'date' => $day,
        'attempts' => $dailyCounts[$key] ?? 0,
    ];
}
// El mínimo de 1 evita dividir por cero cuando la plataforma no tuvo actividad.
$maxDailyAttempts = max(1, ...array_column($activityDays, 'attempts'));

// FILTROS DE AUDITORÍA: permite buscar por nombre de evento y acotar fechas.
// Las fechas se validan estrictamente para evitar rangos ambiguos o inválidos.
$auditSearch = mb_substr(trim((string) ($_GET['audit_event'] ?? '')), 0, 100);
$auditFrom = trim((string) ($_GET['audit_from'] ?? date('Y-m-d', strtotime('-30 days'))));
$auditTo = trim((string) ($_GET['audit_to'] ?? date('Y-m-d')));
foreach (['audit_from' => $auditFrom, 'audit_to' => $auditTo] as $field => $dateValue) {
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue);
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $dateValue) {
        http_response_code(422);
        exit('El rango de fechas de auditoría no es válido.');
    }
}
if ($auditFrom > $auditTo || strtotime($auditTo) - strtotime($auditFrom) > 366 * 86400) {
    http_response_code(422);
    exit('El rango de auditoría debe ser válido y no superar un año.');
}

$auditConditions = ['a.created_at >= ?', 'a.created_at < DATE_ADD(?, INTERVAL 1 DAY)'];
$auditParams = [$auditFrom . ' 00:00:00', $auditTo . ' 00:00:00'];
if ($auditSearch !== '') {
    $auditConditions[] = 'a.event LIKE ?';
    $auditParams[] = '%' . $auditSearch . '%';
}
$auditWhere = ' WHERE ' . implode(' AND ', $auditConditions);
$auditCount = $pdo->prepare('SELECT COUNT(*) FROM audit_logs a' . $auditWhere);
$auditCount->execute($auditParams);
$filteredAuditCount = (int) $auditCount->fetchColumn();
$auditPageSize = 25;
$auditPageCount = max(1, (int) ceil($filteredAuditCount / $auditPageSize));
$auditPage = filter_var($_GET['audit_page'] ?? 1, FILTER_VALIDATE_INT);
$auditPage = $auditPage === false ? 1 : min(max(1, $auditPage), $auditPageCount);
$auditOffset = ($auditPage - 1) * $auditPageSize;

// La bitácora queda ordenada de más reciente a más antigua; se muestran solo
// actor, evento y fecha para minimizar datos personales en la vista.
$auditQuery = $pdo->prepare(
    'SELECT a.event,a.created_at,u.name actor_name
    FROM audit_logs a
    LEFT JOIN users u ON u.id=a.user_id' . $auditWhere . '
    ORDER BY a.created_at DESC
    LIMIT ' . $auditPageSize . ' OFFSET ' . $auditOffset
);
$auditQuery->execute($auditParams);
$auditRows = $auditQuery->fetchAll();

// Exporta solo los datos coincidentes con los filtros y deja constancia de la descarga.
// El prefijo apostrofe evita que hojas de cálculo interpreten texto como fórmulas.
function superadmin_csv_cell(string $value): string
{
    return preg_match('/^[\x00-\x20]*[=+\-@]/', $value) === 1 ? "'" . $value : $value;
}

$exportType = (string) ($_GET['export'] ?? '');
if (in_array($exportType, ['users', 'audit'], true)) {
    if (!hash_equals(csrf_token(), (string) ($_GET['csrf'] ?? ''))) {
        http_response_code(403);
        exit('Token de exportación inválido.');
    }

    audit_event($pdo, $userId, 'superadmin.data_exported', [
        'dataset' => $exportType,
        'filters_applied' => $exportType === 'users'
            ? ['role' => $roleFilter, 'status' => $statusFilter, 'search' => $search !== '']
            : ['event_search' => $auditSearch !== '', 'from' => $auditFrom, 'to' => $auditTo],
    ]);

    header('Content-Type: text/csv; charset=utf-8');
    header(
        'Content-Disposition: attachment; filename="prossor-superadmin-' . $exportType
        . '-' . date('Y-m-d') . '.csv"'
    );
    $output = fopen('php://output', 'wb');
    fwrite($output, "\xEF\xBB\xBF");

    if ($exportType === 'users') {
        $exportUsers = $pdo->prepare(
            'SELECT name,email,role,is_active,created_at,last_login_at
            FROM users' . $userWhere . '
            ORDER BY created_at DESC'
        );
        $exportUsers->execute($userParams);
        fputcsv($output, ['Nombre', 'Correo', 'Rol', 'Estado', 'Creado', 'Último acceso']);

        while ($account = $exportUsers->fetch()) {
            fputcsv($output, [
                superadmin_csv_cell((string) $account['name']),
                superadmin_csv_cell((string) $account['email']),
                superadmin_csv_cell((string) $account['role']),
                (int) $account['is_active'] ? 'Activa' : 'Suspendida',
                (string) $account['created_at'],
                (string) ($account['last_login_at'] ?? ''),
            ]);
        }
    } else {
        $exportAudit = $pdo->prepare(
            'SELECT a.event,a.created_at,u.name actor_name
            FROM audit_logs a
            LEFT JOIN users u ON u.id=a.user_id' . $auditWhere . '
            ORDER BY a.created_at DESC'
        );
        $exportAudit->execute($auditParams);
        fputcsv($output, ['Evento', 'Responsable', 'Fecha']);

        while ($auditRow = $exportAudit->fetch()) {
            fputcsv($output, [
                superadmin_csv_cell((string) $auditRow['event']),
                superadmin_csv_cell((string) ($auditRow['actor_name'] ?? 'Cuenta eliminada')),
                (string) $auditRow['created_at'],
            ]);
        }
    }

    fclose($output);
    exit;
}

// Conteo por rol para ayudar a revisar el balance y la distribución de cuentas.
$roleCounts = $pdo->query(
    'SELECT role,COUNT(*) total FROM users GROUP BY role ORDER BY role'
)->fetchAll();
$roleLabels = [
    'student' => 'Estudiantes',
    'parent' => 'Acudientes',
    'teacher' => 'Docentes',
    'admin' => 'Administradores',
    'superadmin' => 'Superadministradores',
];

// ESTADO DE INTEGRACIONES: revisa presencia de variables mínimas del servidor.
// Este indicador no prueba que el servicio responda y nunca expone las credenciales.
$integrationStatus = [
    'Tutor IA' => (string) getenv('PROSSOR_AI_API_KEY') !== '',
    'Correo SMTP' => (string) getenv('PROSSOR_SMTP_HOST') !== ''
        && (string) getenv('PROSSOR_SMTP_USER') !== ''
        && (string) getenv('PROSSOR_SMTP_PASSWORD') !== '',
    'Wompi' => (string) getenv('PROSSOR_WOMPI_PUBLIC_KEY') !== ''
        && (string) getenv('PROSSOR_WOMPI_INTEGRITY_SECRET') !== ''
        && (string) getenv('PROSSOR_WOMPI_EVENT_SECRET') !== '',
];

// Conserva filtros al cambiar de página; http_build_query los codifica en la URL
// y el escape HTML se aplica después, al imprimir el enlace.
$userQueryParams = [
    'q' => $search,
    'role' => $roleFilter,
    'status' => $statusFilter,
    'activity_window' => $activityWindow,
    'audit_event' => $auditSearch,
    'audit_from' => $auditFrom,
    'audit_to' => $auditTo,
];
$previousPage = max(1, $page - 1);
$nextPage = min($pageCount, $page + 1);
$auditQueryParams = [
    'q' => $search,
    'role' => $roleFilter,
    'status' => $statusFilter,
    'activity_window' => $activityWindow,
    'audit_event' => $auditSearch,
    'audit_from' => $auditFrom,
    'audit_to' => $auditTo,
];
?>
<!-- ESTRUCTURA DEL DOCUMENTO: idioma español, diseño adaptable y hoja de estilos compartida. -->
<!doctype html>
<html lang="es">
<head>
    <!-- Metadatos de codificación y viewport para que la interfaz funcione en móvil. -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Superadministración · Prossor AI</title>
    <link rel="stylesheet" href="../assets/css/app.css">
</head>
<body class="app-body">
    <!-- El centro de control no usa la barra lateral de estudiantes: aprovecha el ancho completo. -->
    <main class="main-content superadmin-content">
        <!-- Barra superior: regreso al dashboard general, rol actual y cierre de sesión protegido por CSRF. -->
        <header class="topbar">
            <a href="dashboard.php">← Mi espacio</a>
            <div class="top-actions">
                <span class="role-pill">Superadministración</span>
                <form method="post" action="../Registro/Inicio/logout.php">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <button class="logout">Salir</button>
                </form>
            </div>
        </header>

        <!-- Los mensajes de resultado se escapan antes de insertarse en HTML. -->
        <?php if ($flash !== ''): ?>
            <div class="notice flash"><?= h($flash) ?></div>
        <?php endif; ?>

        <!-- Resumen inicial y accesos directos a las dos tareas operativas más frecuentes. -->
        <section class="welcome">
            <div>
                <p class="eyebrow">CONTROL DE LA PLATAFORMA</p>
                <h1>Hola, <?= h(explode(' ', trim((string) $me['name']))[0]) ?></h1>
                <p class="muted">
                    Indicadores globales de Prossor, calidad académica, operación y auditoría.
                    Los datos se agregan en el servidor y no incluyen secretos de integración.
                </p>
            </div>
            <div class="welcome-actions">
                <a class="button primary" href="reportes.php">Ver reportes →</a>
                <a class="button light" href="preguntas.php">Revisar contenido</a>
            </div>
        </section>

        <!-- Navegación interna para saltar a cada área sin añadir otra página de gestión. -->
        <nav class="superadmin-nav" aria-label="Secciones de superadministración">
            <a href="#overview">Resumen</a>
            <a href="#activity">Actividad</a>
            <a href="#operations">Operación</a>
            <a href="#integrations">Servicios</a>
            <a href="#accounts">Cuentas</a>
            <a href="#audit">Auditoría</a>
        </nav>

        <!-- Alertas operativas accionables: enlazan al módulo que permite resolver cada pendiente. -->
        <section class="admin-alert-grid" aria-label="Pendientes de operación">
            <a class="admin-alert" href="preguntas.php">
                <span>Preguntas por revisar</span>
                <strong><?= (int) $contentStats['draft_questions'] ?></strong>
                <small>Abrir moderación →</small>
            </a>
            <article class="admin-alert">
                <span>Entregas por revisar</span>
                <strong><?= (int) $platformStats['pending_reviews'] ?></strong>
                <small>Pendientes de calificación docente</small>
            </article>
            <a class="admin-alert" href="../pagos/suscripciones.php">
                <span>Órdenes pendientes</span>
                <strong><?= (int) $orderStats['pending_orders'] ?></strong>
                <small>Ver suscripciones →</small>
            </a>
            <article class="admin-alert <?= (int) $platformStats['rejected_webhooks'] > 0 ? 'admin-alert-warning' : '' ?>">
                <span>Webhooks rechazados</span>
                <strong><?= (int) $platformStats['rejected_webhooks'] ?></strong>
                <small>Conteo histórico de eventos rechazados</small>
            </article>
        </section>

        <!-- TARJETAS KPI: combinan alcance total con ventanas recientes para distinguir
             tamaño de la plataforma, crecimiento, actividad y trabajo de moderación. -->
        <section class="stats-grid" id="overview">
            <!-- El total incluye todas las cuentas; el subtítulo cuenta altas de los últimos 30 días. -->
            <article class="stat-card">
                <span>Usuarios registrados</span>
                <strong><?= (int) $platformStats['total_users'] ?></strong>
                <small><?= (int) $platformStats['new_users_30d'] ?> nuevos en 30 días</small>
            </article>
            <!-- La actividad semanal se basa en último acceso, no solo en que la cuenta esté habilitada. -->
            <article class="stat-card">
                <span>Cuentas habilitadas</span>
                <strong><?= (int) $platformStats['active_users'] ?></strong>
                <small><?= (int) $platformStats['active_users_7d'] ?> iniciaron sesión en 7 días</small>
            </article>
            <!-- Contrasta volumen semanal y mensual de intentos registrados en la base. -->
            <article class="stat-card">
                <span>Prácticas recientes</span>
                <strong><?= (int) $platformStats['attempts_7d'] ?></strong>
                <small><?= (int) $platformStats['attempts_30d'] ?> intentos en 30 días</small>
            </article>
            <!-- Resume la cola editorial y el contenido ya aprobado. -->
            <article class="stat-card">
                <span>Contenido pendiente</span>
                <strong><?= (int) $contentStats['draft_questions'] ?></strong>
                <small><?= (int) $contentStats['approved_questions'] ?> preguntas aprobadas</small>
            </article>
        </section>

        <!-- Distribución por rol: los filtros abren directamente la lista de cuentas correspondiente. -->
        <section class="panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">ESTRUCTURA DE CUENTAS</p>
                    <h2>Usuarios por rol</h2>
                </div>
                <a href="#accounts">Gestionar cuentas →</a>
            </div>
            <div class="role-summary-grid">
                <?php foreach ($roleCounts as $roleCount): ?>
                    <?php
                    $accountRole = (string) $roleCount['role'];
                    $roleLabel = $roleLabels[$accountRole] ?? ucfirst($accountRole);
                    ?>
                    <a class="role-summary-card" href="?<?= h(http_build_query([
                        'role' => $accountRole,
                        'activity_window' => $activityWindow,
                        'audit_event' => $auditSearch,
                        'audit_from' => $auditFrom,
                        'audit_to' => $auditTo,
                    ])) ?>#accounts">
                        <span><?= h($roleLabel) ?></span>
                        <strong><?= (int) $roleCount['total'] ?></strong>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- GRÁFICO DE ADOPCIÓN: presenta conteos agregados por día, sin exponer nombres
             ni respuestas individuales. También muestra tareas docentes publicadas. -->
        <section class="panel" id="activity">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">ADOPCIÓN Y USO</p>
                    <h2>Intentos de práctica · últimos <?= $activityWindow ?> días</h2>
                </div>
                <!-- El selector limita la ventana a valores conocidos y conserva los filtros vigentes. -->
                <form method="get" class="inline-filter">
                    <input type="hidden" name="q" value="<?= h($search) ?>">
                    <input type="hidden" name="role" value="<?= h($roleFilter) ?>">
                    <input type="hidden" name="status" value="<?= h($statusFilter) ?>">
                    <input type="hidden" name="audit_event" value="<?= h($auditSearch) ?>">
                    <input type="hidden" name="audit_from" value="<?= h($auditFrom) ?>">
                    <input type="hidden" name="audit_to" value="<?= h($auditTo) ?>">
                    <label>
                        <span class="visually-hidden">Período de actividad</span>
                        <select name="activity_window" onchange="this.form.submit()">
                            <?php foreach ($validActivityWindows as $window): ?>
                                <option value="<?= $window ?>" <?= $activityWindow === $window ? 'selected' : '' ?>>
                                    <?= $window ?> días
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <noscript><button class="mini-button">Aplicar</button></noscript>
                </form>
            </div>
            <p class="muted"><?= (int) $platformStats['published_assignments'] ?> tareas publicadas actualmente.</p>
            <!-- Cada columna representa una fecha; aria-label describe el gráfico a tecnologías de asistencia. -->
            <div
                class="admin-chart"
                role="img"
                aria-label="Intentos de práctica por día durante los últimos <?= $activityWindow ?> días"
                style="--chart-days:<?= $activityWindow ?>"
            >
                <?php foreach ($activityDays as $activityDay): ?>
                    <!-- Escala cada barra contra el máximo del periodo; el mínimo visual permite
                         distinguir un día con cero actividad sin alterar el valor mostrado. -->
                    <?php $barHeight = max(3, (int) round($activityDay['attempts'] * 100 / $maxDailyAttempts)); ?>
                    <div class="chart-column" title="<?= (int) $activityDay['attempts'] ?> intentos">
                        <span class="chart-count"><?= (int) $activityDay['attempts'] ?></span>
                        <div class="chart-track">
                            <span class="chart-bar" style="height:<?= $barHeight ?>%"></span>
                        </div>
                        <small><?= h($activityDay['date']->format('d/m')) ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- RESÚMENES OPERATIVOS: una tarjeta muestra el estado académico y otra el comercial;
             aquí solo se consulta información, no se publica contenido ni se procesa un cobro. -->
        <section class="content-grid lower-grid" id="operations">
            <!-- Estado del banco y de la operación docente, obtenido de conteos agregados. -->
            <div class="panel">
                <div class="panel-head">
                    <div>
                        <p class="eyebrow">CONTENIDO Y DOCENCIA</p>
                        <h2>Operación académica</h2>
                    </div>
                </div>
                <div class="admin-summary-list">
                    <div><span>Preguntas en el banco</span><strong><?= (int) $contentStats['total_questions'] ?></strong></div>
                    <div><span>Preguntas aprobadas</span><strong><?= (int) $contentStats['approved_questions'] ?></strong></div>
                    <div><span>Grupos docentes</span><strong><?= (int) $platformStats['teacher_groups'] ?></strong></div>
                    <div><span>Simulacros publicados</span><strong><?= (int) $platformStats['published_exams'] ?></strong></div>
                </div>
                <p class="muted">
                    Las preguntas en borrador requieren revisión antes de alimentar la práctica del alumnado.
                </p>
            </div>

            <!-- Conteos y valor bruto de órdenes aprobadas; no equivale a ingreso neto contable. -->
            <div class="panel">
                <div class="panel-head">
                    <div>
                        <p class="eyebrow">SUSCRIPCIONES Y PAGOS</p>
                        <h2>Estado comercial</h2>
                    </div>
                    <a href="../pagos/suscripciones.php">Gestionar planes →</a>
                </div>
                <div class="admin-summary-list">
                    <div><span>Suscripciones activas</span><strong><?= (int) $paymentStats['active_subscriptions'] ?></strong></div>
                    <div><span>Órdenes pendientes</span><strong><?= (int) $orderStats['pending_orders'] ?></strong></div>
                    <div><span>Órdenes aprobadas · 30 días</span><strong><?= (int) $orderStats['paid_orders_30d'] ?></strong></div>
                    <div>
                        <span>Valor bruto aprobado · 30 días</span>
                        <strong>$<?= number_format((int) $orderStats['paid_cents_30d'] / 100, 0, ',', '.') ?> COP</strong>
                    </div>
                </div>
                <p class="muted">
                    Los pagos se confirman mediante webhook firmado; este panel es de consulta y no modifica órdenes.
                </p>
            </div>
        </section>

        <!-- CONFIGURACIÓN DE SERVICIOS: muestra si hay variables mínimas presentes.
             No realiza llamadas externas, no valida credenciales y no imprime secretos. -->
        <section class="panel" id="integrations">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">PREPARACIÓN DEL SERVICIO</p>
                    <h2>Integraciones del servidor</h2>
                </div>
                <span class="soft-tag">Base de datos conectada</span>
            </div>
            <div class="integration-grid">
                <!-- Recorre un mapa de etiquetas/booleanos ya calculado en servidor. -->
                <?php foreach ($integrationStatus as $integration => $configured): ?>
                    <article class="integration-card">
                        <span><?= h($integration) ?></span>
                        <strong class="<?= $configured ? 'integration-ready' : 'integration-missing' ?>">
                            <?= $configured ? 'Configurada' : 'Pendiente' ?>
                        </strong>
                    </article>
                <?php endforeach; ?>
            </div>
            <p class="muted">
                La indicación confirma variables mínimas presentes, no comprueba conectividad externa ni credenciales válidas.
            </p>
        </section>

        <!-- GESTIÓN DE CUENTAS: solo el superadmin llega a esta vista por require_role().
             Cada página devuelve hasta 25 filas y las mutaciones vuelven a validar el rol en servidor. -->
        <section class="panel" id="accounts">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">GOBIERNO DE CUENTAS</p>
                    <h2>Usuarios</h2>
                </div>
                <span class="soft-tag"><?= $filteredUsers ?> resultados</span>
            </div>
            <!-- GET permite compartir filtros; el servidor valida y limita cada campo antes de consultar. -->
            <form method="get" class="chat-form account-filter">
                <input type="search" name="q" value="<?= h($search) ?>" maxlength="100" placeholder="Nombre o correo">
                <input type="hidden" name="activity_window" value="<?= $activityWindow ?>">
                <input type="hidden" name="audit_event" value="<?= h($auditSearch) ?>">
                <input type="hidden" name="audit_from" value="<?= h($auditFrom) ?>">
                <input type="hidden" name="audit_to" value="<?= h($auditTo) ?>">
                <!-- Las opciones vienen de una lista blanca definida en el servidor. -->
                <select name="role" aria-label="Filtrar por rol">
                    <option value="">Todos los roles</option>
                    <?php foreach ($validRoles as $validRole): ?>
                        <option value="<?= h($validRole) ?>" <?= $roleFilter === $validRole ? 'selected' : '' ?>>
                            <?= h(ucfirst($validRole)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <!-- El filtro de estado se traduce a is_active solo después de validarse. -->
                <select name="status" aria-label="Filtrar por estado">
                    <option value="">Todos los estados</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Activas</option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Suspendidas</option>
                </select>
                <button class="button light">Filtrar</button>
            </form>
            <!-- Exporta la búsqueda completa, no solo las 25 filas visibles en esta página. -->
            <form method="get" class="export-form">
                <input type="hidden" name="q" value="<?= h($search) ?>">
                <input type="hidden" name="role" value="<?= h($roleFilter) ?>">
                <input type="hidden" name="status" value="<?= h($statusFilter) ?>">
                <input type="hidden" name="activity_window" value="<?= $activityWindow ?>">
                <input type="hidden" name="audit_event" value="<?= h($auditSearch) ?>">
                <input type="hidden" name="audit_from" value="<?= h($auditFrom) ?>">
                <input type="hidden" name="audit_to" value="<?= h($auditTo) ?>">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <button class="button light" name="export" value="users">Exportar resultados CSV</button>
            </form>

            <!-- El estado vacío evita mostrar una tabla sin filas cuando los filtros no coinciden. -->
            <?php if ($users): ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Correo</th>
                                <th>Rol</th>
                                <th>Estado</th>
                                <th>Último acceso</th>
                                <th>Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Escapa valores de perfil; los IDs se convierten a enteros al imprimirlos. -->
                            <?php foreach ($users as $account): ?>
                                <?php $accountActive = (bool) $account['is_active']; ?>
                                <tr>
                                    <td><?= h($account['name']) ?></td>
                                    <td><?= h($account['email']) ?></td>
                                    <td><?= h(ucfirst($account['role'])) ?></td>
                                    <td><?= $accountActive ? 'Activa' : 'Suspendida' ?></td>
                                    <td>
                                        <?= $account['last_login_at']
                                            ? h(date('d/m/Y H:i', strtotime($account['last_login_at'])))
                                            : 'Sin acceso registrado' ?>
                                    </td>
                                    <td>
                                        <!-- Auto-cuenta y rol raíz quedan protegidos también visualmente;
                                             el endpoint POST vuelve a comprobar ambas reglas. -->
                                        <?php if ((int) $account['id'] !== $userId && $account['role'] !== 'superadmin'): ?>
                                            <form method="post">
                                                <!-- CSRF evita que otro sitio fuerce un cambio con la sesión abierta. -->
                                                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                                <input type="hidden" name="user_id" value="<?= (int) $account['id'] ?>">
                                                <input
                                                    type="hidden"
                                                    name="is_active"
                                                    value="<?= $accountActive ? '0' : '1' ?>"
                                                >
                                                <button class="mini-button" name="action" value="superadmin_user_status">
                                                    <?= $accountActive ? 'Suspender' : 'Activar' ?>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="muted">Protegida</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <!-- Navegación acotada que conserva texto, rol y estado seleccionados. -->
                <div class="pagination">
                    <span class="muted">Página <?= $page ?> de <?= $pageCount ?></span>
                    <?php if ($page > 1): ?>
                        <a class="button light" href="?<?= h(http_build_query($userQueryParams + ['page' => $previousPage, 'audit_page' => $auditPage])) ?>">
                            Anterior
                        </a>
                    <?php endif; ?>
                    <?php if ($page < $pageCount): ?>
                        <a class="button light" href="?<?= h(http_build_query($userQueryParams + ['page' => $nextPage, 'audit_page' => $auditPage])) ?>">
                            Siguiente
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <p class="muted">No hay cuentas que coincidan con esos filtros.</p>
            <?php endif; ?>
        </section>

        <!-- AUDITORÍA VISIBLE: presenta evento, responsable y fecha; el detalle completo
             se conserva en base de datos y no se vuelca en una página general. -->
        <section class="panel recent" id="audit">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">TRAZABILIDAD</p>
                    <h2>Bitácora de actividad</h2>
                </div>
                <span class="soft-tag"><?= $filteredAuditCount ?> eventos encontrados</span>
            </div>
            <!-- Filtra la bitácora sin mostrar detalles libres, direcciones IP ni datos de respuestas. -->
            <form method="get" class="chat-form audit-filter">
                <input type="search" name="audit_event" value="<?= h($auditSearch) ?>" maxlength="100" placeholder="Buscar evento (ej. user_status)">
                <label>Desde<input type="date" name="audit_from" value="<?= h($auditFrom) ?>" required></label>
                <label>Hasta<input type="date" name="audit_to" value="<?= h($auditTo) ?>" required></label>
                <input type="hidden" name="q" value="<?= h($search) ?>">
                <input type="hidden" name="role" value="<?= h($roleFilter) ?>">
                <input type="hidden" name="status" value="<?= h($statusFilter) ?>">
                <input type="hidden" name="activity_window" value="<?= $activityWindow ?>">
                <button class="button light">Buscar eventos</button>
            </form>
            <form method="get" class="export-form">
                <input type="hidden" name="audit_event" value="<?= h($auditSearch) ?>">
                <input type="hidden" name="audit_from" value="<?= h($auditFrom) ?>">
                <input type="hidden" name="audit_to" value="<?= h($auditTo) ?>">
                <input type="hidden" name="q" value="<?= h($search) ?>">
                <input type="hidden" name="role" value="<?= h($roleFilter) ?>">
                <input type="hidden" name="status" value="<?= h($statusFilter) ?>">
                <input type="hidden" name="activity_window" value="<?= $activityWindow ?>">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <button class="button light" name="export" value="audit">Exportar bitácora CSV</button>
            </form>
            <!-- Si aún no existen acciones auditadas, se comunica el estado sin fabricar eventos. -->
            <?php if ($auditRows): ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr><th>Evento</th><th>Responsable</th><th>Fecha</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($auditRows as $auditRow): ?>
                                <tr>
                                    <td><?= h($auditRow['event']) ?></td>
                                    <td><?= h($auditRow['actor_name'] ?? 'Cuenta eliminada') ?></td>
                                    <td><?= h(date('d/m/Y H:i', strtotime($auditRow['created_at']))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="muted">Todavía no hay eventos registrados en la bitácora.</p>
            <?php endif; ?>
            <?php if ($filteredAuditCount > $auditPageSize): ?>
                <!-- La paginación de auditoría mantiene intactas las fechas y el criterio de búsqueda. -->
                <div class="pagination">
                    <span class="muted">Página <?= $auditPage ?> de <?= $auditPageCount ?></span>
                    <?php if ($auditPage > 1): ?>
                        <a class="button light" href="?<?= h(http_build_query($auditQueryParams + [
                            'page' => $page,
                            'audit_page' => $auditPage - 1,
                        ])) ?>">Anterior</a>
                    <?php endif; ?>
                    <?php if ($auditPage < $auditPageCount): ?>
                        <a class="button light" href="?<?= h(http_build_query($auditQueryParams + [
                            'page' => $page,
                            'audit_page' => $auditPage + 1,
                        ])) ?>">Siguiente</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- Pie de página: recuerda el alcance del panel y el uso de indicadores agregados. -->
        <footer class="panel">
            <p class="muted">
                Panel de supervisión de Prossor · métricas agregadas y acciones privilegiadas auditadas.
            </p>
        </footer>
    </main>
</body>
</html>
