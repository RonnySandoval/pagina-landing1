<?php
declare(strict_types=1);

/**
 * Bootstrap inicial de admin (solo servidor).
 *
 * Uso:
 * 1. Copia este archivo como `admin_bootstrap.php`.
 * 2. Define credenciales seguras.
 * 3. NO subas `admin_bootstrap.php` al repositorio.
 *
 * Nota:
 * - Se usa solo cuando la tabla `admins` esta vacia.
 * - Luego puedes borrar este archivo del servidor si quieres.
 */
return [
    "email" => "admin@tu-dominio.com",
    "password" => "CAMBIA_ESTA_CLAVE",
];
