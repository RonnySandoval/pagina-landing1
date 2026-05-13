<?php
declare(strict_types=1);

/**
 * Compatibilidad: el área de cliente es la misma web (index) con sesión.
 * @see index.php#area-cliente
 */
require_once __DIR__ . "/app_urls.php";

header("Location: " . app_client_portal_url(), true, 302);
exit;
