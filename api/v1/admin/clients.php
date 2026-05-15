<?php
declare(strict_types=1);

/**
 * GET    /api/v1/admin/clients.php — lista de clientes del portal.
 * GET    /api/v1/admin/clients.php?id= — detalle.
 * DELETE /api/v1/admin/clients.php?id= — eliminar cuenta.
 */

require_once __DIR__ . "/../../bootstrap.php";

$method = strtoupper((string)($_SERVER["REQUEST_METHOD"] ?? "GET"));
$conn = api_bootstrap_admin_portal();
api_require_admin_session($conn);

$clientId = (int)($_GET["id"] ?? 0);

if ($method === "GET") {
    if ($clientId > 0) {
        $result = admin_clients_service_get($conn, $clientId);
    } else {
        $result = admin_clients_service_list($conn);
    }
    if (!$result["ok"]) {
        $err = (string)($result["error"] ?? "error");
        api_json_error($err, $err === "not_found" ? 404 : 400);
    }
    api_json_ok($result["data"]);
}

if ($method === "DELETE" && $clientId > 0) {
    $result = admin_clients_service_delete($conn, $clientId);
    if (!$result["ok"]) {
        $err = (string)($result["error"] ?? "delete_failed");
        api_json_error($err, $err === "not_found" ? 404 : 400);
    }
    api_json_ok($result["data"]);
}

api_json_error("method_not_allowed", 405);
