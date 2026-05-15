<?php
declare(strict_types=1);

/**
 * IDs de envíos del cliente que pertenecen al mismo hilo (raíz = id del mensaje raíz).
 *
 * @param list<array<string,mixed>> $messages Filas mínimas con id e in_reply_to.
 * @return list<int>
 */
function index_client_message_ids_in_thread(array $messages, int $rootId): array
{
    $byId = [];
    foreach ($messages as $m) {
        $id = (int)($m["id"] ?? 0);
        if ($id > 0) {
            $byId[$id] = $m;
        }
    }
    $out = [];
    foreach ($messages as $m) {
        $mid = (int)($m["id"] ?? 0);
        if ($mid <= 0) {
            continue;
        }
        $r = $mid;
        $p = (int)($m["in_reply_to"] ?? 0);
        $guard = 0;
        while ($p > 0 && isset($byId[$p]) && $guard++ < 64) {
            $r = $p;
            $p = (int)($byId[$p]["in_reply_to"] ?? 0);
        }
        if ($r === $rootId) {
            $out[] = $mid;
        }
    }
    return $out;
}

/**
 * @return list<array<string,mixed>>
 */
function index_client_inbox_messages_minimal(mysqli $conn, int $clientId, string $emailNorm): array
{
    $rows = [];
    $stmt = $conn->prepare(
        "SELECT id, in_reply_to, client_has_unseen_reply, created_at FROM contact_messages
         WHERE client_id = ? OR (client_id IS NULL AND LOWER(TRIM(email)) = ?)
         ORDER BY created_at DESC, id DESC
         LIMIT 200"
    );
    if ($stmt === false) {
        return [];
    }
    $stmt->bind_param("is", $clientId, $emailNorm);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    $stmt->close();
    return $rows;
}

function index_client_site_unseen_total_from_rows(array $rows): int
{
    $n = 0;
    foreach ($rows as $r) {
        if ((int)($r["client_has_unseen_reply"] ?? 0) === 1) {
            $n++;
        }
    }
    return $n;
}

function index_client_max_reply_id_for_messages(mysqli $conn, array $rows): int
{
    $ids = [];
    foreach ($rows as $r) {
        $id = (int)($r["id"] ?? 0);
        if ($id > 0) {
            $ids[$id] = true;
        }
    }
    if (count($ids) === 0) {
        return 0;
    }
    $inList = implode(",", array_keys($ids));
    $q = $conn->query("SELECT COALESCE(MAX(id), 0) AS mx FROM contact_message_replies WHERE contact_message_id IN ($inList)");
    if (!$q || $q->num_rows !== 1) {
        return 0;
    }
    $row = $q->fetch_assoc();
    return (int)($row["mx"] ?? 0);
}
