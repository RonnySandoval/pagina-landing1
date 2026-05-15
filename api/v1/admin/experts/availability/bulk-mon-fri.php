<?php
declare(strict_types=1);

/**
 * POST /api/v1/admin/experts/availability/bulk-mon-fri.php
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
$result = experts_admin_bulk_mon_fri_all($conn, $input);
if (!$result["ok"]) {
    api_json_error((string)($result["error"] ?? "update_failed"), 400);
}

api_json_ok([
    "experts_updated" => (int)($result["experts_updated"] ?? 0),
]);
