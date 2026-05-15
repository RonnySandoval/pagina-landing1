<?php
declare(strict_types=1);

/**
 * GET /api/v1/admin/experts/week-grid.php?expert_id=&week_start=
 */

require_once __DIR__ . "/../../../bootstrap.php";

api_require_method("GET");
$conn = api_bootstrap_admin_experts();
api_require_admin_session($conn);

$expertId = (int)($_GET["expert_id"] ?? $_GET["id"] ?? 0);
$weekStart = trim((string)($_GET["week_start"] ?? $_GET["week"] ?? ""));

$result = admin_experts_service_week_grid($conn, $expertId, $weekStart);
if (!$result["ok"]) {
    $err = (string)($result["error"] ?? "error");
    $status = $err === "feature_disabled" ? 403 : ($err === "not_found" ? 404 : 400);
    api_json_error($err, $status);
}

api_json_ok($result["data"]);
