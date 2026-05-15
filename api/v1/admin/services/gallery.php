<?php
declare(strict_types=1);

/**
 * POST   /api/v1/admin/services/gallery.php — añadir imagen (multipart).
 * PUT    /api/v1/admin/services/gallery.php?id= — metadatos (image_title, image_description).
 * DELETE /api/v1/admin/services/gallery.php?id= — borrar imagen de galería.
 */

require_once __DIR__ . "/../../../bootstrap.php";

$method = strtoupper((string)($_SERVER["REQUEST_METHOD"] ?? "GET"));
if ($method === "PATCH") {
    $_SERVER["REQUEST_METHOD"] = "PUT";
    $method = "PUT";
}

$conn = api_bootstrap_admin_services();
api_require_admin_session($conn);

$galleryId = (int)($_GET["id"] ?? 0);

if ($method === "POST") {
    $input = api_read_input();
    $serviceId = (int)($input["service_id"] ?? $_GET["service_id"] ?? 0);
    $file = $_FILES["image_file"] ?? $_FILES["file"] ?? [];
    $result = admin_services_service_gallery_add($conn, $serviceId, is_array($file) ? $file : []);
    if (!$result["ok"]) {
        $err = (string)($result["error"] ?? "gallery_failed");
        $extra = [];
        if (!empty($result["message"])) {
            $extra["message"] = (string)$result["message"];
        }
        $status = $err === "not_found" ? 404 : 400;
        api_json_error($err, $status, $extra);
    }
    api_json_ok($result["data"], 201);
}

if ($method === "PUT" && $galleryId > 0) {
    $input = api_read_input();
    $title = trim((string)($input["image_title"] ?? $input["title"] ?? ""));
    $description = trim((string)($input["image_description"] ?? $input["description"] ?? ""));
    $result = admin_services_service_gallery_update_meta($conn, $galleryId, $title, $description);
    if (!$result["ok"]) {
        $err = (string)($result["error"] ?? "update_failed");
        api_json_error($err, $err === "not_found" ? 404 : 400);
    }
    api_json_ok($result["data"]);
}

if ($method === "DELETE" && $galleryId > 0) {
    $result = admin_services_service_gallery_delete($conn, $galleryId);
    if (!$result["ok"]) {
        $err = (string)($result["error"] ?? "delete_failed");
        api_json_error($err, $err === "not_found" ? 404 : 400);
    }
    api_json_ok($result["data"]);
}

api_json_error("method_not_allowed", 405);
