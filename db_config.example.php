<?php
declare(strict_types=1);

/**
 * Plantilla de configuración de base de datos.
 *
 * Cómo usarlo:
 * 1. Copia este archivo como `db_config.php` en la misma carpeta.
 * 2. Reemplaza los valores con los reales del entorno (XAMPP local o hosting).
 * 3. Nunca subas `db_config.php` al control de versiones (ya está en .gitignore).
 */

return [
    "host" => "127.0.0.1",
    "user" => "root",
    "password" => "",
    "database" => "web_personal",
];
