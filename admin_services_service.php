<?php
declare(strict_types=1);

require_once __DIR__ . "/services_lib.php";

/**
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string}
 */
function admin_services_service_list(mysqli $conn): array
{
    $catalog = services_load_admin_catalog($conn);
    $items = [];
    foreach ($catalog["services"] as $service) {
        $sid = (int)($service["id"] ?? 0);
        $gallery = $catalog["gallery_by_service"][$sid] ?? [];
        $items[] = services_format_for_api($service, $gallery);
    }

    return ["ok" => true, "data" => ["services" => $items]];
}

/**
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string}
 */
function admin_services_service_get(mysqli $conn, int $serviceId): array
{
    $got = services_get_with_gallery($conn, $serviceId);
    if (!$got["ok"]) {
        return ["ok" => false, "error" => (string)($got["error"] ?? "not_found")];
    }

    return [
        "ok" => true,
        "data" => services_format_for_api($got["service"], $got["gallery"]),
    ];
}

/**
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string, message?: string}
 */
function admin_services_service_create(mysqli $conn, array $input, array $file = []): array
{
    $created = services_create($conn, $input, $file);
    if (!$created["ok"]) {
        $out = ["ok" => false, "error" => (string)($created["error"] ?? "create_failed")];
        if (!empty($created["message"])) {
            $out["message"] = (string)$created["message"];
        }
        return $out;
    }

    return admin_services_service_get($conn, (int)$created["service_id"]);
}

/**
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string, message?: string}
 */
function admin_services_service_update(mysqli $conn, int $serviceId, array $input, array $file = []): array
{
    $updated = services_update($conn, $serviceId, $input, $file);
    if (!$updated["ok"]) {
        $out = ["ok" => false, "error" => (string)($updated["error"] ?? "update_failed")];
        if (!empty($updated["message"])) {
            $out["message"] = (string)$updated["message"];
        }
        return $out;
    }

    return admin_services_service_get($conn, $serviceId);
}

/**
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string, message?: string}
 */
function admin_services_service_update_image(mysqli $conn, int $serviceId, array $file): array
{
    $updated = services_update_image($conn, $serviceId, $file);
    if (!$updated["ok"]) {
        $out = ["ok" => false, "error" => (string)($updated["error"] ?? "update_failed")];
        if (!empty($updated["message"])) {
            $out["message"] = (string)$updated["message"];
        }
        return $out;
    }

    return admin_services_service_get($conn, $serviceId);
}

/**
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string}
 */
function admin_services_service_delete(mysqli $conn, int $serviceId): array
{
    $deleted = services_delete($conn, $serviceId);
    if (!$deleted["ok"]) {
        return ["ok" => false, "error" => (string)($deleted["error"] ?? "delete_failed")];
    }

    return ["ok" => true, "data" => ["service_id" => $serviceId]];
}

/**
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string, message?: string}
 */
function admin_services_service_gallery_add(mysqli $conn, int $serviceId, array $file): array
{
    $added = service_gallery_add_image($conn, $serviceId, $file);
    if (!$added["ok"]) {
        $out = ["ok" => false, "error" => (string)($added["error"] ?? "gallery_failed")];
        if (!empty($added["message"])) {
            $out["message"] = (string)$added["message"];
        }
        return $out;
    }

    $got = services_get_with_gallery($conn, $serviceId);
    $gallery = $got["gallery"] ?? [];
    $item = null;
    foreach ($gallery as $g) {
        if ((int)($g["id"] ?? 0) === (int)$added["gallery_id"]) {
            $item = $g;
            break;
        }
    }

    return [
        "ok" => true,
        "data" => [
            "gallery_id" => (int)$added["gallery_id"],
            "item" => $item,
            "service" => admin_services_service_get($conn, $serviceId)["data"] ?? null,
        ],
    ];
}

/**
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string}
 */
function admin_services_service_gallery_update_meta(
    mysqli $conn,
    int $galleryId,
    string $title,
    string $description
): array {
    $updated = service_gallery_update_meta($conn, $galleryId, $title, $description);
    if (!$updated["ok"]) {
        return ["ok" => false, "error" => (string)($updated["error"] ?? "update_failed")];
    }

    return ["ok" => true, "data" => ["gallery_id" => $galleryId]];
}

/**
 * @param list<int> $orderedIds
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string}
 */
function admin_services_service_gallery_reorder(mysqli $conn, int $serviceId, array $orderedIds): array
{
    $reordered = service_gallery_reorder($conn, $serviceId, $orderedIds);
    if (!$reordered["ok"]) {
        return ["ok" => false, "error" => (string)($reordered["error"] ?? "reorder_failed")];
    }

    return admin_services_service_get($conn, $serviceId);
}

/**
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string}
 */
function admin_services_service_gallery_delete(mysqli $conn, int $galleryId): array
{
    $deleted = services_delete_gallery_item($conn, $galleryId);
    if (!$deleted["ok"]) {
        return ["ok" => false, "error" => (string)($deleted["error"] ?? "delete_failed")];
    }

    return ["ok" => true, "data" => ["gallery_id" => $galleryId]];
}
