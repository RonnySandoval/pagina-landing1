<?php
declare(strict_types=1);

/**
 * POST /api/v1/admin/services/gallery/reorder.php
 * service_id, ordered_ids (array de enteros).
 */

require_once __DIR__ . "/../../../../bootstrap.php";

api_require_method("POST");
$conn = api_bootstrap_admin_services();
api_require_admin_session($conn);

$input = api_read_input();
$serviceId = (int)($input["service_id"] ?? 0);
$orderedRaw = $input["ordered_ids"] ?? [];
$orderedIds = [];
if (is_array($orderedRaw)) {
    $orderedIds = array_values(array_map("intval", $orderedRaw));
}

$result = admin_services_service_gallery_reorder($conn, $serviceId, $orderedIds);
if (!$result["ok"]) {
    $err = (string)($result["error"] ?? "reorder_failed");
    api_json_error($err, $err === "not_found" ? 404 : 400);
}

api_json_ok($result["data"]);
