<?php
declare(strict_types=1);

/**
 * Clics de WhatsApp registrados desde el formulario de contacto (panel admin).
 */

/**
 * @return array{unread: int, total: int}
 */
function whatsapp_admin_counts(mysqli $conn): array
{
    $unread = 0;
    $total = 0;
    $q = $conn->query(
        "SELECT COALESCE(SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END), 0) AS u, COUNT(*) AS t
         FROM (
             SELECT is_read FROM contact_whatsapp_clicks ORDER BY created_at DESC, id DESC LIMIT 100
         ) AS wa_window"
    );
    if ($q) {
        $row = $q->fetch_assoc();
        if (is_array($row)) {
            $unread = (int)($row["u"] ?? 0);
            $total = (int)($row["t"] ?? 0);
        }
    }

    return ["unread" => $unread, "total" => $total];
}

/**
 * @return list<array<string, mixed>>
 */
function whatsapp_admin_list(mysqli $conn, int $limit = 100): array
{
    $limit = max(1, min(200, $limit));
    $rows = [];
    $stmt = $conn->prepare(
        "SELECT id, nombre, email, servicio, mensaje, composed_text, created_at, is_read
         FROM contact_whatsapp_clicks
         ORDER BY created_at DESC, id DESC
         LIMIT ?"
    );
    if ($stmt !== false) {
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        $stmt->close();
    }

    return $rows;
}

/**
 * @return array{ok: true, click: array<string, mixed>}|array{ok: false, error: string}
 */
function whatsapp_admin_get(mysqli $conn, int $clickId): array
{
    if ($clickId <= 0) {
        return ["ok" => false, "error" => "invalid_id"];
    }
    $stmt = $conn->prepare(
        "SELECT id, nombre, email, servicio, mensaje, composed_text, created_at, is_read
         FROM contact_whatsapp_clicks WHERE id = ? LIMIT 1"
    );
    if ($stmt === false) {
        return ["ok" => false, "error" => "load_failed"];
    }
    $stmt->bind_param("i", $clickId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!is_array($row)) {
        return ["ok" => false, "error" => "not_found"];
    }

    return ["ok" => true, "click" => $row];
}

/**
 * @return array{ok: true}|array{ok: false, error: string}
 */
function whatsapp_admin_delete(mysqli $conn, int $clickId): array
{
    if ($clickId <= 0) {
        return ["ok" => false, "error" => "invalid_id"];
    }
    $stmt = $conn->prepare("DELETE FROM contact_whatsapp_clicks WHERE id = ?");
    if ($stmt === false) {
        return ["ok" => false, "error" => "delete_failed"];
    }
    $stmt->bind_param("i", $clickId);
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
 * @return array{ok: true, read: bool, affected: int, counts: array{unread: int, total: int}}|array{ok: false, error: string}
 */
function whatsapp_admin_set_read(mysqli $conn, int $clickId, bool $read): array
{
    if ($clickId <= 0) {
        return ["ok" => false, "error" => "invalid_id"];
    }
    $val = $read ? 1 : 0;
    $stmt = $conn->prepare("UPDATE contact_whatsapp_clicks SET is_read = ? WHERE id = ?");
    if ($stmt === false) {
        return ["ok" => false, "error" => "update_failed"];
    }
    $stmt->bind_param("ii", $val, $clickId);
    if (!$stmt->execute()) {
        $stmt->close();
        return ["ok" => false, "error" => "update_failed"];
    }
    $affected = $stmt->affected_rows;
    $stmt->close();

    return [
        "ok" => true,
        "read" => $read,
        "affected" => $affected,
        "counts" => whatsapp_admin_counts($conn),
    ];
}

/**
 * @return array{ok: true, read: bool, counts: array{unread: int, total: int}}|array{ok: false, error: string}
 */
function whatsapp_admin_set_all_read(mysqli $conn, bool $read): array
{
    if ($read) {
        $ok = (bool)$conn->query("UPDATE contact_whatsapp_clicks SET is_read = 1 WHERE is_read = 0");
    } else {
        $ok = (bool)$conn->query("UPDATE contact_whatsapp_clicks SET is_read = 0 WHERE is_read = 1");
    }
    if (!$ok) {
        return ["ok" => false, "error" => "update_failed"];
    }

    return [
        "ok" => true,
        "read" => $read,
        "counts" => whatsapp_admin_counts($conn),
    ];
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function whatsapp_admin_format(array $row): array
{
    return [
        "id" => (int)($row["id"] ?? 0),
        "nombre" => (string)($row["nombre"] ?? ""),
        "email" => (string)($row["email"] ?? ""),
        "servicio" => (string)($row["servicio"] ?? ""),
        "mensaje" => (string)($row["mensaje"] ?? ""),
        "composed_text" => (string)($row["composed_text"] ?? ""),
        "is_read" => (int)($row["is_read"] ?? 0) === 1,
        "created_at" => $row["created_at"] ?? null,
    ];
}
