<?php
declare(strict_types=1);

/**
 * POST /api/v1/admin/services/image.php
 * multipart: service_id, image_file (o file).
 */

require_once __DIR__ . "/../../../bootstrap.php";

api_require_method("POST");
$conn = api_bootstrap_admin_services();
api_require_admin_session($conn);

$input = api_read_input();
$serviceId = (int)($input["service_id"] ?? $_GET["service_id"] ?? 0);
$file = $_FILES["image_file"] ?? $_FILES["file"] ?? [];

$result = admin_services_service_update_image($conn, $serviceId, is_array($file) ? $file : []);
if (!$result["ok"]) {
    $err = (string)($result["error"] ?? "update_failed");
    $extra = [];
    if (!empty($result["message"])) {
        $extra["message"] = (string)$result["message"];
    }
    $status = $err === "not_found" ? 404 : 400;
    api_json_error($err, $status, $extra);
}

api_json_ok($result["data"]);
