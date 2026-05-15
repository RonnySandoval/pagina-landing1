<?php
declare(strict_types=1);

/**
 * POST /api/v1/admin/whatsapp-clicks/read-all.php
 * read (true|false, default true).
 */

require_once __DIR__ . "/../../../bootstrap.php";

api_require_method("POST");
$conn = api_bootstrap_admin_portal();
api_require_admin_session($conn);

$input = api_read_input();
$read = !array_key_exists("read", $input) || filter_var($input["read"], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;

$result = admin_whatsapp_service_set_all_read($conn, $read);
if (!$result["ok"]) {
    $err = (string)($result["error"] ?? "error");
    api_json_error($err, $err === "feature_disabled" ? 403 : 400);
}

api_json_ok($result["data"]);
