<?php
declare(strict_types=1);

/**
 * GET    /api/v1/admin/experts.php — lista de expertos.
 * GET    /api/v1/admin/experts.php?id= — detalle (?include=schedule&week=YYYY-MM-DD).
 * POST   /api/v1/admin/experts.php — crear.
 * PUT    /api/v1/admin/experts.php?id= — actualizar.
 * DELETE /api/v1/admin/experts.php?id= — eliminar.
 */

require_once __DIR__ . "/../../bootstrap.php";

$method = strtoupper((string)($_SERVER["REQUEST_METHOD"] ?? "GET"));
if ($method === "PATCH") {
    $_SERVER["REQUEST_METHOD"] = "PUT";
    $method = "PUT";
}

$conn = api_bootstrap_admin_experts();
api_require_admin_session($conn);

$expertId = (int)($_GET["id"] ?? 0);
$includeSchedule = isset($_GET["include"]) && str_contains((string)$_GET["include"], "schedule");
$weekStart = trim((string)($_GET["week"] ?? $_GET["week_start"] ?? ""));

if ($method === "GET") {
    if ($expertId > 0) {
        $result = admin_experts_service_get($conn, $expertId, $includeSchedule, $weekStart);
    } else {
        $result = admin_experts_service_list($conn);
    }
    if (!$result["ok"]) {
        $err = (string)($result["error"] ?? "error");
        $status = $err === "feature_disabled" ? 403 : ($err === "not_found" ? 404 : 400);
        api_json_error($err, $status);
    }
    api_json_ok($result["data"]);
}

if ($method === "POST") {
    $input = api_read_input();
    $result = admin_experts_service_create($conn, $input);
    if (!$result["ok"]) {
        $err = (string)($result["error"] ?? "create_failed");
        $status = $err === "feature_disabled" ? 403 : 400;
        api_json_error($err, $status);
    }
    api_json_ok($result["data"], 201);
}

if ($method === "PUT" && $expertId > 0) {
    $input = api_read_input();
    $result = admin_experts_service_update($conn, $expertId, $input);
    if (!$result["ok"]) {
        $err = (string)($result["error"] ?? "update_failed");
        $status = $err === "feature_disabled" ? 403 : ($err === "not_found" ? 404 : 400);
        api_json_error($err, $status);
    }
    api_json_ok($result["data"]);
}

if ($method === "DELETE" && $expertId > 0) {
    $result = admin_experts_service_delete($conn, $expertId);
    if (!$result["ok"]) {
        $err = (string)($result["error"] ?? "delete_failed");
        $status = $err === "feature_disabled" ? 403 : ($err === "not_found" ? 404 : 400);
        api_json_error($err, $status);
    }
    api_json_ok($result["data"]);
}

api_json_error("method_not_allowed", 405);
