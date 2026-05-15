<?php
declare(strict_types=1);

/**
 * POST /api/v1/auth/register-confirm.php
 * Activa cuenta con token del correo. Campo: token (o verify_token).
 */

require_once __DIR__ . "/../../bootstrap.php";

api_require_method("POST");
$conn = api_bootstrap_client();
client_session_start();

$input = api_read_input();
$token = trim((string)($input["token"] ?? $input["verify_token"] ?? ""));

$result = client_service_confirm_registration($conn, $token);
if (!$result["ok"]) {
    api_json_error(
        (string)($result["error"] ?? "invalid_token"),
        400,
        ["message" => (string)($result["message"] ?? "")]
    );
}

api_json_ok(["user" => $result["user"]]);
