<?php
declare(strict_types=1);

/**
 * POST /api/v1/admin/messages/delete.php — message_id
 */

require_once __DIR__ . "/../../../bootstrap.php";

api_require_method("POST");
$conn = api_bootstrap_admin_messages();
api_require_admin_session($conn);

$input = api_read_input();
$messageId = (int)($input["message_id"] ?? 0);

$result = admin_messages_service_delete($conn, $messageId);
if (!$result["ok"]) {
    $err = (string)($result["error"] ?? "error");
    api_json_error($err, $err === "feature_disabled" ? 403 : 400);
}

api_json_ok($result["data"]);
