<?php
declare(strict_types=1);

/**
 * POST /api/v1/auth/login.php — email + password (JSON o form).
 */

require_once __DIR__ . "/../../bootstrap.php";

api_require_method("POST");
$conn = api_bootstrap_client();
client_session_start();

$input = api_read_input();
$email = (string)($input["email"] ?? "");
$password = (string)($input["password"] ?? "");

$result = client_service_login($conn, $email, $password);
if (!$result["ok"]) {
    api_json_error(
        (string)($result["error"] ?? "invalid_credentials"),
        401,
        ["message" => (string)($result["message"] ?? "")]
    );
}

api_json_ok(["user" => $result["user"]]);
