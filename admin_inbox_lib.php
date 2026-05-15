<?php
declare(strict_types=1);

require_once __DIR__ . "/smtp_mail.php";
require_once __DIR__ . "/app_urls.php";
require_once __DIR__ . "/client_inbox_helpers.php";

/**
 * Bandeja de mensajes de contacto en el panel admin.
 */

function admin_inbox_group_threads(array $messages, array $repliesByMessageId): array
{
    return client_inbox_group_threads($messages, $repliesByMessageId);
}

/**
 * @return array{unread: int, total: int}
 */
function admin_contact_inbox_counts(mysqli $conn): array
{
    $unread = 0;
    $total = 0;
    $q = $conn->query(
        "SELECT COALESCE(SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END), 0) AS u, COUNT(*) AS t
         FROM (
             SELECT is_read FROM contact_messages ORDER BY created_at DESC, id DESC LIMIT 100
         ) AS inbox_window"
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
 * @return array{ok: bool, code: string}
 */
function admin_send_visitor_reply_mail(
    array $mailConfig,
    string $to,
    string $subject,
    string $bodyPlain,
    string $replyToEmail,
    string $fromDisplayName
): array {
    $replyToEmail = trim($replyToEmail);
    $fromDisplayName = trim($fromDisplayName);
    $fromEmail = mail_config_resolve_smtp_from($mailConfig);
    if ($replyToEmail === "" || !filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)) {
        return ["ok" => false, "code" => "bad_config"];
    }
    if ($fromEmail === "" || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        return ["ok" => false, "code" => "bad_config"];
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ["ok" => false, "code" => "bad_config"];
    }

    $host = trim((string)($mailConfig["host"] ?? ""));
    $user = trim((string)($mailConfig["username"] ?? ""));
    $pass = preg_replace('/\s+/', '', (string)($mailConfig["password"] ?? ""));
    $smtpFullyConfigured = $host !== "" && $user !== "" && $pass !== "";
    $smtpReady = $smtpFullyConfigured && $fromEmail !== "";

    if ($smtpReady) {
        $smtpCfg = $mailConfig;
        $smtpCfg["from_email"] = $fromEmail;
        $smtpCfg["from_name"] = $fromDisplayName;
        $ok = send_mail_smtp($smtpCfg, $to, $subject, $bodyPlain, $replyToEmail);
        if ($ok) {
            return ["ok" => true, "code" => "smtp"];
        }
        if (!empty($mailConfig["debug"]) && !empty($mailConfig["debug_log"])) {
            smtp_debug_log($mailConfig, "Respuesta admin: envio SMTP fallo (To=" . $to . ")");
        }
        $fromHeaderLine = smtp_format_from_header($fromDisplayName !== "" ? $fromDisplayName : "Web", $fromEmail);
        $headers = "From: " . $fromHeaderLine . "\r\n";
        $headers .= "Reply-To: " . $replyToEmail . "\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        if ((bool)@mail($to, $subject, $bodyPlain, $headers)) {
            return ["ok" => true, "code" => "php_mail_fallback"];
        }

        return ["ok" => false, "code" => "smtp_failed"];
    }

    $fromHeaderLine = smtp_format_from_header($fromDisplayName !== "" ? $fromDisplayName : "Web", $fromEmail);
    $headers = "From: " . $fromHeaderLine . "\r\n";
    $headers .= "Reply-To: " . $replyToEmail . "\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "MIME-Version: 1.0\r\n";

    return (bool)@mail($to, $subject, $bodyPlain, $headers)
        ? ["ok" => true, "code" => "php_mail"]
        : ["ok" => false, "code" => "php_mail_failed"];
}

/**
 * @return list<array<string, mixed>>
 */
function admin_inbox_fetch_portal_clients(mysqli $conn): array
{
    require_once __DIR__ . "/admin_clients_lib.php";

    return clients_admin_list($conn);
}

/**
 * @return array{
 *   messages: list<array<string, mixed>>,
 *   replies_by_message_id: array<int, list<array<string, mixed>>>,
 *   counts: array{unread: int, total: int},
 *   groups: list<array<string, mixed>>
 * }
 */
