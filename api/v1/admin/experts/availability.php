<?php
declare(strict_types=1);

/**
 * POST   /api/v1/admin/experts/availability.php — franja semanal.
 * DELETE /api/v1/admin/experts/availability.php?id=&expert_id=
 */

require_once __DIR__ . "/../../../bootstrap.php";

$method = strtoupper((string)($_SERVER["REQUEST_METHOD"] ?? "GET"));
$conn = api_bootstrap_admin_experts();
api_require_admin_session($conn);

$deny = admin_experts_require_agenda();
if ($deny !== null) {
    api_json_error("feature_disabled", 403);
}

$availabilityId = (int)($_GET["id"] ?? 0);

if ($method === "POST") {
    $input = api_read_input();
    $expertId = (int)($input["expert_id"] ?? 0);
    $weekday = (int)($input["weekday"] ?? -1);
    $result = experts_admin_add_weekly_availability(
        $conn,
        $expertId,
        $weekday,
        trim((string)($input["start_time"] ?? "")),
        trim((string)($input["end_time"] ?? ""))
    );
    if (!$result["ok"]) {
        $err = (string)($result["error"] ?? "error");
        api_json_error($err, $err === "not_found" ? 404 : 400);
    }
    api_json_ok($result, 201);
}

if ($method === "DELETE") {
    $input = api_read_input();
    $expertId = (int)($input["expert_id"] ?? $_GET["expert_id"] ?? 0);
    $result = experts_admin_delete_weekly_availability($conn, $expertId, $availabilityId);
    if (!$result["ok"]) {
        api_json_error((string)($result["error"] ?? "delete_failed"), 400);
    }
    api_json_ok(["availability_id" => $availabilityId]);
}

api_json_error("method_not_allowed", 405);
