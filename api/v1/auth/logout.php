<?php
declare(strict_types=1);

/**
 * POST /api/v1/auth/logout.php
 */

require_once __DIR__ . "/../../bootstrap.php";

api_require_method("POST");
api_bootstrap_client();
client_session_start();
client_service_logout();

api_json_ok(["logged_out" => true]);
