<?php
declare(strict_types=1);

/**
 * GET /api/v1/agenda/slots.php
 * Query: service_id (o agenda_service), date (o agenda_date, Y-m-d).
 */

require_once __DIR__ . "/../../bootstrap.php";

api_require_method("GET");
$conn = api_bootstrap_agenda();

$result = agenda_service_get_slots($conn, $_GET);

if (!$result["ok"]) {
    $err = (string)($result["error"] ?? "error");
    $status = $err === "feature_disabled" ? 403 : 400;
    api_json_error($err, $status);
}

api_json_ok($result["data"]);
