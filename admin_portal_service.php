<?php
declare(strict_types=1);

require_once __DIR__ . "/app_urls.php";
require_once __DIR__ . "/admin_clients_lib.php";
require_once __DIR__ . "/admin_whatsapp_lib.php";

function admin_whatsapp_require_feature(): ?array
{
    if (!app_feature_enabled("admin_whatsapp_clicks")) {
        return ["ok" => false, "error" => "feature_disabled"];
    }

    return null;
}

/**
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string}
 */
function admin_clients_service_list(mysqli $conn): array
{
    $items = [];
    foreach (clients_admin_list($conn) as $row) {
        $items[] = clients_admin_format($row);
    }

    return ["ok" => true, "data" => ["clients" => $items]];
}

/**
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string}
 */
function admin_clients_service_get(mysqli $conn, int $clientId): array
{
    $got = clients_admin_get($conn, $clientId);
    if (!$got["ok"]) {
        return ["ok" => false, "error" => (string)($got["error"] ?? "not_found")];
    }

    return ["ok" => true, "data" => clients_admin_format($got["client"])];
}

/**
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string}
 */
function admin_clients_service_delete(mysqli $conn, int $clientId): array
{
    $deleted = clients_admin_delete($conn, $clientId);
    if (!$deleted["ok"]) {
        return ["ok" => false, "error" => (string)($deleted["error"] ?? "delete_failed")];
    }

    return ["ok" => true, "data" => ["client_id" => $clientId]];
}

/**
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string}
 */
function admin_clients_service_toggle_active(mysqli $conn, int $clientId): array
{
    $result = clients_admin_toggle_active($conn, $clientId);
    if (!$result["ok"]) {
        return ["ok" => false, "error" => (string)($result["error"] ?? "update_failed")];
    }

    return ["ok" => true, "data" => $result["client"]];
}

/**
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string}
 */
function admin_clients_service_toggle_email_notify(mysqli $conn, int $clientId): array
{
    $result = clients_admin_toggle_email_notify($conn, $clientId);
    if (!$result["ok"]) {
        return ["ok" => false, "error" => (string)($result["error"] ?? "update_failed")];
    }

    return ["ok" => true, "data" => $result["client"]];
}

/**
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string}
 */
function admin_whatsapp_service_list(mysqli $conn, int $limit = 100): array
{
    $deny = admin_whatsapp_require_feature();
    if ($deny !== null) {
        return $deny;
    }

    $rows = whatsapp_admin_list($conn, $limit);
    $items = [];
    foreach ($rows as $row) {
        $items[] = whatsapp_admin_format($row);
    }

    return [
        "ok" => true,
        "data" => [
            "counts" => whatsapp_admin_counts($conn),
            "clicks" => $items,
        ],
    ];
}

/**
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string}
 */
function admin_whatsapp_service_get(mysqli $conn, int $clickId): array
{
    $deny = admin_whatsapp_require_feature();
    if ($deny !== null) {
        return $deny;
    }

    $got = whatsapp_admin_get($conn, $clickId);
    if (!$got["ok"]) {
        return ["ok" => false, "error" => (string)($got["error"] ?? "not_found")];
    }

    return ["ok" => true, "data" => whatsapp_admin_format($got["click"])];
}

/**
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string}
 */
function admin_whatsapp_service_delete(mysqli $conn, int $clickId): array
{
    $deny = admin_whatsapp_require_feature();
    if ($deny !== null) {
        return $deny;
    }

    $deleted = whatsapp_admin_delete($conn, $clickId);
    if (!$deleted["ok"]) {
        return ["ok" => false, "error" => (string)($deleted["error"] ?? "delete_failed")];
    }

    return [
        "ok" => true,
        "data" => [
            "click_id" => $clickId,
            "counts" => whatsapp_admin_counts($conn),
        ],
    ];
}

/**
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string}
 */
function admin_whatsapp_service_set_read(mysqli $conn, int $clickId, bool $read): array
{
    $deny = admin_whatsapp_require_feature();
    if ($deny !== null) {
        return $deny;
    }

    $result = whatsapp_admin_set_read($conn, $clickId, $read);
    if (!$result["ok"]) {
        return ["ok" => false, "error" => (string)($result["error"] ?? "update_failed")];
    }

    return [
        "ok" => true,
        "data" => [
            "click_id" => $clickId,
            "read" => $read,
            "affected" => (int)($result["affected"] ?? 0),
            "counts" => $result["counts"],
            "unread_total" => (int)($result["counts"]["unread"] ?? 0),
            "messages_total" => (int)($result["counts"]["total"] ?? 0),
        ],
    ];
}

/**
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string}
 */
function admin_whatsapp_service_set_all_read(mysqli $conn, bool $read): array
{
    $deny = admin_whatsapp_require_feature();
    if ($deny !== null) {
        return $deny;
    }

    $result = whatsapp_admin_set_all_read($conn, $read);
    if (!$result["ok"]) {
        return ["ok" => false, "error" => (string)($result["error"] ?? "update_failed")];
    }

    return [
        "ok" => true,
        "data" => [
            "read" => $read,
            "counts" => $result["counts"],
            "unread_total" => (int)($result["counts"]["unread"] ?? 0),
            "messages_total" => (int)($result["counts"]["total"] ?? 0),
        ],
    ];
}