function admin_inbox_load(mysqli $conn, int $limit = 100, ?array $portalClients = null): array
{
    $limit = max(1, min(200, $limit));
    $messages = [];
    $stmt = $conn->prepare(
        "SELECT id, nombre, email, servicio, subject, mensaje, sent_to, is_read, created_at, client_id, in_reply_to
         FROM contact_messages
         ORDER BY created_at DESC, id DESC
         LIMIT ?"
    );
    if ($stmt !== false) {
        $stmt->bind_param("i", $limit);
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
    foreach ($messages as $row) {
        $mid = (int)($row["id"] ?? 0);
        if ($mid > 0) {
            $msgIds[$mid] = true;
        }
    }
    if (count($msgIds) > 0) {
        $inList = implode(",", array_keys($msgIds));
        $repQuery = $conn->query(
            "SELECT id, contact_message_id, body, created_at FROM contact_message_replies WHERE contact_message_id IN ($inList) ORDER BY created_at ASC, id ASC"
        );
        if ($repQuery) {
            while ($r = $repQuery->fetch_assoc()) {
                $mid = (int)$r["contact_message_id"];
                if (!isset($repliesByMessageId[$mid])) {
                    $repliesByMessageId[$mid] = [];
                }
                $repliesByMessageId[$mid][] = $r;
            }
        }
    }

    if ($portalClients === null) {
        $portalClients = admin_inbox_fetch_portal_clients($conn);
    }

    $groups = admin_inbox_build_groups($messages, $repliesByMessageId, $portalClients);
    $counts = admin_contact_inbox_counts($conn);

    return [
        "messages" => $messages,
        "replies_by_message_id" => $repliesByMessageId,
        "counts" => $counts,
        "groups" => $groups,
    ];
}

/**
 * @param array<int, list<array<string, mixed>>> $repliesByMessageId
 * @param list<array<string, mixed>> $portalClients
 * @return list<array<string, mixed>>
 */
function admin_inbox_build_groups(array $messages, array $repliesByMessageId, array $portalClients): array
{
    if (count($messages) === 0) {
        return [];
    }

    $clientDirectoryById = [];
    foreach ($portalClients as $pcRow) {
        $clientDirectoryById[(int)$pcRow["id"]] = $pcRow;
    }

    $groupsAccum = [];
    foreach ($messages as $row) {
        $cid = (int)($row["client_id"] ?? 0);
        if ($cid > 0) {
            $gkey = "c:" . $cid;
        } else {
            $em = strtolower(trim((string)($row["email"] ?? "")));
            $gkey = "e:" . ($em !== "" ? $em : "__sin_correo__");
        }
        if (!isset($groupsAccum[$gkey])) {
            $groupsAccum[$gkey] = [
                "key" => $gkey,
                "slug" => "h" . substr(md5($gkey), 0, 14),
                "type" => $cid > 0 ? "client" : "email",
                "client_id" => $cid > 0 ? $cid : 0,
                "anchor_email" => trim((string)($row["email"] ?? "")),
                "messages" => [],
                "unread" => 0,
                "latest_ts" => 0,
            ];
        }
        $groupsAccum[$gkey]["messages"][] = $row;
        if ((int)($row["is_read"] ?? 0) === 0) {
            $groupsAccum[$gkey]["unread"]++;
        }
        $ts = strtotime((string)($row["created_at"] ?? "")) ?: 0;
        if ($ts > $groupsAccum[$gkey]["latest_ts"]) {
            $groupsAccum[$gkey]["latest_ts"] = $ts;
        }
    }

    foreach ($groupsAccum as &$g) {
        usort($g["messages"], static function (array $a, array $b): int {
            $ta = strtotime((string)($a["created_at"] ?? "")) ?: 0;
            $tb = strtotime((string)($b["created_at"] ?? "")) ?: 0;
            if ($ta !== $tb) {
                return $ta <=> $tb;
            }
            return ((int)($a["id"] ?? 0)) <=> ((int)($b["id"] ?? 0));
        });
    }
    unset($g);

    $contactMessageGroups = array_values($groupsAccum);
    usort($contactMessageGroups, static function (array $a, array $b): int {
        return ($b["latest_ts"] ?? 0) <=> ($a["latest_ts"] ?? 0);
    });

    foreach ($contactMessageGroups as &$g) {
        if ($g["type"] === "client") {
            $pid = (int)$g["client_id"];
            $cl = $clientDirectoryById[$pid] ?? null;
            $g["head_badge"] = "Cliente";
            if ($cl !== null) {
                $dn = trim((string)($cl["display_name"] ?? ""));
                $g["head_title"] = $dn !== "" ? $dn : (string)$cl["email"];
                $g["head_email"] = (string)$cl["email"];
            } else {
                $first = $g["messages"][0] ?? [];
                $g["head_title"] = "Cliente n.º " . $pid;
                $g["head_email"] = (string)($first["email"] ?? "");
            }
            $g["head_sub"] = "";
        } else {
            $first = $g["messages"][0] ?? [];
            $nm = trim((string)($first["nombre"] ?? ""));
            $em = (string)($g["anchor_email"] ?? "");
            $g["head_badge"] = "Correo";
            $g["head_title"] = $em !== "" ? $em : "Sin correo";
            $g["head_email"] = $em;
            $g["head_sub"] = $nm !== "" ? $nm : "";
        }
        $g["msg_count"] = count($g["messages"]);
        $g["threads"] = admin_inbox_group_threads($g["messages"], $repliesByMessageId);
        $g["conv_count"] = count($g["threads"]);
        $ts = (int)($g["latest_ts"] ?? 0);
        $g["latest_label"] = $ts > 0 ? date("d/m H:i", $ts) : "";
    }
    unset($g);

    return $contactMessageGroups;
}

/**
 * @return array{ok: true, message: array<string, mixed>, replies: list<array<string, mixed>>}|array{ok: false, error: string}
 */
function admin_inbox_get_message(mysqli $conn, int $messageId): array
{
    if ($messageId <= 0) {
        return ["ok" => false, "error" => "id_invalido"];
    }

    $stmt = $conn->prepare(
        "SELECT id, nombre, email, servicio, subject, mensaje, sent_to, is_read, created_at, client_id, in_reply_to
         FROM contact_messages WHERE id = ? LIMIT 1"
    );
    if ($stmt === false) {
        return ["ok" => false, "error" => "db_error"];
    }
    $stmt->bind_param("i", $messageId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if ($row === null) {
        return ["ok" => false, "error" => "not_found"];
    }

    $replies = [];
    $repStmt = $conn->prepare(
        "SELECT id, contact_message_id, body, created_at FROM contact_message_replies WHERE contact_message_id = ? ORDER BY created_at ASC, id ASC"
    );
    if ($repStmt !== false) {
        $repStmt->bind_param("i", $messageId);
        $repStmt->execute();
        $repRes = $repStmt->get_result();
        if ($repRes) {
            while ($rp = $repRes->fetch_assoc()) {
                $replies[] = $rp;
            }
        }
        $repStmt->close();
    }

    return ["ok" => true, "message" => $row, "replies" => $replies];
}

/**
 * @return array{ok: bool, affected: int, counts: array{unread: int, total: int}, error?: string}
 */
function admin_inbox_mark_read(mysqli $conn, int $messageId, bool $read): array
{
    $counts = admin_contact_inbox_counts($conn);
    if ($messageId <= 0) {
        return ["ok" => false, "affected" => 0, "counts" => $counts, "error" => "id_invalido"];
    }

    if ($read) {
        $stmt = $conn->prepare("UPDATE contact_messages SET is_read = 1 WHERE id = ?");
    } else {
        $stmt = $conn->prepare("UPDATE contact_messages SET is_read = 0 WHERE id = ?");
    }
    if ($stmt === false) {
        return ["ok" => false, "affected" => 0, "counts" => $counts, "error" => "prepare_failed"];
    }
    $stmt->bind_param("i", $messageId);
    $ok = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    return [
        "ok" => (bool)$ok,
        "affected" => $affected,
        "counts" => admin_contact_inbox_counts($conn),
    ];
}

/**
 * @return array{ok: bool, counts: array{unread: int, total: int}}
 */
function admin_inbox_mark_all(mysqli $conn, bool $read): array
{
    if ($read) {
        $ok = (bool)$conn->query("UPDATE contact_messages SET is_read = 1 WHERE is_read = 0");
    } else {
        $ok = (bool)$conn->query("UPDATE contact_messages SET is_read = 0 WHERE is_read = 1");
    }

    return ["ok" => $ok, "counts" => admin_contact_inbox_counts($conn)];
}

/**
 * @return array{ok: bool, error?: string}
 */
function admin_inbox_delete_message(mysqli $conn, int $messageId): array
{
    if ($messageId <= 0) {
        return ["ok" => false, "error" => "id_invalido"];
    }

    $delReplies = $conn->prepare("DELETE FROM contact_message_replies WHERE contact_message_id = ?");
    if ($delReplies !== false) {
        $delReplies->bind_param("i", $messageId);
        $delReplies->execute();
        $delReplies->close();
    }

    $stmt = $conn->prepare("DELETE FROM contact_messages WHERE id = ?");
    if ($stmt === false) {
        return ["ok" => false, "error" => "prepare_failed"];
    }
    $stmt->bind_param("i", $messageId);
    $ok = $stmt->execute();
    $stmt->close();

    return ["ok" => (bool)$ok];
}

/**
 * @return array{
 *   ok: true,
 *   reply_id: int,
 *   email_sent: bool,
 *   email_code: string,
 *   notice: string
 * }|array{ok: false, error: string, message: string}
 */
function admin_inbox_reply_message(mysqli $conn, int $messageId, string $replyBody): array
{
    $replyBody = trim($replyBody);
    if ($messageId <= 0) {
        return ["ok" => false, "error" => "id_invalido", "message" => "Mensaje no valido."];
    }
    if ($replyBody === "") {
        return ["ok" => false, "error" => "body_vacio", "message" => "Escribe el texto de la respuesta."];
    }
    if (strlen($replyBody) > 20000) {
        return ["ok" => false, "error" => "body_largo", "message" => "La respuesta es demasiado larga."];
    }

    $stmt = $conn->prepare(
        "SELECT id, nombre, email, servicio, subject, mensaje, created_at, in_reply_to, client_id FROM contact_messages WHERE id = ? LIMIT 1"
    );
    if ($stmt === false) {
        return ["ok" => false, "error" => "db_error", "message" => "No se pudo cargar el mensaje."];
    }
    $stmt->bind_param("i", $messageId);
    $stmt->execute();
    $msgRes = $stmt->get_result();
    $stmt->close();
    if (!$msgRes || $msgRes->num_rows !== 1) {
        return ["ok" => false, "error" => "not_found", "message" => "Ese mensaje ya no existe."];
    }
    $msgRow = $msgRes->fetch_assoc();
    $visitorEmail = trim((string)($msgRow["email"] ?? ""));
    if (!filter_var($visitorEmail, FILTER_VALIDATE_EMAIL)) {
        return ["ok" => false, "error" => "email_invalido", "message" => "El correo del visitante no es valido."];
    }

    $ins = $conn->prepare("INSERT INTO contact_message_replies (contact_message_id, body) VALUES (?, ?)");
    if ($ins === false) {
        return ["ok" => false, "error" => "db_insert", "message" => "No se pudo guardar la respuesta."];
    }
    $ins->bind_param("is", $messageId, $replyBody);
    if (!$ins->execute()) {
        $ins->close();
        return ["ok" => false, "error" => "db_insert", "message" => "No se pudo guardar la respuesta."];
    }
    $replyId = (int)$conn->insert_id;
    $ins->close();

    $mark = $conn->prepare("UPDATE contact_messages SET is_read = 1 WHERE id = ?");
    if ($mark !== false) {
        $mark->bind_param("i", $messageId);
        $mark->execute();
        $mark->close();
    }
    $clientUnseen = $conn->prepare("UPDATE contact_messages SET client_has_unseen_reply = 1 WHERE id = ?");
    if ($clientUnseen !== false) {
        $clientUnseen->bind_param("i", $messageId);
        $clientUnseen->execute();
        $clientUnseen->close();
    }

    $msgClientId = (int)($msgRow["client_id"] ?? 0);
    if ($msgClientId > 0) {
        $cst = $conn->prepare("SELECT email_notify_outbound FROM clients WHERE id = ? LIMIT 1");
        if ($cst !== false) {
            $cst->bind_param("i", $msgClientId);
            $cst->execute();
            $cres = $cst->get_result();
            $crow = $cres ? $cres->fetch_assoc() : null;
            $cst->close();
            if ($crow !== null && (int)($crow["email_notify_outbound"] ?? 1) === 0) {
                return [
                    "ok" => true,
                    "reply_id" => $replyId,
                    "email_sent" => false,
                    "email_code" => "skipped_client_pref",
                    "notice" => "Respuesta guardada. Este cliente no recibe correos del sitio (solo verá el mensaje en la web).",
                ];
            }
        }
    }

    $settingsRes = $conn->query("SELECT contact_email, person_name, brand_name FROM site_settings WHERE id = 1 LIMIT 1");
    $contactEmail = "";
    $personName = "";
    $brandName = "";
    if ($settingsRes && $settingsRes->num_rows === 1) {
        $srow = $settingsRes->fetch_assoc();
        $contactEmail = trim((string)($srow["contact_email"] ?? ""));
        $personName = trim((string)($srow["person_name"] ?? ""));
        $brandName = trim((string)($srow["brand_name"] ?? ""));
    }
    if ($contactEmail === "" || !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        return [
            "ok" => true,
            "reply_id" => $replyId,
            "email_sent" => false,
            "email_code" => "no_site_contact_email",
            "notice" => "Respuesta guardada en el panel. Configura un correo receptor valido en Configuracion general para poder enviar por SMTP al visitante.",
        ];
    }

    $mailConfigPath = __DIR__ . "/mail_config.php";
    $mailConfig = is_readable($mailConfigPath) ? require $mailConfigPath : [];
    $mailConfig = is_array($mailConfig) ? $mailConfig : [];
    $fromEmail = mail_config_resolve_smtp_from($mailConfig);
    if ($fromEmail === "") {
        return [
            "ok" => true,
            "reply_id" => $replyId,
            "email_sent" => false,
            "email_code" => "no_smtp_from",
            "notice" => "Respuesta guardada. En mail_config indica from_email o un username que sea un correo valido.",
        ];
    }

    $smtpHost = strtolower((string)($mailConfig["host"] ?? ""));
    $smtpUser = trim((string)($mailConfig["username"] ?? ""));
    if (!empty($mailConfig["use_smtp"]) && str_contains($smtpHost, "gmail") && strcasecmp($fromEmail, $smtpUser) !== 0) {
        return [
            "ok" => true,
            "reply_id" => $replyId,
            "email_sent" => false,
            "email_code" => "gmail_from_mismatch",
            "notice" => "Respuesta guardada. Con Gmail, username y remitente SMTP deben ser el mismo correo.",
        ];
    }

    $fromDisplayName = trim((string)($mailConfig["from_name"] ?? ""));
    if ($fromDisplayName === "") {
        $fromDisplayName = $personName !== "" ? $personName : $brandName;
    }
    if ($fromDisplayName === "") {
        $localPart = str_contains($fromEmail, "@") ? trim(explode("@", $fromEmail, 2)[0] ?? "") : "";
        $fromDisplayName = $localPart !== "" ? $localPart : "Web";
    }

    $visitorNombre = trim((string)($msgRow["nombre"] ?? ""));
    $servicio = trim((string)($msgRow["servicio"] ?? ""));
    $msgSubject = trim((string)($msgRow["subject"] ?? ""));
    $mensajeOriginal = trim((string)($msgRow["mensaje"] ?? ""));
    $createdOriginal = (string)($msgRow["created_at"] ?? "");
    $subject = "Respuesta a tu mensaje de contacto";
    if ($msgSubject !== "") {
        $subject = "Re: " . $msgSubject;
    }
    if ($brandName !== "") {
        $subject .= " (" . $brandName . ")";
    }
    $body = "Hola" . ($visitorNombre !== "" ? " " . $visitorNombre : "") . ",\n\n";
    $body .= "Gracias por escribirnos. Respondemos a continuacion:\n\n";
    $body .= $replyBody . "\n\n";
    $body .= "---\nTu mensaje (" . $createdOriginal . ")\n";
    if ($msgSubject !== "") {
        $body .= "Asunto: " . $msgSubject . "\n";
    }
    $body .= "Servicio: " . $servicio . "\n\n" . $mensajeOriginal . "\n\n---\n";
    $inReplyRow = (int)($msgRow["in_reply_to"] ?? 0);
    if ($inReplyRow > 0) {
        $body .= "Nota: este envío es un seguimiento enlazado al mensaje n.º " . $inReplyRow . " en el panel.\n\n";
    }
    $body .= "Puedes responder a este correo y llegara a nuestra bandeja.\n\n";
    $body .= app_mail_plain_text_links_footer("visitor_reply");

    $sendResult = admin_send_visitor_reply_mail(
        $mailConfig,
        $visitorEmail,
        $subject,
        $body,
        $contactEmail,
        $fromDisplayName
    );
    $sent = (bool)($sendResult["ok"] ?? false);
    if (!$sent) {
        $code = (string)($sendResult["code"] ?? "");
        $traceLine = date("c") . " admin_reply fallo To=" . $visitorEmail . " code=" . $code . "\n";
        @file_put_contents(__DIR__ . "/contact_send_trace.log", $traceLine, FILE_APPEND | LOCK_EX);
        $notice = "Respuesta guardada. No se pudo completar el envío por correo; revisa mail_config.";
        if ($code === "smtp_failed") {
            $notice = "Respuesta guardada en el panel, pero SMTP falló al enviar al visitante.";
        } elseif ($code === "php_mail_failed") {
            $notice = "Respuesta guardada. Falta SMTP completo y mail() del servidor también falló.";
        } elseif ($code === "bad_config") {
            $notice = "Respuesta guardada. Revisa correo del visitante, remitente SMTP y contacto del sitio.";
        }

        return [
            "ok" => true,
            "reply_id" => $replyId,
            "email_sent" => false,
            "email_code" => $code !== "" ? $code : "send_failed",
            "notice" => $notice,
        ];
    }

    return [
        "ok" => true,
        "reply_id" => $replyId,
        "email_sent" => true,
        "email_code" => (string)($sendResult["code"] ?? "ok"),
        "notice" => "Respuesta guardada y enviada a " . $visitorEmail . ".",
    ];
}
