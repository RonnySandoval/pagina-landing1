<?php
declare(strict_types=1);

/**
 * GET /api/v1/admin/messages.php
 *   Lista bandeja (query: limit, default 100).
 * GET /api/v1/admin/messages.php?id=123
 *   Un mensaje con respuestas.
 */

require_once __DIR__ . "/../../bootstrap.php";

api_require_method("GET");
$conn = api_bootstrap_admin_messages();
api_require_admin_session($conn);

$messageId = (int)($_GET["id"] ?? 0);
if ($messageId > 0) {
    $result = admin_messages_service_get($conn, $messageId);
} else {
    $limit = (int)($_GET["limit"] ?? 100);
    $result = admin_messages_service_list($conn, $limit);
}

if (!$result["ok"]) {
    $err = (string)($result["error"] ?? "error");
    $status = $err === "feature_disabled" ? 403 : ($err === "not_found" ? 404 : 400);
    api_json_error($err, $status);
}

api_json_ok($result["data"]);
