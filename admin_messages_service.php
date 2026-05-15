<?php
declare(strict_types=1);

require_once __DIR__ . "/app_urls.php";
require_once __DIR__ . "/admin_inbox_lib.php";

function admin_messages_require_inbox(): ?array
{
    if (!app_feature_enabled("admin_inbox")) {
        return ["ok" => false, "error" => "feature_disabled"];
    }

    return null;
}

/**
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string}
 */
function admin_messages_service_list(mysqli $conn, int $limit = 100): array
{
    $deny = admin_messages_require_inbox();
    if ($deny !== null) {
        return $deny;
    }

    $inbox = admin_inbox_load($conn, $limit);

    return [
        "ok" => true,
        "data" => [
            "counts" => $inbox["counts"],
            "messages" => $inbox["messages"],
            "replies" => $inbox["replies_by_message_id"],
            "groups" => $inbox["groups"],
        ],
    ];
}

/**
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string, message?: string}
 */
function admin_messages_service_get(mysqli $conn, int $messageId): array
{
    $deny = admin_messages_require_inbox();
    if ($deny !== null) {
        return $deny;
    }

    $got = admin_inbox_get_message($conn, $messageId);
    if (!$got["ok"]) {
        $err = (string)($got["error"] ?? "error");
        $status = $err === "not_found" ? "not_found" : "invalid_request";
        return ["ok" => false, "error" => $status];
    }

    return [
        "ok" => true,
        "data" => [
            "message" => $got["message"],
            "replies" => $got["replies"],
        ],
    ];
}

/**
 * @param array{read?: bool} $opts
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string}
 */
function admin_messages_service_set_read(mysqli $conn, int $messageId, array $opts): array
{
    $deny = admin_messages_require_inbox();
    if ($deny !== null) {
        return $deny;
    }

    $read = array_key_exists("read", $opts) ? (bool)$opts["read"] : true;
    $result = admin_inbox_mark_read($conn, $messageId, $read);

    if (!$result["ok"]) {
        return ["ok" => false, "error" => (string)($result["error"] ?? "update_failed")];
    }

    return [
        "ok" => true,
        "data" => [
            "message_id" => $messageId,
            "read" => $read,
            "affected" => (int)($result["affected"] ?? 0),
            "counts" => $result["counts"],
        ],
    ];
}

/**
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string}
 */
function admin_messages_service_set_all_read(mysqli $conn, bool $read): array
{
    $deny = admin_messages_require_inbox();
    if ($deny !== null) {
        return $deny;
    }

    $result = admin_inbox_mark_all($conn, $read);

    return [
        "ok" => true,
        "data" => [
            "read" => $read,
            "counts" => $result["counts"],
        ],
    ];
}

/**
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string}
 */
function admin_messages_service_delete(mysqli $conn, int $messageId): array
{
    $deny = admin_messages_require_inbox();
    if ($deny !== null) {
        return $deny;
    }

    $result = admin_inbox_delete_message($conn, $messageId);
    if (!$result["ok"]) {
        return ["ok" => false, "error" => (string)($result["error"] ?? "delete_failed")];
    }

    return [
        "ok" => true,
        "data" => [
            "message_id" => $messageId,
            "counts" => admin_contact_inbox_counts($conn),
        ],
    ];
}

/**
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string, message?: string}
 */
function admin_messages_service_reply(mysqli $conn, int $messageId, string $body): array
{
    $deny = admin_messages_require_inbox();
    if ($deny !== null) {
        return $deny;
    }

    $result = admin_inbox_reply_message($conn, $messageId, $body);
    if (!$result["ok"]) {
        return [
            "ok" => false,
            "error" => (string)($result["error"] ?? "reply_failed"),
            "message" => (string)($result["message"] ?? ""),
        ];
    }

    return [
        "ok" => true,
        "data" => [
            "reply_id" => (int)$result["reply_id"],
            "message_id" => $messageId,
            "email_sent" => (bool)$result["email_sent"],
            "email_code" => (string)$result["email_code"],
            "notice" => (string)$result["notice"],
            "counts" => admin_contact_inbox_counts($conn),
        ],
    ];
}
