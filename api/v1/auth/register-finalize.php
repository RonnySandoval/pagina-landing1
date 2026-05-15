<?php
declare(strict_types=1);

/**
 * POST /api/v1/auth/register-finalize.php
 * Crea cuenta sin correo de verificación (sesión client_reg_pending previa).
 */

require_once __DIR__ . "/../../bootstrap.php";

api_require_method("POST");
$conn = api_bootstrap_client();
client_session_start();

$result = client_service_register_finalize_no_mail($conn);
if (!$result["ok"]) {
    api_json_error(
        (string)($result["error"] ?? "finalize_failed"),
        400,
        ["message" => (string)($result["message"] ?? "")]
    );
}

api_json_ok(["user" => $result["user"]], 201);
