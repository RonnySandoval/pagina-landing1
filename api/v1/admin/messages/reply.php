<?php
declare(strict_types=1);

/**
 * POST /api/v1/admin/messages/reply.php
 * message_id, body (o reply_body).
 */

require_once __DIR__ . "/../../../bootstrap.php";

api_require_method("POST");
$conn = api_bootstrap_admin_messages();
api_require_admin_session($conn);

$input = api_read_input();
$messageId = (int)($input["message_id"] ?? 0);
$body = trim((string)($input["body"] ?? $input["reply_body"] ?? ""));

$result = admin_messages_service_reply($conn, $messageId, $body);
if (!$result["ok"]) {
    $err = (string)($result["error"] ?? "reply_failed");
    $extra = [];
    if (!empty($result["message"])) {
        $extra["message"] = (string)$result["message"];
    }
    $status = $err === "feature_disabled" ? 403 : ($err === "not_found" ? 404 : 400);
    api_json_error($err, $status, $extra);
}

api_json_ok($result["data"], 201);
