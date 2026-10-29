# Arquitectura

Prossor usa PHP con renderizado en servidor y MySQL/MariaDB. Las rutas validan sesión y rol antes de consultar o modificar datos. `includes/auth.php` centraliza sesión, CSRF, escape HTML y auditoría; `config/database.php` crea PDO con consultas preparadas y fija la zona horaria de Colombia tanto en PHP como en la conexión MySQL.

El esquema inicial está en `database/schema.sql`; las migraciones incrementales agregan estructuras sin eliminar datos. La migración 003 habilita recuperación, límites del tutor, tareas, entregas, archivos, autoría/importación de preguntas, opciones de simulacro y deduplicación de eventos de pago.

La migración `005-superadmin-role.sql` añade el rol raíz de plataforma sin promover cuentas existentes. `panel/superadmin.php` valida ese rol en cada petición y reúne métricas agregadas, indicadores de integración, gestión auditada de cuentas y eventos administrativos recientes. La cuenta se aprovisiona desde CLI con `scripts/create-superadmin.php`, no mediante el registro público.

Las calificaciones de prácticas y simulacros se computan en servidor con las claves de respuesta persistidas. Archivos de tareas se validan y guardan fuera del webroot; las descargas comprueban que el usuario tenga acceso a la entrega/grupo.

`includes/ai-tutor.php` construye contexto agregado de progreso y usa un endpoint compatible con OpenAI Chat Completions si se configuran credenciales. Sin clave responde claramente en modo demostrativo local. `includes/mailer.php` envía por SMTP o escribe un mensaje local privado para desarrollo.

`includes/payments.php` crea checkout Wompi con referencia aleatoria y firma de integridad en servidor. `pagos/wompi-webhook.php` valida el checksum del proveedor, coteja referencia/monto/moneda/ambiente y procesa eventos con clave de idempotencia antes de activar una suscripción. La migración 004 agrega órdenes de pago y duración opcional de plan. La integración aún necesita credenciales sandbox y verificación contra eventos reales de prueba.
