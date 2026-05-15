<?php
declare(strict_types=1);

/**
 * GET /api/v1/client/messages.php — bandeja del cliente (requiere sesión).
 * Query opcional: limit (1–200, default 40).
 */

require_once __DIR__ . "/../../bootstrap.php";

api_require_method("GET");
$conn = api_bootstrap_client();
$user = api_require_client_session($conn);

$limit = (int)($_GET["limit"] ?? 40);
$result = client_service_get_inbox(
    $conn,
    (int)$user["id"],
    strtolower(trim($user["email"])),
    $limit
);

if (!$result["ok"]) {
    $err = (string)($result["error"] ?? "error");
    api_json_error($err, $err === "feature_disabled" ? 403 : 400);
}

api_json_ok($result["data"]);
