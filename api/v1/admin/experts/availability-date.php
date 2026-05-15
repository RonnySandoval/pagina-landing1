<?php
declare(strict_types=1);

/**
 * POST   /api/v1/admin/experts/availability-date.php — excepción por fecha.
 * DELETE /api/v1/admin/experts/availability-date.php?id=&expert_id=
 */

require_once __DIR__ . "/../../../bootstrap.php";

$method = strtoupper((string)($_SERVER["REQUEST_METHOD"] ?? "GET"));
$conn = api_bootstrap_admin_experts();
api_require_admin_session($conn);

$deny = admin_experts_require_agenda();
if ($deny !== null) {
    api_json_error("feature_disabled", 403);
}

$avDateId = (int)($_GET["id"] ?? 0);

if ($method === "POST") {
    $input = api_read_input();
    $expertId = (int)($input["expert_id"] ?? 0);
    $result = experts_admin_add_date_exception($conn, $expertId, $input);
    if (!$result["ok"]) {
        $err = (string)($result["error"] ?? "error");
        api_json_error($err, $err === "not_found" ? 404 : 400);
    }
    api_json_ok(["expert_id" => $expertId], 201);
}

if ($method === "DELETE") {
    $input = api_read_input();
    $expertId = (int)($input["expert_id"] ?? $_GET["expert_id"] ?? 0);
    $result = experts_admin_delete_date_exception($conn, $expertId, $avDateId);
    if (!$result["ok"]) {
        api_json_error((string)($result["error"] ?? "delete_failed"), 400);
    }
    api_json_ok(["av_date_id" => $avDateId]);
}

api_json_error("method_not_allowed", 405);
