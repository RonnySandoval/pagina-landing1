<?php
declare(strict_types=1);

/**
 * POST /api/v1/admin/auth/password-reset.php
 * token, new_password, confirm_password (o new_admin_password / confirm_admin_password).
 */

require_once __DIR__ . "/../../../bootstrap.php";

api_require_method("POST");
$conn = api_bootstrap_admin();

$input = api_read_input();
$result = admin_service_reset_password(
    $conn,
    (string)($input["token"] ?? $input["reset_token"] ?? ""),
    (string)($input["new_password"] ?? $input["new_admin_password"] ?? ""),
    (string)($input["confirm_password"] ?? $input["confirm_admin_password"] ?? "")
);

if (!$result["ok"]) {
    api_json_error(
        (string)($result["error"] ?? "reset_failed"),
        400,
        ["message" => (string)($result["message"] ?? "")]
    );
}

api_json_ok(["reset" => true]);
