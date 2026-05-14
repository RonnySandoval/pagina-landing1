<?php
declare(strict_types=1);

/**
 * Configuración opcional de la aplicación (copiar como app_config.php).
 *
 * app_config.php está en .gitignore: cada entorno puede tener el suyo.
 * Mapa de archivos/URLs (local y servidor): ver app_urls.php al inicio del archivo.
 */

return [
    /**
     * URL pública de esta instalación, sin barra final (recomendado).
     * Si incluyes "/" al final de la ruta, el código la elimina al resolver la base.
     * Ejemplos:
     * - Local:      "http://localhost/pag-nombre"
     * - Producción: "https://tudominio.com" o "https://tudominio.com/subcarpeta"
     *
     * Déjalo vacío ("") para detectar automáticamente en cada petición (lo habitual en local).
     * Úsalo en servidor si hay proxy, SSL terminado delante, o la detección falla.
     */
    "public_base_url" => "",

    /**
     * Si es true, cada petición escribe UNA línea en el log de errores de PHP
     * (error_log / log del hosting) con public_base_url calculada y valores
     * de HTTP_HOST, SCRIPT_NAME, REQUEST_URI, HTTPS, X-Forwarded-Proto.
     * Úsalo solo para depurar rutas; vuelve a false cuando termines.
     */
    "log_public_base_url" => false,

    /**
     * Módulos por instalación (cada landing puede usar su propio app_config.php).
     * Omitir `features` o una clave concreta = ese módulo activo (true).
     *
     * - contact_whatsapp: botón «Escribir por WhatsApp» en el formulario de contacto.
     * - client_inbox: bandeja «Mis mensajes» y envíos desde el área cliente (send.php con return_anchor=area-cliente).
     * - admin_inbox: acordeón «Mensajes» en admin (contact_messages + respuestas).
     * - admin_whatsapp_clicks: acordeón «Clics WhatsApp» en admin.
     */
    "features" => [
        "contact_whatsapp" => true,
        "client_inbox" => true,
        "admin_inbox" => true,
        "admin_whatsapp_clicks" => true,
    ],
];
