# Estado del proyecto

## Misión 2

- Recuperación: respuesta genérica, límite por cuenta/IP, token aleatorio almacenado como hash, caducidad, un solo uso y entrega SMTP o bandeja local privada.
- Tutor: adaptador OpenAI Chat Completions, contexto académico, historial persistente, límites y modo local explícito sin clave.
- Tareas: asignación a grupos, publicación/cierre, respuesta de texto, archivos validados fuera del webroot, calificación, feedback y descarga autorizada.
- Preguntas: borrador manual, autoría, revisión y flujo CSV con plantilla/previsualización/confirmación.
- Simulacros: banco aprobado por grado, horarios, duración/intententos, respuestas guardadas, nota de servidor e integración del resultado al progreso por competencia.
- Reportes por rol con filtros básicos y CSV.
- Suscripciones: configuración de planes, duración/acceso, solicitud y cancelación. Wompi cuenta con creación de orden/checkout firmado y webhook verificado e idempotente.
- Migraciones `003-mission-two-modules.sql` y `004-payment-orders.sql` agregan estructuras sin borrar datos.

## Límites y verificación

No se configuraron credenciales de SMTP, IA o Wompi. Se verificó recuperación local y token de un solo uso, grupos/tareas/entregas/calificación, simulacro real con respuesta correcta (100%), tutor local y carga de reportes. Las migraciones se aplicaron a `prossor_colegio` sin cambiar cuentas ni preguntas. El checkout/webhook Wompi no se probó en sandbox.

La importación CSV tiene vista previa y validación, pero falta probar una carga real; la edición/duplicación/filtrado avanzado del banco necesita trabajo adicional. Los reportes actuales cubren principalmente práctica y aún no consolidan todas las tareas/simulacros ni la evolución semanal. No se probaron adjuntos de punta a punta, CSV descargado ni el webhook Wompi sandbox. Consulta `docs/PRUEBAS.md`. Bienestar, gemelo digital y API móvil quedan fuera de esta misión.
