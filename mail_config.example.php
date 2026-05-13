<?php
declare(strict_types=1);

/**
 * Plantilla para `mail_config.php` (copiar al mismo directorio y rellenar).
 * No versiones el archivo real: está en .gitignore y el deploy FTP lo excluye;
 * en el servidor créalo manualmente junto a `index.php`.
 *
 * Cuenta SMTP real (Gmail con contraseña de aplicación u otro proveedor).
 *
 * Flujo de correos en este proyecto:
 * 1) El visitante envía el formulario (incluye su correo personal).
 * 2) El servidor envía un correo AL correo receptor del sitio (admin → Configuración general),
 *    usando esta cuenta SMTP. Reply-To = correo del visitante (para responderle con un clic).
 * 3) El admin puede responder desde el panel (Mensajes → Responder), sin abrir Outlook/Gmail.
 * 4) El servidor envía AL correo personal del visitante con la misma cuenta SMTP.
 *    Reply-To = correo receptor del sitio (si el visitante pulsa "Responder", te escribe ahí).
 *
 * Remitente SMTP (From / MAIL FROM): usa from_email; si lo dejas vacío pero username es un
 * correo válido, se usa username (típico en Gmail: mismo correo en ambos).
 * Con smtp.gmail.com, username y ese remitente deben ser el mismo correo.
 * use_smtp debe ser true para que 2) y 4) salgan por SMTP (en XAMPP mail() suele fallar).
 *
 * from_name: nombre visible; si está vacío, en formulario se usa "Nombre persona" del admin.
 *
 * https://support.google.com/accounts/answer/185833 (contraseña de aplicación Gmail)
 */

return [
    // true = usar SMTP; false = solo PHP mail() (en XAMPP suele no enviar nada)
    "use_smtp" => true,

    "host" => "smtp.gmail.com",
    "port" => 587,
    // "tls" = STARTTLS (puerto 587), "ssl" = conexión SSL (p. ej. 465)
    "encryption" => "tls",

    "username" => "tu_correo@gmail.com",
    "password" => "tu_contraseña_de_aplicacion",

    // Opcional si username ya es ese correo (el código puede reutilizarlo).
    "from_email" => "tu_correo@gmail.com",
    "from_name" => "Formulario web",

    // Solo para depuración local: escribe errores SMTP en mail_debug.log
    // "debug" => true,
    // "debug_log" => __DIR__ . "/mail_debug.log",
];
