<?php
declare(strict_types=1);

/**
 * Credenciales del primer usuario admin (solo para tabla `admins` vacía).
 *
 * Uso:
 * 1. Copia este archivo como `admin_bootstrap.php` en la misma carpeta.
 * 2. Pon un correo real y una contraseña fuerte (se hashea con bcrypt al insertar).
 * 3. No subas `admin_bootstrap.php` al repositorio (.gitignore).
 * 4. Tras el primer login, borra el archivo del disco (local o servidor).
 *
 * En producción: créalo en el hosting por FTP o administrador de archivos; el
 * workflow de deploy no sube este archivo desde Git.
 */
return [
    "email" => "admin@tu-dominio.com",
    "password" => "CAMBIA_ESTA_CLAVE",
];
