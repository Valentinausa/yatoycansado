# Roles y permisos

| Rol | Capacidades | Límites aplicados en servidor |
| --- | --- | --- |
| Estudiante | Práctica, diagnóstico, tutor, tareas, entregas, simulacros, reportes propios, planes y perfil | Solo consulta y modifica sus propios intentos, entregas, reportes y conversación; solo accede a grupos de su grado |
| Acudiente | Canjear código y consultar reportes de estudiantes vinculados | Consultas limitadas a una relación activa; no busca cuentas ajenas por correo |
| Docente | Grupos, tareas, calificación/feedback, preguntas propias, simulacros y reportes de sus grupos | No ve grupos ni entregas de otro docente; descargas de archivos requieren membresía/asignación válida |
| Administrador | Revisar cuentas, moderar preguntas, administrar planes y consultar informes agregados | Acciones administrativas auditadas; no puede desactivar su cuenta ni otra cuenta admin desde la gestión acotada |
| Superadministrador | Supervisión global de usuarios, actividad agregada, contenido, pagos, integraciones y bitácora; puede suspender cuentas no superadmin y configurar planes | Rol creado solo por CLI; cada página comprueba el rol en servidor; no puede suspenderse a sí mismo ni a otro superadmin; los cambios privilegiados quedan auditados |

Las mutaciones POST verifican CSRF. Las claves de respuesta, evaluaciones, tokens de recuperación y eventos sensibles se procesan en servidor. El rol no se recibe desde formularios de perfil; cada petición autenticada vuelve a comprobar en base de datos que la cuenta siga activa y sincroniza su rol con la sesión.

El panel `panel/superadmin.php` requiere aplicar `005-superadmin-role.sql` y crear una cuenta separada con `scripts/create-superadmin.php`. Sus indicadores son agregados y los secretos de SMTP, IA y Wompi nunca se muestran; el estado de integración solo indica si existen variables mínimas configuradas. Suspender una cuenta invalida su acceso protegido en la siguiente petición.

Las órdenes Wompi solo activan un plan después de recibir un webhook con firma válida; el retorno del navegador no concede acceso. Sin credenciales, la solicitud queda pendiente. Antes del despliegue institucional, configura Wompi en sandbox, SMTP/IA, HTTPS, controles de acceso institucionales y revisiones de privacidad para estudiantes menores.
