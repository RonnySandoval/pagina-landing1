<?php
declare(strict_types=1);

require_once __DIR__ . "/upload_image_lib.php";

/**
 * Servicios de la landing y galería por servicio.
 */

function services_clamp_sort_order(int $sortOrder): int
{
    if ($sortOrder < 0) {
        return 999;
    }
    if ($sortOrder > 999999) {
        return 999999;
    }

    return $sortOrder;
}

/**
 * @return list<array<string, mixed>>
 */
function services_list_all(mysqli $conn, bool $activeOnly = false): array
{
    $sql = "SELECT * FROM services";
    if ($activeOnly) {
        $sql .= " WHERE is_active = 1";
    }
    $sql .= " ORDER BY sort_order ASC, id ASC";

    $rows = [];
    $q = $conn->query($sql);
    if ($q) {
        while ($row = $q->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    return $rows;
}

/**
 * @return array<int, list<array<string, mixed>>>
 */
function services_gallery_by_service(mysqli $conn, bool $activeOnly = true): array
{
    $sql = "SELECT id, service_id, image_path, caption, image_title, image_description, sort_order, is_active
            FROM service_gallery";
    if ($activeOnly) {
        $sql .= " WHERE is_active = 1";
    }
    $sql .= " ORDER BY sort_order ASC, id ASC";

    $byService = [];
    $q = $conn->query($sql);
    if ($q) {
        while ($row = $q->fetch_assoc()) {
            $sid = (int)($row["service_id"] ?? 0);
            if (!isset($byService[$sid])) {
                $byService[$sid] = [];
            }
            $byService[$sid][] = $row;
        }
    }

    return $byService;
}

/**
 * @return array{services: list<array<string, mixed>>, gallery_by_service: array<int, list<array<string, mixed>>>}
 */
function services_load_admin_catalog(mysqli $conn): array
{
    return [
        "services" => services_list_all($conn, false),
        "gallery_by_service" => services_gallery_by_service($conn, true),
    ];
}

/**
 * @return array{ok: true, service: array<string, mixed>, gallery: list<array<string, mixed>>}|array{ok: false, error: string}
 */
function services_get_with_gallery(mysqli $conn, int $serviceId): array
{
    if ($serviceId <= 0) {
        return ["ok" => false, "error" => "id_invalido"];
    }

    $stmt = $conn->prepare("SELECT * FROM services WHERE id = ? LIMIT 1");
    if ($stmt === false) {
        return ["ok" => false, "error" => "load_failed"];
    }
    $stmt->bind_param("i", $serviceId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!is_array($row)) {
        return ["ok" => false, "error" => "not_found"];
    }

    $gallery = [];
    $gstmt = $conn->prepare(
        "SELECT id, service_id, image_path, caption, image_title, image_description, sort_order, is_active
         FROM service_gallery WHERE service_id = ? ORDER BY sort_order ASC, id ASC"
    );
    if ($gstmt !== false) {
        $gstmt->bind_param("i", $serviceId);
        $gstmt->execute();
        $gres = $gstmt->get_result();
        if ($gres) {
            while ($g = $gres->fetch_assoc()) {
                $gallery[] = $g;
            }
        }
        $gstmt->close();
    }

    return ["ok" => true, "service" => $row, "gallery" => $gallery];
}

/**
 * @param array<string, mixed> $input
 * @return array{ok: true, service_id: int}|array{ok: false, error: string, message?: string}
 */
function services_create(mysqli $conn, array $input, array $file = [], ?string $projectRoot = null): array
{
    $title = trim((string)($input["title"] ?? ""));
    $description = trim((string)($input["description"] ?? ""));
    if ($title === "" || $description === "") {
        return ["ok" => false, "error" => "missing_fields"];
    }

    $iconClass = trim((string)($input["icon_class"] ?? "fa-solid fa-star"));
    if ($iconClass === "") {
        $iconClass = "fa-solid fa-star";
    }
    $sortOrder = services_clamp_sort_order((int)($input["sort_order"] ?? 999));
    $isActive = !array_key_exists("is_active", $input) || filter_var($input["is_active"], FILTER_VALIDATE_BOOLEAN);

    $root = $projectRoot ?? dirname(__FILE__);
    $upload = upload_store_service_image($file, $root);
    if ($upload["error"] !== "") {
        return ["ok" => false, "error" => "image_upload_failed", "message" => $upload["error"]];
    }
    $imagePath = $upload["path"] ?? "";
    if ($imagePath === null) {
        $imagePath = "";
    }

    $activeInt = $isActive ? 1 : 0;
    $stmt = $conn->prepare(
        "INSERT INTO services (title, description, icon_class, image_path, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?)"
    );
    if ($stmt === false) {
        return ["ok" => false, "error" => "insert_failed"];
    }
    $stmt->bind_param("ssssii", $title, $description, $iconClass, $imagePath, $sortOrder, $activeInt);
    if (!$stmt->execute()) {
        $stmt->close();
        return ["ok" => false, "error" => "insert_failed"];
    }
    $id = (int)$stmt->insert_id;
    $stmt->close();

    return ["ok" => true, "service_id" => $id];
}

/**
 * @param array<string, mixed> $input
 * @return array{ok: true}|array{ok: false, error: string, message?: string}
 */
function services_update(
    mysqli $conn,
    int $serviceId,
    array $input,
    array $file = [],
    ?string $projectRoot = null
): array {
    if ($serviceId <= 0) {
        return ["ok" => false, "error" => "id_invalido"];
    }

    $existing = services_get_with_gallery($conn, $serviceId);
    if (!$existing["ok"]) {
        return ["ok" => false, "error" => "not_found"];
    }

    $title = trim((string)($input["title"] ?? $existing["service"]["title"] ?? ""));
    $description = trim((string)($input["description"] ?? $existing["service"]["description"] ?? ""));
    if ($title === "" || $description === "") {
        return ["ok" => false, "error" => "missing_fields"];
    }

    $iconClass = trim((string)($input["icon_class"] ?? $existing["service"]["icon_class"] ?? "fa-solid fa-star"));
    $sortOrder = services_clamp_sort_order((int)($input["sort_order"] ?? $existing["service"]["sort_order"] ?? 999));
    $isActive = array_key_exists("is_active", $input)
        ? filter_var($input["is_active"], FILTER_VALIDATE_BOOLEAN)
        : (int)($existing["service"]["is_active"] ?? 1) === 1;

    $currentImagePath = trim((string)($input["current_image_path"] ?? $existing["service"]["image_path"] ?? ""));
    $root = $projectRoot ?? dirname(__FILE__);
    $upload = upload_store_service_image($file, $root);
    if ($upload["error"] !== "") {
        return ["ok" => false, "error" => "image_upload_failed", "message" => $upload["error"]];
    }
    $imagePath = $upload["path"] ?? null;
    if ($imagePath === null) {
        $imagePath = $currentImagePath !== "" ? $currentImagePath : "";
    }
    $imagePathDb = $imagePath !== "" ? (string)$imagePath : "";

    $activeInt = $isActive ? 1 : 0;
    $stmt = $conn->prepare(
        "UPDATE services SET title = ?, description = ?, icon_class = ?, image_path = NULLIF(?, ''), sort_order = ?, is_active = ? WHERE id = ?"
    );
    if ($stmt === false) {
        return ["ok" => false, "error" => "update_failed"];
    }
    $stmt->bind_param("ssssiii", $title, $description, $iconClass, $imagePathDb, $sortOrder, $activeInt, $serviceId);
    if (!$stmt->execute()) {
        $stmt->close();
        return ["ok" => false, "error" => "update_failed"];
    }
    $stmt->close();

    return ["ok" => true];
}

/**
 * @return array{ok: true}|array{ok: false, error: string}
 */
function services_update_image(mysqli $conn, int $serviceId, array $file, ?string $projectRoot = null): array
{
    if ($serviceId <= 0) {
        return ["ok" => false, "error" => "id_invalido"];
    }

    $existing = services_get_with_gallery($conn, $serviceId);
    if (!$existing["ok"]) {
        return ["ok" => false, "error" => "not_found"];
    }

    $root = $projectRoot ?? dirname(__FILE__);
    $upload = upload_store_service_image($file, $root);
    if ($upload["error"] !== "") {
        return ["ok" => false, "error" => "image_upload_failed", "message" => $upload["error"]];
    }
    if ($upload["path"] === null) {
        return ["ok" => false, "error" => "no_file"];
    }

    $stmt = $conn->prepare("UPDATE services SET image_path = ? WHERE id = ?");
    if ($stmt === false) {
        return ["ok" => false, "error" => "update_failed"];
    }
    $path = (string)$upload["path"];
    $stmt->bind_param("si", $path, $serviceId);
    if (!$stmt->execute()) {
        $stmt->close();
        return ["ok" => false, "error" => "update_failed"];
    }
    $stmt->close();

    return ["ok" => true];
}

/**
 * @return array{ok: true}|array{ok: false, error: string}
 */
function services_delete(mysqli $conn, int $serviceId): array
{
    if ($serviceId <= 0) {
        return ["ok" => false, "error" => "id_invalido"];
    }

    $stmt = $conn->prepare("DELETE FROM services WHERE id = ?");
    if ($stmt === false) {
        return ["ok" => false, "error" => "delete_failed"];
    }
    $stmt->bind_param("i", $serviceId);
    if (!$stmt->execute()) {
        $stmt->close();
        return ["ok" => false, "error" => "delete_failed"];
    }
    $affected = $stmt->affected_rows;
    $stmt->close();
    if ($affected < 1) {
        return ["ok" => false, "error" => "not_found"];
    }

    return ["ok" => true];
}

/**
 * @param list<int> $galleryIds
 */
function service_gallery_delete_ids(mysqli $conn, array $galleryIds): void
{
    $galleryIds = array_values(array_filter(array_map("intval", $galleryIds), static fn(int $id): bool => $id > 0));
    if (count($galleryIds) === 0) {
        return;
    }
    $placeholders = implode(",", array_fill(0, count($galleryIds), "?"));
    $types = str_repeat("i", count($galleryIds));
    $stmt = $conn->prepare("DELETE FROM service_gallery WHERE id IN ($placeholders)");
    if ($stmt === false) {
        return;
    }
    $stmt->bind_param($types, ...$galleryIds);
    $stmt->execute();
    $stmt->close();
}

/**
 * @return array{ok: true, gallery_id: int}|array{ok: false, error: string, message?: string}
 */
function service_gallery_add_image(
    mysqli $conn,
    int $serviceId,
    array $file,
    ?string $projectRoot = null
): array {
    if ($serviceId <= 0) {
        return ["ok" => false, "error" => "id_invalido"];
    }

    $check = services_get_with_gallery($conn, $serviceId);
    if (!$check["ok"]) {
        return ["ok" => false, "error" => "not_found"];
    }

    $root = $projectRoot ?? dirname(__FILE__);
    $upload = upload_store_service_image($file, $root);
    if ($upload["error"] !== "") {
        return ["ok" => false, "error" => "image_upload_failed", "message" => $upload["error"]];
    }
    if ($upload["path"] === null) {
        if (!isset($file["error"]) || (int)$file["error"] === UPLOAD_ERR_NO_FILE) {
            return ["ok" => true, "gallery_id" => 0];
        }
        return ["ok" => false, "error" => "no_file"];
    }

    $path = (string)$upload["path"];
    $stmt = $conn->prepare(
        "INSERT INTO service_gallery (service_id, image_path, sort_order, is_active, image_title, image_description) VALUES (?, ?, 999, 1, NULL, NULL)"
    );
    if ($stmt === false) {
        return ["ok" => false, "error" => "insert_failed"];
    }
    $stmt->bind_param("is", $serviceId, $path);
    if (!$stmt->execute()) {
        $stmt->close();
        return ["ok" => false, "error" => "insert_failed"];
    }
    $id = (int)$stmt->insert_id;
    $stmt->close();

    return ["ok" => true, "gallery_id" => $id];
}

/**
 * @return array{ok: true}|array{ok: false, error: string}
 */
function service_gallery_update_meta(mysqli $conn, int $galleryId, string $title, string $description): array
{
    if ($galleryId <= 0) {
        return ["ok" => false, "error" => "id_invalido"];
    }

    $imgTitle = trim($title);
    $imgDesc = trim($description);
    $capSync = $imgTitle;
    if (function_exists("mb_substr")) {
        $capSync = mb_substr($imgTitle, 0, 180, "UTF-8");
    } elseif (strlen($capSync) > 180) {
        $capSync = substr($capSync, 0, 180);
    }

    $stmt = $conn->prepare("UPDATE service_gallery SET image_title = ?, image_description = ?, caption = ? WHERE id = ?");
    if ($stmt === false) {
        return ["ok" => false, "error" => "update_failed"];
    }
    $stmt->bind_param("sssi", $imgTitle, $imgDesc, $capSync, $galleryId);
    if (!$stmt->execute()) {
        $stmt->close();
        return ["ok" => false, "error" => "update_failed"];
    }
    $affected = $stmt->affected_rows;
    $stmt->close();
    if ($affected < 1) {
        return ["ok" => false, "error" => "not_found"];
    }

    return ["ok" => true];
}

/**
 * @param list<int> $orderedIds
 * @return array{ok: true}|array{ok: false, error: string}
 */
function service_gallery_reorder(mysqli $conn, int $serviceId, array $orderedIds): array
{
    if ($serviceId <= 0) {
        return ["ok" => false, "error" => "id_invalido"];
    }

    $orderedIds = array_values(array_unique(array_filter(array_map("intval", $orderedIds), static fn(int $v): bool => $v > 0)));
    if (count($orderedIds) === 0) {
        return ["ok" => true];
    }

    $reset = $conn->prepare("UPDATE service_gallery SET sort_order = 999 WHERE service_id = ?");
    if ($reset === false) {
        return ["ok" => false, "error" => "update_failed"];
    }
    $reset->bind_param("i", $serviceId);
    $reset->execute();
    $reset->close();

    $position = 1;
    foreach ($orderedIds as $galleryId) {
        $orderStmt = $conn->prepare("UPDATE service_gallery SET sort_order = ? WHERE id = ? AND service_id = ?");
        if ($orderStmt === false) {
            return ["ok" => false, "error" => "update_failed"];
        }
        $orderStmt->bind_param("iii", $position, $galleryId, $serviceId);
        $orderStmt->execute();
        $orderStmt->close();
        $position++;
    }

    return ["ok" => true];
}

/**
 * @return array{ok: true}|array{ok: false, error: string, message?: string}
 */
function services_delete_gallery_item(mysqli $conn, int $galleryId): array
{
    if ($galleryId <= 0) {
        return ["ok" => false, "error" => "id_invalido"];
    }

    $stmt = $conn->prepare("DELETE FROM service_gallery WHERE id = ?");
    if ($stmt === false) {
        return ["ok" => false, "error" => "delete_failed"];
    }
    $stmt->bind_param("i", $galleryId);
    if (!$stmt->execute()) {
        $stmt->close();
        return ["ok" => false, "error" => "delete_failed"];
    }
    $affected = $stmt->affected_rows;
    $stmt->close();
    if ($affected < 1) {
        return ["ok" => false, "error" => "not_found"];
    }

    return ["ok" => true];
}

/**
 * Guardado masivo del formulario legacy en admin.php.
 *
 * @return array{ok: true, message: string}|array{ok: false, error: string, message?: string}
 */
function services_save_batch_from_post(
    mysqli $conn,
    array $post,
    array $files,
    ?string $projectRoot = null,
    ?int $onlyServiceId = null
): array {
    if (!isset($post["services"]) || !is_array($post["services"])) {
        return ["ok" => false, "error" => "missing_services"];
    }

    if ($onlyServiceId !== null && $onlyServiceId <= 0) {
        $onlyServiceId = null;
    }

    $removeGalleryIds = array_values(array_filter(
        array_map("intval", $post["remove_gallery_ids"] ?? []),
        static fn(int $id): bool => $id > 0
    ));
    if ($onlyServiceId !== null && count($removeGalleryIds) > 0) {
        $allowedGalleryIds = [];
        $gstmt = $conn->prepare("SELECT id FROM service_gallery WHERE service_id = ?");
        if ($gstmt !== false) {
            $gstmt->bind_param("i", $onlyServiceId);
            $gstmt->execute();
            $gres = $gstmt->get_result();
            if ($gres) {
                while ($grow = $gres->fetch_assoc()) {
                    $allowedGalleryIds[(int)($grow["id"] ?? 0)] = true;
                }
            }
            $gstmt->close();
        }
        $removeGalleryIds = array_values(array_filter(
            $removeGalleryIds,
            static fn(int $gid): bool => isset($allowedGalleryIds[$gid])
        ));
    }
    service_gallery_delete_ids($conn, $removeGalleryIds);

    if (isset($post["gallery_image_titles"]) && is_array($post["gallery_image_titles"])) {
        foreach ($post["gallery_image_titles"] as $galleryIdRaw => $titleRaw) {
            $galleryId = (int)$galleryIdRaw;
            if ($galleryId <= 0 || in_array($galleryId, $removeGalleryIds, true)) {
                continue;
            }
            if ($onlyServiceId !== null) {
                $ownerStmt = $conn->prepare("SELECT service_id FROM service_gallery WHERE id = ? LIMIT 1");
                if ($ownerStmt === false) {
                    continue;
                }
                $ownerStmt->bind_param("i", $galleryId);
                $ownerStmt->execute();
                $ownerRes = $ownerStmt->get_result();
                $ownerRow = $ownerRes ? $ownerRes->fetch_assoc() : null;
                $ownerStmt->close();
                if ((int)($ownerRow["service_id"] ?? 0) !== $onlyServiceId) {
                    continue;
                }
            }
            $imgDesc = trim((string)($post["gallery_image_descriptions"][$galleryIdRaw] ?? ""));
            service_gallery_update_meta($conn, $galleryId, trim((string)$titleRaw), $imgDesc);
        }
    }

    $root = $projectRoot ?? dirname(__FILE__);

    foreach ($post["services"] as $id => $serviceData) {
        if (!is_array($serviceData)) {
            continue;
        }
        $serviceId = (int)$id;
        if ($serviceId <= 0) {
            continue;
        }
        if ($onlyServiceId !== null && $serviceId !== $onlyServiceId) {
            continue;
        }

        $serviceFile = [];
        if (isset($files["service_images"]["error"][$serviceId])) {
            $serviceFile = [
                "name" => $files["service_images"]["name"][$serviceId] ?? "",
                "type" => $files["service_images"]["type"][$serviceId] ?? "",
                "tmp_name" => $files["service_images"]["tmp_name"][$serviceId] ?? "",
                "error" => (int)($files["service_images"]["error"][$serviceId] ?? UPLOAD_ERR_NO_FILE),
                "size" => (int)($files["service_images"]["size"][$serviceId] ?? 0),
            ];
        }

        $isActive = isset($serviceData["is_active"]);
        $update = services_update($conn, $serviceId, [
            "title" => $serviceData["title"] ?? "",
            "description" => $serviceData["description"] ?? "",
            "icon_class" => $serviceData["icon_class"] ?? "fa-solid fa-star",
            "sort_order" => $serviceData["sort_order"] ?? 999,
            "is_active" => $isActive,
            "current_image_path" => $serviceData["current_image_path"] ?? "",
        ], $serviceFile, $root);

        if (!$update["ok"]) {
            return [
                "ok" => false,
                "error" => (string)($update["error"] ?? "update_failed"),
                "message" => (string)($update["message"] ?? ""),
            ];
        }

        $galleryOrderRaw = (string)($serviceData["gallery_order"] ?? "");
        $orderedIds = array_values(array_unique(array_filter(
            array_map("intval", explode(",", $galleryOrderRaw)),
            static fn(int $value): bool => $value > 0
        )));
        $reorder = service_gallery_reorder($conn, $serviceId, $orderedIds);
        if (!$reorder["ok"]) {
            return ["ok" => false, "error" => (string)($reorder["error"] ?? "reorder_failed")];
        }

        if (isset($files["gallery_images"]["error"][$serviceId]) && is_array($files["gallery_images"]["error"][$serviceId])) {
            foreach ($files["gallery_images"]["error"][$serviceId] as $index => $fileError) {
                if ((int)$fileError === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $galleryFile = [
                    "name" => $files["gallery_images"]["name"][$serviceId][$index] ?? "",
                    "type" => $files["gallery_images"]["type"][$serviceId][$index] ?? "",
                    "tmp_name" => $files["gallery_images"]["tmp_name"][$serviceId][$index] ?? "",
                    "error" => (int)$fileError,
                    "size" => (int)($files["gallery_images"]["size"][$serviceId][$index] ?? 0),
                ];
                $add = service_gallery_add_image($conn, $serviceId, $galleryFile, $root);
                if (!$add["ok"]) {
                    return [
                        "ok" => false,
                        "error" => (string)($add["error"] ?? "gallery_failed"),
                        "message" => (string)($add["message"] ?? ""),
                    ];
                }
            }
        }
    }

    if ($onlyServiceId !== null) {
        $out = ["ok" => true, "message" => "Servicio actualizado."];
        $got = services_get_with_gallery($conn, $onlyServiceId);
        if ($got["ok"]) {
            $out["service"] = services_format_for_api($got["service"], $got["gallery"]);
        }

        return $out;
    }

    return ["ok" => true, "message" => "Servicios actualizados."];
}

/**
 * @param array<string, mixed> $service
 * @param list<array<string, mixed>> $gallery
 * @return array<string, mixed>
 */
function services_format_for_api(array $service, array $gallery = []): array
{
    return [
        "id" => (int)($service["id"] ?? 0),
        "title" => (string)($service["title"] ?? ""),
        "description" => (string)($service["description"] ?? ""),
        "icon_class" => (string)($service["icon_class"] ?? ""),
        "image_path" => $service["image_path"] ?? null,
        "sort_order" => (int)($service["sort_order"] ?? 999),
        "is_active" => (int)($service["is_active"] ?? 0) === 1,
        "gallery" => array_map(static function (array $g): array {
            return [
                "id" => (int)($g["id"] ?? 0),
                "service_id" => (int)($g["service_id"] ?? 0),
                "image_path" => (string)($g["image_path"] ?? ""),
                "caption" => $g["caption"] ?? null,
                "image_title" => $g["image_title"] ?? null,
                "image_description" => $g["image_description"] ?? null,
                "sort_order" => (int)($g["sort_order"] ?? 999),
                "is_active" => (int)($g["is_active"] ?? 1) === 1,
            ];
        }, $gallery),
    ];
}
