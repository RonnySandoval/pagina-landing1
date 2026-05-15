<?php
declare(strict_types=1);

/**
 * POST /api/v1/admin/settings/logo.php
 * multipart: logo_image_file (opcional), remove_logo (true|false), current_logo_image_path (opcional).
 */

require_once __DIR__ . "/../../../bootstrap.php";

api_require_method("POST");
$conn = api_bootstrap_admin_settings();
api_require_admin_session($conn);

$input = api_read_input();
$currentPath = trim((string)($input["current_logo_image_path"] ?? ""));
$remove = filter_var($input["remove_logo"] ?? $input["remove_logo_image"] ?? false, FILTER_VALIDATE_BOOLEAN);

$file = $_FILES["logo_image_file"] ?? [];
if ($file === [] && isset($_FILES["file"])) {
    $file = $_FILES["file"];
}

$row = site_settings_get($conn);
if ($currentPath === "" && is_array($row)) {
    $currentPath = trim((string)($row["logo_image_path"] ?? ""));
}

$result = admin_settings_service_update_logo($conn, is_array($file) ? $file : [], $currentPath, $remove);
if (!$result["ok"]) {
    $err = (string)($result["error"] ?? "logo_failed");
    $extra = [];
    if (!empty($result["message"])) {
        $extra["message"] = (string)$result["message"];
    }
    api_json_error($err, 400, $extra);
}

api_json_ok($result["data"]);
