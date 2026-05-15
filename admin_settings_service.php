<?php
declare(strict_types=1);

require_once __DIR__ . "/site_settings_lib.php";

/**
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string, message?: string}
 */
function admin_settings_service_get(mysqli $conn): array
{
    $row = site_settings_get($conn);
    if ($row === null) {
        return ["ok" => false, "error" => "not_found"];
    }

    return ["ok" => true, "data" => site_settings_format_for_api($row)];
}

/**
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string, message?: string}
 */
function admin_settings_service_update(mysqli $conn, array $input): array
{
    $result = site_settings_update($conn, $input);
    if (!$result["ok"]) {
        return ["ok" => false, "error" => (string)($result["error"] ?? "update_failed")];
    }

    return [
        "ok" => true,
        "data" => site_settings_format_for_api($result["settings"]),
    ];
}

/**
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string, message?: string}
 */
function admin_settings_service_update_logo(
    mysqli $conn,
    array $file,
    string $currentLogoPath,
    bool $removeLogo
): array {
    $result = site_settings_update_logo($conn, $file, $currentLogoPath, $removeLogo);
    if (!$result["ok"]) {
        $out = ["ok" => false, "error" => (string)($result["error"] ?? "logo_failed")];
        if (!empty($result["message"])) {
            $out["message"] = (string)$result["message"];
        }
        return $out;
    }

    return [
        "ok" => true,
        "data" => site_settings_format_for_api($result["settings"]),
    ];
}

/**
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string}
 */
function admin_settings_service_set_agenda_display(mysqli $conn, bool $showNames): array
{
    $result = site_settings_set_agenda_show_expert_names($conn, $showNames);
    if (!$result["ok"]) {
        return ["ok" => false, "error" => (string)($result["error"] ?? "update_failed")];
    }

    return [
        "ok" => true,
        "data" => site_settings_format_for_api($result["settings"]),
    ];
}
