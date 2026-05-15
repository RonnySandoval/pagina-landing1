<?php
declare(strict_types=1);

/**
 * POST /api/v1/admin/messages/read.php
 * message_id, read (true|false, default true).
 */

require_once __DIR__ . "/../../../bootstrap.php";

api_require_method("POST");
$conn = api_bootstrap_admin_messages();
api_require_admin_session($conn);

$input = api_read_input();
$messageId = (int)($input["message_id"] ?? 0);
$read = !array_key_exists("read", $input) || filter_var($input["read"], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;

$result = admin_messages_service_set_read($conn, $messageId, ["read" => $read]);
if (!$result["ok"]) {
    $err = (string)($result["error"] ?? "error");
    if ($err === "feature_disabled") {
        api_json_error($err, 403);
    }
    api_json_error($err !== "" ? $err : "update_failed", 400);
}

api_json_ok($result["data"]);
