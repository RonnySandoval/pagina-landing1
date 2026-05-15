<?php
declare(strict_types=1);

/**
 * Gestión de cuentas del portal de clientes (panel admin).
 */

/**
 * @return list<array<string, mixed>>
 */
function clients_admin_list(mysqli $conn): array
{
    $rows = [];
    $q = $conn->query(
        "SELECT id, email, display_name, is_active, email_notify_outbound, created_at
         FROM clients ORDER BY id ASC"
    );
    if ($q) {
        while ($row = $q->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    return $rows;
}

/**
 * @return array{ok: true, client: array<string, mixed>}|array{ok: false, error: string}
 */
function clients_admin_get(mysqli $conn, int $clientId): array
{
    if ($clientId <= 0) {
        return ["ok" => false, "error" => "invalid_id"];
    }
    $stmt = $conn->prepare(
        "SELECT id, email, display_name, is_active, email_notify_outbound, created_at FROM clients WHERE id = ? LIMIT 1"
    );
    if ($stmt === false) {
        return ["ok" => false, "error" => "load_failed"];
    }
    $stmt->bind_param("i", $clientId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!is_array($row)) {
        return ["ok" => false, "error" => "not_found"];
    }

    return ["ok" => true, "client" => $row];
}

/**
 * @return array{ok: true}|array{ok: false, error: string}
 */
function clients_admin_delete(mysqli $conn, int $clientId): array
{
    if ($clientId <= 0) {
        return ["ok" => false, "error" => "invalid_id"];
    }
    $stmt = $conn->prepare("DELETE FROM clients WHERE id = ?");
    if ($stmt === false) {
        return ["ok" => false, "error" => "delete_failed"];
    }
    $stmt->bind_param("i", $clientId);
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
 * @return array{ok: true, is_active: bool, client: array<string, mixed>}|array{ok: false, error: string}
 */
function clients_admin_toggle_active(mysqli $conn, int $clientId): array
{
    if ($clientId <= 0) {
        return ["ok" => false, "error" => "invalid_id"];
    }
    $stmt = $conn->prepare("UPDATE clients SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?");
    if ($stmt === false) {
        return ["ok" => false, "error" => "update_failed"];
    }
    $stmt->bind_param("i", $clientId);
    if (!$stmt->execute()) {
        $stmt->close();
        return ["ok" => false, "error" => "update_failed"];
    }
    $stmt->close();

    $got = clients_admin_get($conn, $clientId);
    if (!$got["ok"]) {
        return ["ok" => false, "error" => "not_found"];
    }

    return [
        "ok" => true,
        "is_active" => (int)($got["client"]["is_active"] ?? 0) === 1,
        "client" => clients_admin_format($got["client"]),
    ];
}

/**
 * @return array{ok: true, email_notify_outbound: bool, client: array<string, mixed>}|array{ok: false, error: string}
 */
function clients_admin_toggle_email_notify(mysqli $conn, int $clientId): array
{
    if ($clientId <= 0) {
        return ["ok" => false, "error" => "invalid_id"];
    }
    $stmt = $conn->prepare(
        "UPDATE clients SET email_notify_outbound = IF(email_notify_outbound = 1, 0, 1) WHERE id = ?"
    );
    if ($stmt === false) {
        return ["ok" => false, "error" => "update_failed"];
    }
    $stmt->bind_param("i", $clientId);
    if (!$stmt->execute()) {
        $stmt->close();
        return ["ok" => false, "error" => "update_failed"];
    }
    $stmt->close();

    $got = clients_admin_get($conn, $clientId);
    if (!$got["ok"]) {
        return ["ok" => false, "error" => "not_found"];
    }

    return [
        "ok" => true,
        "email_notify_outbound" => (int)($got["client"]["email_notify_outbound"] ?? 0) === 1,
        "client" => clients_admin_format($got["client"]),
    ];
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function clients_admin_format(array $row): array
{
    return [
        "id" => (int)($row["id"] ?? 0),
        "email" => (string)($row["email"] ?? ""),
        "display_name" => (string)($row["display_name"] ?? ""),
        "is_active" => (int)($row["is_active"] ?? 0) === 1,
        "email_notify_outbound" => (int)($row["email_notify_outbound"] ?? 1) === 1,
        "created_at" => $row["created_at"] ?? null,
    ];
}
