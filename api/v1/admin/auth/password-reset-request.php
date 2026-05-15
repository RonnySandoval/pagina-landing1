<?php
declare(strict_types=1);

/**
 * POST /api/v1/admin/auth/password-reset-request.php — email (respuesta genérica siempre).
 */

require_once __DIR__ . "/../../../bootstrap.php";

api_require_method("POST");
$conn = api_bootstrap_admin();

$input = api_read_input();
$result = admin_service_request_password_reset($conn, (string)($input["email"] ?? $input["reset_email"] ?? ""));

api_json_ok(["message" => $result["message"]]);
