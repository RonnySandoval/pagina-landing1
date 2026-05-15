<?php
declare(strict_types=1);

/**
 * GET /api/v1/client/inbox-poll.php — contadores para polling (misma lógica que index.php?client_inbox_poll=1).
 */

require_once __DIR__ . "/../../bootstrap.php";

api_require_method("GET");
$conn = api_bootstrap_client();
$user = api_require_client_session($conn);

$result = client_service_poll_inbox(
    $conn,
    (int)$user["id"],
    strtolower(trim($user["email"]))
);

if (!$result["ok"]) {
    $err = (string)($result["error"] ?? "error");
    api_json_error($err, $err === "feature_disabled" ? 403 : 400);
}

api_json_ok($result["data"]);
