<?php
declare(strict_types=1);

/**
 * POST /api/v1/admin/whatsapp-clicks/read.php
 * click_id (o whatsapp_click_id), read (true|false, default true).
 */

require_once __DIR__ . "/../../../bootstrap.php";

api_require_method("POST");
$conn = api_bootstrap_admin_portal();
api_require_admin_session($conn);

$input = api_read_input();
$clickId = (int)($input["click_id"] ?? $input["whatsapp_click_id"] ?? 0);
$read = !array_key_exists("read", $input) || filter_var($input["read"], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;

$result = admin_whatsapp_service_set_read($conn, $clickId, $read);
if (!$result["ok"]) {
    $err = (string)($result["error"] ?? "error");
    $status = $err === "feature_disabled" ? 403 : 400;
    api_json_error($err, $status);
}

api_json_ok($result["data"]);
