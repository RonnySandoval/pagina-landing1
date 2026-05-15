<?php
declare(strict_types=1);

/**
 * GET /api/v1/auth/session.php — estado de sesión del portal cliente.
 */

require_once __DIR__ . "/../../bootstrap.php";

api_require_method("GET");
$conn = api_bootstrap_client();
client_session_start();

$result = client_service_session_status($conn);
api_json_ok($result);
