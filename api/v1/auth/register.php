<?php
declare(strict_types=1);

/**
 * POST /api/v1/auth/register.php
 * Campos: email, password, password_confirm, display_name (o reg_* en formularios legacy).
 */

require_once __DIR__ . "/../../bootstrap.php";

api_require_method("POST");
$conn = api_bootstrap_client();
client_session_start();

$result = client_service_register($conn, api_read_input());

if (!$result["ok"]) {
    $err = (string)($result["error"] ?? "registration_rejected");
    $status = !empty($result["need_email_choice"]) ? 503 : 400;
    $extra = ["message" => (string)($result["message"] ?? "")];
    if (!empty($result["need_email_choice"])) {
        $extra["need_email_choice"] = true;
    }
    api_json_error($err, $status, $extra);
}

api_json_ok([
    "awaiting_verification" => true,
    "email" => (string)($result["email"] ?? ""),
], 202);
