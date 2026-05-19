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

/**
 * Agrupa mensajes en hilos (misma lógica que index.php).
 *
 * @param array<int, list<array<string, mixed>>> $repliesByMessageId
 * @return list<array{root_id: int, messages: list<array<string, mixed>>, latest_ts: int, has_admin_reply: bool}>
 */
function client_inbox_group_threads(array $messages, array $repliesByMessageId): array
{
    $byId = [];
    foreach ($messages as $m) {
        $id = (int)($m["id"] ?? 0);
        if ($id > 0) {
            $byId[$id] = $m;
        }
    }
    $buckets = [];
    foreach ($messages as $m) {
        $mid = (int)($m["id"] ?? 0);
        if ($mid <= 0) {
            continue;
        }
        $root = $mid;
        $p = (int)($m["in_reply_to"] ?? 0);
        $guard = 0;
        while ($p > 0 && isset($byId[$p]) && $guard++ < 64) {
            $root = $p;
            $p = (int)($byId[$p]["in_reply_to"] ?? 0);
        }
        if (!isset($buckets[$root])) {
            $buckets[$root] = [];
        }
        $buckets[$root][] = $m;
    }
    $threads = [];
    foreach ($buckets as $rootId => $rows) {
        usort($rows, static function (array $a, array $b): int {
            $ta = strtotime((string)($a["created_at"] ?? "")) ?: 0;
            $tb = strtotime((string)($b["created_at"] ?? "")) ?: 0;
            if ($ta !== $tb) {
                return $ta <=> $tb;
            }
            return ((int)($a["id"] ?? 0)) <=> ((int)($b["id"] ?? 0));
        });
        $latestTs = 0;
        foreach ($rows as $r) {
            $t = strtotime((string)($r["created_at"] ?? "")) ?: 0;
            if ($t > $latestTs) {
                $latestTs = $t;
            }
        }
        $hasAdmin = false;
        foreach ($rows as $r) {
            $rid = (int)($r["id"] ?? 0);
            if ($rid > 0 && !empty($repliesByMessageId[$rid])) {
                $hasAdmin = true;
                break;
            }
        }
        $threads[] = [
            "root_id" => (int)$rootId,
            "messages" => $rows,
            "latest_ts" => $latestTs,
            "has_admin_reply" => $hasAdmin,
        ];
    }
    usort($threads, static function (array $a, array $b): int {
        return ($b["latest_ts"] ?? 0) <=> ($a["latest_ts"] ?? 0);
    });

    return $threads;
}

/**
 * @return array{
 *   messages: list<array<string, mixed>>,
 *   replies_by_message_id: array<int, list<array<string, mixed>>>,
 *   threads: list<array<string, mixed>>,
 *   site_unseen_total: int,
 *   max_reply_id: int
 * }
 */
function client_inbox_load_full(mysqli $conn, int $clientId, string $emailNorm, int $limit = 40): array
{
    $emailNorm = strtolower(trim($emailNorm));
    $messages = [];
    $limit = max(1, min(200, $limit));
    $stmt = $conn->prepare(
        "SELECT id, nombre, servicio, subject, mensaje, created_at, in_reply_to, is_read, client_has_unseen_reply,
                (SELECT COUNT(*) FROM contact_message_replies r WHERE r.contact_message_id = m.id) AS reply_count
         FROM contact_messages m
         WHERE m.client_id = ? OR (m.client_id IS NULL AND LOWER(TRIM(m.email)) = ?)
         ORDER BY m.created_at DESC, m.id DESC
         LIMIT ?"
    );
    if ($stmt !== false) {
        $stmt->bind_param("isi", $clientId, $emailNorm, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $messages[] = $row;
            }
        }
        $stmt->close();
    }

    $repliesByMessageId = [];
    $msgIds = [];
    foreach ($messages as $cm) {
        $mid = (int)($cm["id"] ?? 0);
        if ($mid > 0) {
            $msgIds[$mid] = true;
        }
    }
    $maxReplyId = 0;
    if (count($msgIds) > 0) {
        $inList = implode(",", array_keys($msgIds));
        $repQ = $conn->query(
            "SELECT id, contact_message_id, body, created_at FROM contact_message_replies WHERE contact_message_id IN ($inList) ORDER BY created_at ASC, id ASC"
        );
        if ($repQ) {
            while ($rp = $repQ->fetch_assoc()) {
                $mid = (int)$rp["contact_message_id"];
                if (!isset($repliesByMessageId[$mid])) {
                    $repliesByMessageId[$mid] = [];
                }
                $repliesByMessageId[$mid][] = $rp;
            }
        }
        $maxReplyId = index_client_max_reply_id_for_messages($conn, $messages);
    }

    $siteUnseen = 0;
    foreach ($messages as $cm) {
        if ((int)($cm["client_has_unseen_reply"] ?? 0) === 1) {
            $siteUnseen++;
        }
    }

    $threads = client_inbox_group_threads($messages, $repliesByMessageId);

    return [
        "messages" => $messages,
        "replies_by_message_id" => $repliesByMessageId,
        "threads" => $threads,
        "site_unseen_total" => $siteUnseen,
        "max_reply_id" => $maxReplyId,
    ];
}

/**
 * @return array{site_unseen_total: int, max_reply_id: int, threads_site_unseen: array<string, int>}
 */
