<?php
declare(strict_types=1);

/**
 * Copia este archivo como mail_config.php y rellena datos REALES de tu proveedor.
 * No inventes usuario/clave: deben ser de una cuenta que permita SMTP (Gmail con
 * contraseña de aplicación, Outlook, hosting propio, etc.).
 *
 * El correo DONDE LLEGAN los mensajes del formulario se define en el panel admin
 * ("Correo receptor del formulario"); send.php ya usa ese valor como destino (To).
 * Nombre visible del remitente: si rellenas from_name aquí, ese texto gana; si lo
 * dejas vacío, se usa "Nombre persona" del admin. La "Marca" del sitio no se usa
 * en correos (evita nombres tipo carpeta del proyecto en el remitente).
 * Aquí configuras la cuenta que ENVÍA por SMTP (From / login).
 *
 * Si en el buzón del destinatario ves algo como "Pagina1, Ronny" aunque from_name sea
 * otro texto, Gmail suele mezclar el nombre de tu CUENTA DE GOOGLE (organización +
 * nombre) con la cabecera. Revisa: https://myaccount.google.com/personal-info
 * (Nombre, y si aparece "Pagina1" como empresa u organización, corrígelo o bórralo).
 * Con debug=true, send.php escribe en mail_debug.log la línea From exacta que envía PHP.
 *
 * Privacidad: no subas mail_config.php a Git público. En este proyecto .htaccess
 * impide abrir mail_config.php y db.php por URL; guarda igualmente el archivo fuera
 * de copias públicas si puedes.
 *
 * Gmail: "Contraseña de aplicaciones" (no la clave de la cuenta).
 * https://support.google.com/accounts/answer/185833
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

    // Debe coincidir con la cuenta SMTP en muchos proveedores
    "from_email" => "tu_correo@gmail.com",
    "from_name" => "Formulario web",

    // Solo para depuración local: escribe errores SMTP en mail_debug.log
    // "debug" => true,
    // "debug_log" => __DIR__ . "/mail_debug.log",
];
