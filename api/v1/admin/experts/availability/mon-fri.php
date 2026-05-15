<?php
declare(strict_types=1);

/**
 * POST /api/v1/admin/experts/availability/mon-fri.php
 * expert_id, use_defaults, mon_fri_start, mon_fri_end.
 */

require_once __DIR__ . "/../../../../bootstrap.php";

api_require_method("POST");
$conn = api_bootstrap_admin_experts();
api_require_admin_session($conn);

$deny = admin_experts_require_agenda();
if ($deny !== null) {
    api_json_error("feature_disabled", 403);
}

$input = api_read_input();
$expertId = (int)($input["expert_id"] ?? 0);
$result = experts_admin_set_mon_fri_window($conn, $expertId, $input);
if (!$result["ok"]) {
    $err = (string)($result["error"] ?? "update_failed");
    api_json_error($err, $err === "not_found" ? 404 : 400);
}

api_json_ok(["expert_id" => $expertId]);