/**
 * Ítems unificados para la campana del cliente (citas + respuestas del sitio en mensajes).
 *
 * @param list<array<string, mixed>> $agendaRows
 * @param list<array<string, mixed>> $messages
 * @param array<int, list<array<string, mixed>>> $repliesByMessageId
 * @return array{items: list<array<string, mixed>>, unread: int}
 */
function client_notify_bell_build_items(
    array $agendaRows,
    int $agendaUnread,
    array $messages,
    array $repliesByMessageId,
    int $siteUnseenTotal
): array {
    $items = [];

    foreach ($agendaRows as $row) {
        $evt = (string)($row["event_type"] ?? "");
        $isCancel = $evt === "appointment_cancelled";
        $apptId = (int)($row["appointment_id"] ?? 0);
        $items[] = [
            "kind" => "agenda",
            "is_unread" => (int)($row["is_read"] ?? 0) === 0,
            "tag" => $isCancel ? "Cancelada" : "Cita",
            "tag_muted" => $isCancel,
            "title" => trim((string)($row["title"] ?? "")),
            "body" => trim((string)($row["body"] ?? "")),
            "created_at" => (string)($row["created_at"] ?? ""),
            "meta_extra" => $apptId > 0 ? "#" . $apptId : "",
            "href" => "#client-agenda-notifications",
            "mark" => [
                "action" => "client_agenda_mark_notifications_read",
                "delivery_id" => (int)($row["id"] ?? 0),
            ],
        ];
    }

    if ($siteUnseenTotal > 0 && count($messages) > 0) {
        $threads = client_inbox_group_threads($messages, $repliesByMessageId);
        foreach ($threads as $thread) {
            $tMsgs = $thread["messages"] ?? [];
            $threadSiteUnseen = 0;
            $latestReplyAt = "";
            $latestReplyBody = "";
            foreach ($tMsgs as $tm) {
                if ((int)($tm["client_has_unseen_reply"] ?? 0) !== 1) {
                    continue;
                }
                $threadSiteUnseen++;
                $mid = (int)($tm["id"] ?? 0);
                $reps = $repliesByMessageId[$mid] ?? [];
                if (count($reps) > 0) {
                    $last = $reps[count($reps) - 1];
                    $latestReplyAt = (string)($last["created_at"] ?? $latestReplyAt);
                    $latestReplyBody = trim((string)($last["body"] ?? ""));
                }
            }
            if ($threadSiteUnseen <= 0) {
                continue;
            }
            $rootRow = $tMsgs[0] ?? [];
            $rootId = (int)($thread["root_id"] ?? 0);
            $subject = trim((string)($rootRow["subject"] ?? ""));
            if ($subject === "") {
                $subject = "Sin asunto";
            }
            $bodyPreview = $latestReplyBody !== ""
                ? $latestReplyBody
                : "Nueva respuesta del sitio en esta conversación.";
            $created = $latestReplyAt !== "" ? $latestReplyAt : (string)($rootRow["created_at"] ?? "");
            $items[] = [
                "kind" => "inbox",
                "is_unread" => true,
                "tag" => "Mensaje",
                "tag_muted" => false,
                "title" => $subject,
                "body" => $bodyPreview,
                "created_at" => $created,
                "meta_extra" => $threadSiteUnseen > 1
                    ? $threadSiteUnseen . " respuestas nuevas"
                    : "",
                "href" => $rootId > 0 ? "#client-thread-" . $rootId : "#area-cliente",
                "mark" => [
                    "action" => "client_mark_thread_read",
                    "thread_root_id" => $rootId,
                ],
            ];
        }
    }

    usort($items, static function (array $a, array $b): int {
        return strcmp((string)($b["created_at"] ?? ""), (string)($a["created_at"] ?? ""));
    });

    return [
        "items" => $items,
        "unread" => max(0, $agendaUnread) + max(0, $siteUnseenTotal),
    ];
}

/**
 * Quita el indicador de respuesta nueva del sitio en todos los mensajes del cliente.
 */
function client_notify_mark_all_inbox_seen(mysqli $conn, int $clientId, string $emailNorm): void
{
    if ($clientId <= 0 || $emailNorm === "") {
        return;
    }
    $st = $conn->prepare(
        "UPDATE contact_messages SET client_has_unseen_reply = 0
         WHERE client_has_unseen_reply = 1
           AND (client_id = ? OR (client_id IS NULL AND LOWER(TRIM(email)) = ?))"
    );
    if ($st === false) {
        return;
    }
    $st->bind_param("is", $clientId, $emailNorm);
    $st->execute();
    $st->close();
}

function client_inbox_poll_snapshot(mysqli $conn, int $clientId, string $emailNorm): array
{
    $minimal = index_client_inbox_messages_minimal($conn, $clientId, $emailNorm);
    $siteUnseen = index_client_site_unseen_total_from_rows($minimal);
    $maxReply = index_client_max_reply_id_for_messages($conn, $minimal);
    $threadsPoll = client_inbox_group_threads($minimal, []);
    $threadsSiteUnseen = [];
    foreach ($threadsPoll as $tp) {
        $nSite = 0;
        foreach ($tp["messages"] as $tm) {
            if ((int)($tm["client_has_unseen_reply"] ?? 0) === 1) {
                $nSite++;
            }
        }
        if ($nSite > 0) {
            $threadsSiteUnseen[(string)((int)($tp["root_id"] ?? 0))] = $nSite;
        }
    }

    return [
        "site_unseen_total" => $siteUnseen,
        "max_reply_id" => $maxReply,
        "threads_site_unseen" => $threadsSiteUnseen,
    ];
}
