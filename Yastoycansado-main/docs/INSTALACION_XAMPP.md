# Instalación y configuración en XAMPP

1. Coloca el proyecto en `C:\\xampp\\htdocs\\Yastoycansado-main` e inicia Apache y MySQL.
2. Para una base nueva, crea `prossor_colegio` e importa `database/schema.sql`. Para una base con datos, respáldala y aplica solo migraciones pendientes. No importes el esquema base en una base con datos.
3. En un esquema actual, aplica en orden `database/migrations/002-account-and-role-flows.sql`, `003-mission-two-modules.sql`, `004-payment-orders.sql` y `005-superadmin-role.sql`. La migración 001 solo corresponde a un esquema legacy con tabla `users` y columnas `password` y `created_at`; revisa su contenido antes de usarla.
4. Importa opcionalmente `database/seed.sql` para contenido de demostración.
5. Configura `PROSSOR_DB_HOST`, `PROSSOR_DB_NAME`, `PROSSOR_DB_USER` y `PROSSOR_DB_PASSWORD` en el entorno de Apache/PHP. El archivo `.env.example` documenta variables, pero PHP no lo carga por sí mismo. La hora de la aplicación se configura para Colombia.
6. Crea la cuenta administrativa desde PowerShell:

   ```powershell
   $secret = Read-Host "Contraseña nueva (12 caracteres mínimo)" -AsSecureString
   $ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secret)
   try {
     $env:PROSSOR_ADMIN_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
     C:\xampp\php\php.exe scripts\create-admin.php "Administración Colegio" admin@colegio.edu.co
   } finally {
     Remove-Item Env:PROSSOR_ADMIN_PASSWORD -ErrorAction SilentlyContinue
     [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)
   }
   ```

7. Después de aplicar la migración 005, crea el superadministrador desde consola con una contraseña independiente:

   ```powershell
   $secret = Read-Host "Contraseña nueva (12 caracteres mínimo)" -AsSecureString
   $ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secret)
   try {
     $env:PROSSOR_SUPERADMIN_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
     C:\xampp\php\php.exe scripts\create-superadmin.php "Prossor" superadmin@prossor.co
   } finally {
     Remove-Item Env:PROSSOR_SUPERADMIN_PASSWORD -ErrorAction SilentlyContinue
     [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)
   }
   ```

8. Abre `http://localhost/Yastoycansado-main/`. El acceso a `panel/superadmin.php` se concede únicamente al rol `superadmin`.

## Correo de recuperación

Configura `PROSSOR_BASE_URL` con la URL pública HTTPS y completa `PROSSOR_SMTP_HOST`, `PROSSOR_SMTP_PORT`, `PROSSOR_SMTP_TLS`, `PROSSOR_SMTP_USER`, `PROSSOR_SMTP_PASSWORD` y `PROSSOR_MAIL_FROM` en el entorno de PHP. No guardes credenciales en el repositorio. En modo local, sin SMTP, los mensajes se escriben en una carpeta privada fuera de `htdocs` (por defecto `C:\\xampp\\Prossor_private\\mail`). `scripts/read-reset-mails.php` puede leer la bandeja solo desde CLI; no uses la bandeja local como entrega de producción.

## Tutor IA

Para activar respuestas externas, define `PROSSOR_AI_API_KEY`, `PROSSOR_AI_ENDPOINT` (por defecto endpoint Chat Completions), y `PROSSOR_AI_MODEL`. El secreto permanece en servidor. Sin clave, el tutor informa que está en modo demostración y no simula una respuesta de IA. Configura también un plan con `ai_tutor` solo cuando quieras habilitarlo.

## Suscripciones y pagos

La integración Wompi crea órdenes usando el precio configurado del plan, firma la referencia en el servidor y solo activa la suscripción cuando recibe el webhook firmado. Configura `PROSSOR_WOMPI_ENV=test`, `PROSSOR_WOMPI_PUBLIC_KEY`, `PROSSOR_WOMPI_INTEGRITY_SECRET` y `PROSSOR_WOMPI_EVENT_SECRET` en PHP. Registra `https://tu-dominio/pagos/wompi-webhook.php` en el panel Wompi y prueba primero en sandbox. La URL debe ser HTTPS y accesible desde Wompi. La respuesta de retorno del navegador nunca activa acceso. Sin esas credenciales se mantiene la solicitud pendiente sin cobro. Los precios existentes no se alteran.

Para producción configura contraseña de MySQL, HTTPS, respaldos, errores no verbosos y revisa privacidad/consentimiento para datos de estudiantes.
