<?php
declare(strict_types=1);

/**
 * POST /api/v1/admin/settings/agenda-display.php
 * agenda_show_expert_names (bool).
 */

require_once __DIR__ . "/../../../bootstrap.php";

api_require_method("POST");
$conn = api_bootstrap_admin_settings();
api_require_admin_session($conn);

$input = api_read_input();
$show = filter_var($input["agenda_show_expert_names"] ?? false, FILTER_VALIDATE_BOOLEAN);

$result = admin_settings_service_set_agenda_display($conn, $show);
if (!$result["ok"]) {
    api_json_error((string)($result["error"] ?? "update_failed"), 400);
}

api_json_ok($result["data"]);
