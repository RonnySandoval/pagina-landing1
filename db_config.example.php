<?php
declare(strict_types=1);

/**
 * Plantilla de configuración de base de datos.
 *
 * Cómo usarlo:
 * 1. Copia este archivo como `db_config.php` en la misma carpeta.
 * 2. Reemplaza los valores con los reales del entorno.
 * 3. Nunca subas `db_config.php` al control de versiones (ya está en .gitignore).
 *
 * Valores típicos:
 * - Local (XAMPP): host `127.0.0.1`, usuario `root`, clave vacía o la que
 *   definas en MySQL, nombre de BD la que creaste (p. ej. `web_personal`).
 * - Hosting (InfinityFree u otro): host, usuario, clave y nombre de BD los
 *   indica el panel (el host remoto casi nunca es `127.0.0.1`). Tras el deploy
 *   por FTP debes crear `db_config.php` en el servidor a mano; el CI no lo sube.
 */

return [
    "host" => "127.0.0.1",
    "user" => "root",
    "password" => "",
    "database" => "web_personal",
];
