<?php
declare(strict_types=1);

/**
 * POST /api/v1/admin/auth/login.php — email + password.
 */

require_once __DIR__ . "/../../../bootstrap.php";

api_require_method("POST");
$conn = api_bootstrap_admin();
admin_session_start();

$input = api_read_input();
$result = admin_service_login(
    $conn,
    (string)($input["email"] ?? ""),
    (string)($input["password"] ?? "")
);

if (!$result["ok"]) {
    api_json_error(
        (string)($result["error"] ?? "invalid_credentials"),
        401,
        ["message" => (string)($result["message"] ?? "")]
    );
}

api_json_ok(["user" => $result["user"]]);
