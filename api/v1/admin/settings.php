<?php
declare(strict_types=1);

/**
 * GET  /api/v1/admin/settings.php — configuración general del sitio.
 * PUT  /api/v1/admin/settings.php — actualizar textos y WhatsApp (JSON).
 * PATCH aceptado igual que PUT.
 */

require_once __DIR__ . "/../../bootstrap.php";

$method = strtoupper((string)($_SERVER["REQUEST_METHOD"] ?? "GET"));
if ($method === "PATCH") {
    $_SERVER["REQUEST_METHOD"] = "PUT";
    $method = "PUT";
}

$conn = api_bootstrap_admin_settings();
api_require_admin_session($conn);

if ($method === "GET") {
    $result = admin_settings_service_get($conn);
    if (!$result["ok"]) {
        $err = (string)($result["error"] ?? "error");
        api_json_error($err, $err === "not_found" ? 404 : 400);
    }
    api_json_ok($result["data"]);
}

if ($method === "PUT") {
    $input = api_read_input();
    $result = admin_settings_service_update($conn, $input);
    if (!$result["ok"]) {
        $err = (string)($result["error"] ?? "update_failed");
        $extra = [];
        if (!empty($result["message"])) {
            $extra["message"] = (string)$result["message"];
        }
        api_json_error($err, 400, $extra);
    }
    api_json_ok($result["data"]);
}

api_json_error("method_not_allowed", 405);
