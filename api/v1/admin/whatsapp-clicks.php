<?php
declare(strict_types=1);

/**
 * GET    /api/v1/admin/whatsapp-clicks.php — lista + counts.
 * GET    /api/v1/admin/whatsapp-clicks.php?id= — detalle.
 * DELETE /api/v1/admin/whatsapp-clicks.php?id= — borrar.
 */

require_once __DIR__ . "/../../bootstrap.php";

$method = strtoupper((string)($_SERVER["REQUEST_METHOD"] ?? "GET"));
$conn = api_bootstrap_admin_portal();
api_require_admin_session($conn);

$clickId = (int)($_GET["id"] ?? 0);

if ($method === "GET") {
    if ($clickId > 0) {
        $result = admin_whatsapp_service_get($conn, $clickId);
    } else {
        $limit = (int)($_GET["limit"] ?? 100);
        $result = admin_whatsapp_service_list($conn, $limit);
    }
    if (!$result["ok"]) {
        $err = (string)($result["error"] ?? "error");
        $status = $err === "feature_disabled" ? 403 : ($err === "not_found" ? 404 : 400);
        api_json_error($err, $status);
    }
    api_json_ok($result["data"]);
}

if ($method === "DELETE" && $clickId > 0) {
    $result = admin_whatsapp_service_delete($conn, $clickId);
    if (!$result["ok"]) {
        $err = (string)($result["error"] ?? "delete_failed");
        $status = $err === "feature_disabled" ? 403 : ($err === "not_found" ? 404 : 400);
        api_json_error($err, $status);
    }
    api_json_ok($result["data"]);
}

api_json_error("method_not_allowed", 405);
