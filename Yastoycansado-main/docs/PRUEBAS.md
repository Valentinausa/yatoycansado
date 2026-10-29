# Verificación local

Se usó una base temporal aislada (`prossor_mission2_test_20261016`) y cuentas de prueba sin datos personales; la base existente `prossor_colegio` recibió solo las migraciones incrementales. La base temporal se eliminó al finalizar y se vació su bandeja local de mensajes.

## Ejecutado

- Migraciones 002 y 003 aplicadas; se conservó el conteo de datos de la base existente.
- Migración 004 aplicada; las tablas de órdenes/webhooks y el campo de duración se crearon sin cambiar precios.
- Sintaxis PHP de los archivos de la aplicación verificada con `php -l`.
- Página de login (200), dashboard sin sesión (redirección), logout GET (405) y rechazo CSRF (403).
- Solicitud de recuperación, restablecimiento con token, rechazo de reutilización y nuevo login en base temporal.
- Cuenta docente, grupos, código de incorporación, tarea, entrega del estudiante y calificación/feedback docente.
- Creación de pregunta en borrador y creación/publicación de simulacro en base temporal. Inicio, guardado de respuesta y finalización de un intento con una respuesta correcta: puntuación guardada de 100% y progreso por competencia.
- La prueba del temporizador descubrió una diferencia de zona horaria y una llamada PDO incorrecta; se corrigieron ambas y se repitió el recorrido correcto.
- Reporte estudiantil y tutor local abrieron; el tutor guardó y mostró la respuesta de demostración.

## Pendiente de credenciales o validación adicional

- El panel de superadministración y la migración 005 requieren validación de extremo a extremo con una base temporal: acceso exclusivo por rol, búsqueda/paginación, exportación CSV y protección frente a fórmulas, filtros/exportación auditables de bitácora, suspensión auditada con revocación en la siguiente petición, alertas operativas, resumen de pagos y estado de integraciones.
- No hay credenciales SMTP ni de IA: no se verificó entrega de correo a un proveedor ni inferencia externa. La bandeja local de desarrollo sí se usó para recuperar el token.
- No se completó la importación CSV real, las pruebas de adjuntos y descargas, permisos de reportes por cada rol, ni la descarga CSV.
- Wompi tiene código de checkout y webhook verificado, pero no se pudo probar con credenciales sandbox. El flujo de pago queda pendiente de esa prueba externa.
- Los planes existentes de precio cero permanecen sin cambios. Las suscripciones de pago no pueden activarse automáticamente.
- Las migraciones 003 y 004 se aplicaron a la base local existente; realiza una copia de seguridad antes de aplicar en cualquier otra base.

La base temporal y los archivos de correo temporal deben eliminarse al terminar una sesión de pruebas. No uses datos o credenciales de producción para las pruebas de escritura.
