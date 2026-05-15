<?php
declare(strict_types=1);

/**
 * GET    /api/v1/admin/services.php — lista con galería.
 * GET    /api/v1/admin/services.php?id= — detalle.
 * POST   /api/v1/admin/services.php — crear (JSON; imagen aparte en services/image.php).
 * PUT    /api/v1/admin/services.php?id= — actualizar campos (JSON).
 * DELETE /api/v1/admin/services.php?id= — eliminar servicio.
 */

require_once __DIR__ . "/../../bootstrap.php";

$method = strtoupper((string)($_SERVER["REQUEST_METHOD"] ?? "GET"));
if ($method === "PATCH") {
    $_SERVER["REQUEST_METHOD"] = "PUT";
    $method = "PUT";
}

$conn = api_bootstrap_admin_services();
api_require_admin_session($conn);

$serviceId = (int)($_GET["id"] ?? 0);

if ($method === "GET") {
    if ($serviceId > 0) {
        $result = admin_services_service_get($conn, $serviceId);
    } else {
        $result = admin_services_service_list($conn);
    }
    if (!$result["ok"]) {
        $err = (string)($result["error"] ?? "error");
        api_json_error($err, $err === "not_found" ? 404 : 400);
    }
    api_json_ok($result["data"]);
}

if ($method === "POST") {
    $input = api_read_input();
    $result = admin_services_service_create($conn, $input);
    if (!$result["ok"]) {
        $err = (string)($result["error"] ?? "create_failed");
        $extra = [];
        if (!empty($result["message"])) {
            $extra["message"] = (string)$result["message"];
        }
        api_json_error($err, 400, $extra);
    }
    api_json_ok($result["data"], 201);
}

if ($method === "PUT" && $serviceId > 0) {
    $input = api_read_input();
    $result = admin_services_service_update($conn, $serviceId, $input);
    if (!$result["ok"]) {
        $err = (string)($result["error"] ?? "update_failed");
        $extra = [];
        if (!empty($result["message"])) {
            $extra["message"] = (string)$result["message"];
        }
        $status = $err === "not_found" ? 404 : 400;
        api_json_error($err, $status, $extra);
    }
    api_json_ok($result["data"]);
}

if ($method === "DELETE" && $serviceId > 0) {
    $result = admin_services_service_delete($conn, $serviceId);
    if (!$result["ok"]) {
        $err = (string)($result["error"] ?? "delete_failed");
        api_json_error($err, $err === "not_found" ? 404 : 400);
    }
    api_json_ok($result["data"]);
}

api_json_error("method_not_allowed", 405);
