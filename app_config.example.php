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
     * URL pública de esta instalación, sin barra final.
     * Ejemplos:
     * - Local:     "http://localhost/pagina1"
     * - Producción: "https://tudominio.com" o "https://tudominio.com/subcarpeta"
     *
     * Déjalo vacío para detectar automáticamente desde cada petición (normal en local).
     * Úsalo en servidor si hay proxy, SSL terminado delante, o la detección falla.
     */
    "public_base_url" => "",
];
