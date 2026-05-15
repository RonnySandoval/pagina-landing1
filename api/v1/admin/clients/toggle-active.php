<?php
declare(strict_types=1);

/**
 * POST /api/v1/admin/clients/toggle-active.php — client_id.
 */

require_once __DIR__ . "/../../../bootstrap.php";

api_require_method("POST");
$conn = api_bootstrap_admin_portal();
api_require_admin_session($conn);

$input = api_read_input();
$clientId = (int)($input["client_id"] ?? $_GET["client_id"] ?? 0);
$result = admin_clients_service_toggle_active($conn, $clientId);
if (!$result["ok"]) {
    $err = (string)($result["error"] ?? "update_failed");
    api_json_error($err, $err === "not_found" ? 404 : 400);
}

api_json_ok($result["data"]);
