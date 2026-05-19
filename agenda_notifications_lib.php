<?php
declare(strict_types=1);

require_once __DIR__ . "/app_urls.php";
require_once __DIR__ . "/smtp_mail.php";
require_once __DIR__ . "/contact_lib.php";

const AGENDA_NOTIFY_EVENT_BOOKED = "appointment_booked";
const AGENDA_NOTIFY_EVENT_CANCELLED = "appointment_cancelled";

const AGENDA_NOTIFY_CHANNEL_IN_APP_ADMIN = "in_app_admin";
const AGENDA_NOTIFY_CHANNEL_IN_APP_CLIENT = "in_app_client";
const AGENDA_NOTIFY_CHANNEL_EMAIL = "email";

const AGENDA_NOTIFY_STATUS_DELIVERED = "delivered";
const AGENDA_NOTIFY_STATUS_SKIPPED = "skipped";
const AGENDA_NOTIFY_STATUS_FAILED = "failed";

function agenda_notifications_trace(string $message): void
{
    contact_send_trace("agenda_notify: " . $message);
}

function agenda_notifications_enabled(): bool
{
    return app_feature_enabled("agenda_notifications") && app_feature_enabled("expert_agenda");
}

/**
 * @return array{person_name: string, brand_name: string, contact_email: string}
 */
function agenda_notifications_site_context(mysqli $conn): array
{
    $personName = "";
    $brandName = "";
    $contactEmail = "";
    $st = $conn->query("SELECT person_name, brand_name, contact_email FROM site_settings WHERE id = 1 LIMIT 1");
    if ($st && ($row = $st->fetch_assoc())) {
        $personName = trim((string)($row["person_name"] ?? ""));
        $brandName = trim((string)($row["brand_name"] ?? ""));
        $contactEmail = trim((string)($row["contact_email"] ?? ""));
    }

    return [
        "person_name" => $personName,
        "brand_name" => $brandName,
        "contact_email" => $contactEmail,
    ];
}

function agenda_notifications_format_range(string $startsAt, string $endsAt): string
{
    $tz = new DateTimeZone(date_default_timezone_get() ?: "UTC");
    $s = DateTimeImmutable::createFromFormat("Y-m-d H:i:s", $startsAt, $tz);
    $e = DateTimeImmutable::createFromFormat("Y-m-d H:i:s", $endsAt, $tz);
    if ($s === false) {
        return trim($startsAt);
    }
    $datePart = $s->format("d/m/Y");
    $timePart = $s->format("H:i");
    if ($e !== false) {
        $timePart .= "–" . $e->format("H:i");
    }

    return $datePart . ", " . $timePart;
}

/**
 * @return array<string, mixed>|null
 */
function agenda_notifications_load_appointment(mysqli $conn, int $appointmentId): ?array
{
    if ($appointmentId <= 0) {
        return null;
    }
    $sql = "SELECT a.id, a.expert_id, a.service_id, a.starts_at, a.ends_at, a.guest_name, a.guest_email,
                   a.guest_phone, a.notes, a.client_id, a.status,
                   e.display_name AS expert_name, e.email AS expert_email,
                   s.title AS service_title
            FROM expert_appointments a
            INNER JOIN experts e ON e.id = a.expert_id
            INNER JOIN services s ON s.id = a.service_id
            WHERE a.id = ? LIMIT 1";
    $st = $conn->prepare($sql);
    if ($st === false) {
        return null;
    }
    $st->bind_param("i", $appointmentId);
    $st->execute();
    $res = $st->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $st->close();

    return is_array($row) ? $row : null;
}

function agenda_notifications_lookup_client_id_by_email(mysqli $conn, string $email): ?int
{
    $email = strtolower(trim($email));
    if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    $st = $conn->prepare("SELECT id FROM clients WHERE LOWER(TRIM(email)) = ? AND is_active = 1 LIMIT 1");
    if ($st === false) {
        return null;
    }
    $st->bind_param("s", $email);
    $st->execute();
    $res = $st->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $st->close();
    $id = (int)($row["id"] ?? 0);

    return $id > 0 ? $id : null;
}

/** Enlaza la cita a un cliente existente por correo si aún no tiene client_id. */
function agenda_notifications_link_appointment_client(mysqli $conn, int $appointmentId, ?int $sessionClientId, string $guestEmail): void
{
    if ($appointmentId <= 0) {
        return;
    }
    $clientId = $sessionClientId !== null && $sessionClientId > 0 ? $sessionClientId : null;
    if ($clientId === null) {
        $clientId = agenda_notifications_lookup_client_id_by_email($conn, $guestEmail);
    }
    if ($clientId === null) {
        return;
    }
    $st = $conn->prepare(
        "UPDATE expert_appointments SET client_id = ? WHERE id = ? AND (client_id IS NULL OR client_id = 0) LIMIT 1"
    );
    if ($st === false) {
        return;
    }
    $st->bind_param("ii", $clientId, $appointmentId);
    $st->execute();
    $st->close();
}

/**
 * @param array<string, mixed> $row
 * @return array{
 *   site: array{person_name: string, brand_name: string, contact_email: string},
 *   brand: string,
 *   guest_name: string,
 *   guest_email: string,
 *   guest_phone: string,
 *   notes: string,
 *   expert_name: string,
 *   expert_email: string,
 *   service_title: string,
 *   when: string,
 *   appointment_id: int,
 *   client_id: int|null,
 *   guest_email_valid: bool
 * }
 */
function agenda_notifications_row_context(mysqli $conn, array $row): array
{
    $site = agenda_notifications_site_context($conn);
    $brand = $site["brand_name"] !== "" ? $site["brand_name"] : $site["person_name"];
    if ($brand === "") {
        $brand = "el sitio";
    }
    $guestEmail = trim((string)($row["guest_email"] ?? ""));
    $clientId = (int)($row["client_id"] ?? 0);

    return [
        "site" => $site,
        "brand" => $brand,
        "guest_name" => trim((string)($row["guest_name"] ?? "")),
        "guest_email" => $guestEmail,
        "guest_phone" => trim((string)($row["guest_phone"] ?? "")),
        "notes" => trim((string)($row["notes"] ?? "")),
        "expert_name" => trim((string)($row["expert_name"] ?? "")),
        "expert_email" => trim((string)($row["expert_email"] ?? "")),
        "service_title" => trim((string)($row["service_title"] ?? "")),
        "when" => agenda_notifications_format_range(
            (string)($row["starts_at"] ?? ""),
            (string)($row["ends_at"] ?? "")
        ),
        "appointment_id" => (int)($row["id"] ?? 0),
        "client_id" => $clientId > 0 ? $clientId : null,
        "guest_email_valid" => $guestEmail !== "" && filter_var($guestEmail, FILTER_VALIDATE_EMAIL),
    ];
}

/**
 * @return array{delivery_id: int, channel: string, recipient_role: string, status: string, status_detail: string|null}
 */
function agenda_notifications_record_delivery(
    mysqli $conn,
    int $appointmentId,
    string $eventType,
    string $channel,
    string $recipientRole,
    ?int $clientId,
    ?string $recipientEmail,
    string $title,
    string $body,
    string $status,
    ?string $statusDetail,
    bool $markRead = false
): array {
    $recipientEmail = $recipientEmail !== null ? trim($recipientEmail) : null;
    if ($recipientEmail === "") {
        $recipientEmail = null;
    }
    $clientBind = $clientId !== null && $clientId > 0 ? $clientId : null;
    $isRead = $markRead ? 1 : 0;
    $detail = $statusDetail !== null && $statusDetail !== "" ? $statusDetail : null;

    $st = $conn->prepare(
        "INSERT INTO agenda_notification_deliveries
         (appointment_id, event_type, channel, recipient_role, client_id, recipient_email, title, body, status, status_detail, is_read)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if ($st === false) {
        agenda_notifications_trace("INSERT delivery falló appt=" . $appointmentId);
        return [
            "delivery_id" => 0,
            "channel" => $channel,
            "recipient_role" => $recipientRole,
            "status" => $status,
            "status_detail" => "db_insert_failed",
        ];
    }
    $st->bind_param(
        "isssisssssi",
        $appointmentId,
        $eventType,
        $channel,
        $recipientRole,
        $clientBind,
        $recipientEmail,
        $title,
        $body,
        $status,
        $detail,
        $isRead
    );
    if (!$st->execute()) {
        $st->close();
        return [
            "delivery_id" => 0,
            "channel" => $channel,
            "recipient_role" => $recipientRole,
            "status" => $status,
            "status_detail" => "db_insert_failed",
        ];
    }
    $deliveryId = (int)$conn->insert_id;
    $st->close();

    return [
        "delivery_id" => $deliveryId,
        "channel" => $channel,
        "recipient_role" => $recipientRole,
        "status" => $status,
        "status_detail" => $detail,
    ];
}

function agenda_notifications_build_messages(array $ctx, bool $isCancel): array
{
    $svc = $ctx["service_title"] !== "" ? $ctx["service_title"] : "—";
    $exp = $ctx["expert_name"] !== "" ? $ctx["expert_name"] : "—";
    $contactLine = "";
    if ($ctx["guest_email_valid"]) {
        $contactLine .= "Correo: " . $ctx["guest_email"] . "\n";
    }
    if ($ctx["guest_phone"] !== "") {
        $contactLine .= "Teléfono: " . $ctx["guest_phone"] . "\n";
    }
    if ($contactLine === "") {
        $contactLine = "Sin correo ni teléfono en la reserva.\n";
    }

    if ($isCancel) {
        $adminTitle = "Cita cancelada — " . $svc;
        $adminBody = "Se canceló una cita (ref. #" . $ctx["appointment_id"] . ").\n\n"
            . "Servicio: {$svc}\nExperto: {$exp}\nFecha: " . $ctx["when"] . "\n"
            . "Cliente: " . $ctx["guest_name"] . "\n" . $contactLine;
        $guestTitle = "Tu cita ha sido cancelada — " . $ctx["brand"];
        $guestBody = "Hola " . $ctx["guest_name"] . ",\n\nTu cita ha sido cancelada:\n\n"
            . "Servicio: {$svc}\nProfesional: {$exp}\nFecha: " . $ctx["when"] . "\n\n"
            . "Si tienes dudas, contáctanos.\n";
    } else {
        $adminTitle = "Nueva cita — " . $svc;
        $adminBody = "Nueva reserva (ref. #" . $ctx["appointment_id"] . ").\n\n"
            . "Servicio: {$svc}\nExperto: {$exp}\nFecha: " . $ctx["when"] . "\n"
            . "Cliente: " . $ctx["guest_name"] . "\n" . $contactLine;
        if ($ctx["notes"] !== "") {
            $adminBody .= "\nNotas:\n" . $ctx["notes"] . "\n";
        }
        if ($ctx["client_id"] === null) {
            $adminBody .= "\nSin cuenta de cliente vinculada";
            if ($ctx["guest_email_valid"]) {
                $adminBody .= " (el visitante puede registrarse con el mismo correo).";
            }
            $adminBody .= "\n";
        }
        $guestTitle = "Cita confirmada — " . $ctx["brand"];
        $guestBody = "Hola " . $ctx["guest_name"] . ",\n\nTu cita quedó registrada:\n\n"
            . "Servicio: {$svc}\nProfesional: {$exp}\nFecha: " . $ctx["when"] . "\n";
        if ($ctx["notes"] !== "") {
            $guestBody .= "\nTus notas:\n" . $ctx["notes"] . "\n";
        }
        $guestBody .= "\nPara ver avisos en la web, entra en el área de clientes con este correo.\n";
    }

    $expertTitle = ($isCancel ? "Cita cancelada" : "Nueva cita") . " — " . $ctx["when"];
    $expertBody = ($isCancel ? "Se canceló" : "Tienes") . " una cita:\n\n"
        . "Servicio: {$svc}\nFecha: " . $ctx["when"] . "\n"
        . "Cliente: " . $ctx["guest_name"] . "\n" . $contactLine;

    return [
        "admin" => ["title" => $adminTitle, "body" => $adminBody],
        "guest" => ["title" => $guestTitle, "body" => $guestBody],
        "expert" => ["title" => $expertTitle, "body" => $expertBody],
    ];
}

function agenda_notifications_mail_from_name(array $mailConfig, string $personName, string $brandName): string
{
    $fromDisplayName = trim((string)($mailConfig["from_name"] ?? ""));
    if ($fromDisplayName !== "") {
        return $fromDisplayName;
    }
    if ($personName !== "") {
        return $personName;
    }
    if ($brandName !== "") {
        return $brandName;
    }

    return "Agenda";
}

function agenda_mail_send_plain(mysqli $conn, string $to, string $subject, string $body, string $replyTo): bool
{
    $to = trim($to);
    if ($to === "" || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $mailConfig = contact_load_mail_config();
    $site = agenda_notifications_site_context($conn);
    $contactEmail = $site["contact_email"];
    if ($contactEmail === "" || !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $useSmtp = !empty($mailConfig["use_smtp"]);
    $smtpFromResolved = mail_config_resolve_smtp_from($mailConfig);
    $smtpReady = $useSmtp
        && !empty($mailConfig["host"])
        && !empty($mailConfig["username"])
        && !empty($mailConfig["password"])
        && $smtpFromResolved !== "";

    $fromForPhpMail = $smtpFromResolved !== "" ? $smtpFromResolved : $contactEmail;
    $fromDisplayName = agenda_notifications_mail_from_name(
        $mailConfig,
        $site["person_name"],
        $site["brand_name"]
    );
    $fromHeaderLine = smtp_format_from_header($fromDisplayName, $fromForPhpMail);

    $replyTo = trim($replyTo);
    if ($replyTo === "" || !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $replyTo = $contactEmail;
    }

    $headers = "From: " . $fromHeaderLine . "\r\n";
    $headers .= "Reply-To: " . $replyTo . "\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "MIME-Version: 1.0\r\n";

    $mailSent = false;
    if ($smtpReady) {
        $smtpCfg = $mailConfig;
        $smtpCfg["from_email"] = $smtpFromResolved;
        $smtpCfg["from_name"] = $fromDisplayName;
        $mailSent = send_mail_smtp($smtpCfg, $to, $subject, $body, $replyTo);
    }
    if (!$mailSent) {
        $mailSent = @mail($to, $subject, $body, $headers);
    }

    return $mailSent;
}

function agenda_notifications_guest_may_receive_email(mysqli $conn, ?int $clientId, string $guestEmail): bool
{
    if ($guestEmail === "" || !filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    if ($clientId === null || $clientId <= 0) {
        return true;
    }
    $st = $conn->prepare("SELECT email, email_notify_outbound FROM clients WHERE id = ? LIMIT 1");
    if ($st === false) {
        return true;
    }
    $st->bind_param("i", $clientId);
    $st->execute();
    $res = $st->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $st->close();
    if ($row === null) {
        return true;
    }
    if ((int)($row["email_notify_outbound"] ?? 1) === 0) {
        return false;
    }
    $clientEmail = strtolower(trim((string)($row["email"] ?? "")));

    return $clientEmail === "" || strtolower($guestEmail) === $clientEmail;
}

/**
 * @param array<string, mixed> $ctx
 * @return list<array{delivery_id: int, channel: string, recipient_role: string, status: string, status_detail: string|null}>
 */
function agenda_notifications_process_event(mysqli $conn, array $row, string $eventType): array
{
    $deliveries = [];
    if (!agenda_notifications_enabled()) {
        return $deliveries;
    }

    $isCancel = $eventType === AGENDA_NOTIFY_EVENT_CANCELLED;
    $ctx = agenda_notifications_row_context($conn, $row);
    $msgs = agenda_notifications_build_messages($ctx, $isCancel);
    $apptId = $ctx["appointment_id"];
    $site = $ctx["site"];
    $adminEmail = $site["contact_email"];

    $deliveries[] = agenda_notifications_record_delivery(
        $conn,
        $apptId,
        $eventType,
        AGENDA_NOTIFY_CHANNEL_IN_APP_ADMIN,
        "admin",
        null,
        $adminEmail !== "" ? $adminEmail : null,
        $msgs["admin"]["title"],
        $msgs["admin"]["body"],
        AGENDA_NOTIFY_STATUS_DELIVERED,
        "in_app_created",
        false
    );

    if ($ctx["client_id"] !== null && $ctx["client_id"] > 0) {
        $deliveries[] = agenda_notifications_record_delivery(
            $conn,
            $apptId,
            $eventType,
            AGENDA_NOTIFY_CHANNEL_IN_APP_CLIENT,
            "client",
            $ctx["client_id"],
            $ctx["guest_email_valid"] ? $ctx["guest_email"] : null,
            $msgs["guest"]["title"],
            $msgs["guest"]["body"],
            AGENDA_NOTIFY_STATUS_DELIVERED,
            "in_app_created",
            false
        );
    } else {
        $detail = $ctx["guest_email_valid"]
            ? "no_client_account"
            : "no_client_account_no_email";
        $deliveries[] = agenda_notifications_record_delivery(
            $conn,
            $apptId,
            $eventType,
            AGENDA_NOTIFY_CHANNEL_IN_APP_CLIENT,
            "client",
            null,
            $ctx["guest_email_valid"] ? $ctx["guest_email"] : null,
            $msgs["guest"]["title"],
            $msgs["guest"]["body"],
            AGENDA_NOTIFY_STATUS_SKIPPED,
            $detail,
            true
        );
    }

    if ($adminEmail !== "" && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $body = $msgs["admin"]["body"] . app_mail_plain_text_links_footer("admin_notify");
        $ok = agenda_mail_send_plain($conn, $adminEmail, $msgs["admin"]["title"], $body, $ctx["guest_email_valid"] ? $ctx["guest_email"] : $adminEmail);
        $deliveries[] = agenda_notifications_record_delivery(
            $conn,
            $apptId,
            $eventType,
            AGENDA_NOTIFY_CHANNEL_EMAIL,
            "admin",
            null,
            $adminEmail,
            $msgs["admin"]["title"],
            $body,
            $ok ? AGENDA_NOTIFY_STATUS_DELIVERED : AGENDA_NOTIFY_STATUS_FAILED,
            $ok ? "email_sent" : "smtp_failed",
            true
        );
    } else {
        $deliveries[] = agenda_notifications_record_delivery(
            $conn,
            $apptId,
            $eventType,
            AGENDA_NOTIFY_CHANNEL_EMAIL,
            "admin",
            null,
            null,
            $msgs["admin"]["title"],
            $msgs["admin"]["body"],
            AGENDA_NOTIFY_STATUS_SKIPPED,
            "no_site_contact_email",
            true
        );
    }

    if ($ctx["guest_email_valid"] && agenda_notifications_guest_may_receive_email($conn, $ctx["client_id"], $ctx["guest_email"])) {
        $body = $msgs["guest"]["body"] . app_mail_plain_text_links_footer("agenda_guest");
        $replyTo = $adminEmail !== "" ? $adminEmail : $ctx["guest_email"];
        $ok = agenda_mail_send_plain($conn, $ctx["guest_email"], $msgs["guest"]["title"], $body, $replyTo);
        $deliveries[] = agenda_notifications_record_delivery(
            $conn,
            $apptId,
            $eventType,
            AGENDA_NOTIFY_CHANNEL_EMAIL,
            "guest",
            $ctx["client_id"],
            $ctx["guest_email"],
            $msgs["guest"]["title"],
            $body,
            $ok ? AGENDA_NOTIFY_STATUS_DELIVERED : AGENDA_NOTIFY_STATUS_FAILED,
            $ok ? "email_sent" : "smtp_failed",
            true
        );
    } else {
        $detail = !$ctx["guest_email_valid"]
            ? "no_valid_guest_email"
            : "guest_email_notify_disabled";
        $deliveries[] = agenda_notifications_record_delivery(
            $conn,
            $apptId,
            $eventType,
            AGENDA_NOTIFY_CHANNEL_EMAIL,
            "guest",
            $ctx["client_id"],
            $ctx["guest_email"] !== "" ? $ctx["guest_email"] : null,
            $msgs["guest"]["title"],
            $msgs["guest"]["body"],
            AGENDA_NOTIFY_STATUS_SKIPPED,
            $detail,
            true
        );
    }

    $expertEmail = $ctx["expert_email"];
    if ($expertEmail !== "" && filter_var($expertEmail, FILTER_VALIDATE_EMAIL)) {
        $adminNorm = strtolower($adminEmail);
        $expertNorm = strtolower($expertEmail);
        if ($expertNorm !== $adminNorm) {
            $body = $msgs["expert"]["body"] . app_mail_plain_text_links_footer("admin_notify");
            $ok = agenda_mail_send_plain($conn, $expertEmail, $msgs["expert"]["title"], $body, $ctx["guest_email_valid"] ? $ctx["guest_email"] : $adminEmail);
            $deliveries[] = agenda_notifications_record_delivery(
                $conn,
                $apptId,
                $eventType,
                AGENDA_NOTIFY_CHANNEL_EMAIL,
                "expert",
                null,
                $expertEmail,
                $msgs["expert"]["title"],
                $body,
                $ok ? AGENDA_NOTIFY_STATUS_DELIVERED : AGENDA_NOTIFY_STATUS_FAILED,
                $ok ? "email_sent" : "smtp_failed",
                true
            );
        } else {
            $deliveries[] = agenda_notifications_record_delivery(
                $conn,
                $apptId,
                $eventType,
                AGENDA_NOTIFY_CHANNEL_EMAIL,
                "expert",
                null,
                $expertEmail,
                $msgs["expert"]["title"],
                $msgs["expert"]["body"],
                AGENDA_NOTIFY_STATUS_SKIPPED,
                "same_as_site_contact",
                true
            );
        }
    } else {
        $deliveries[] = agenda_notifications_record_delivery(
            $conn,
            $apptId,
            $eventType,
            AGENDA_NOTIFY_CHANNEL_EMAIL,
            "expert",
            null,
            null,
            $msgs["expert"]["title"],
            $msgs["expert"]["body"],
            AGENDA_NOTIFY_STATUS_SKIPPED,
            "no_expert_email",
            true
        );
    }

    return $deliveries;
}

/**
 * @return array{
 *   guest: bool,
 *   admin: bool,
 *   expert: bool,
 *   skipped_guest: bool,
 *   in_app_admin: bool,
 *   in_app_client: bool,
 *   deliveries: list<array<string, mixed>>
 * }
 */
function agenda_notifications_summarize_deliveries(array $deliveries): array
{
    $summary = [
        "guest" => false,
        "admin" => false,
        "expert" => false,
        "skipped_guest" => false,
        "in_app_admin" => false,
        "in_app_client" => false,
        "deliveries" => $deliveries,
    ];
    foreach ($deliveries as $d) {
        $ch = (string)($d["channel"] ?? "");
        $role = (string)($d["recipient_role"] ?? "");
        $status = (string)($d["status"] ?? "");
        if ($ch === AGENDA_NOTIFY_CHANNEL_IN_APP_ADMIN && $status === AGENDA_NOTIFY_STATUS_DELIVERED) {
            $summary["in_app_admin"] = true;
        }
        if ($ch === AGENDA_NOTIFY_CHANNEL_IN_APP_CLIENT && $status === AGENDA_NOTIFY_STATUS_DELIVERED) {
            $summary["in_app_client"] = true;
        }
        if ($ch === AGENDA_NOTIFY_CHANNEL_EMAIL && $status === AGENDA_NOTIFY_STATUS_DELIVERED) {
            if ($role === "guest") {
                $summary["guest"] = true;
            } elseif ($role === "admin") {
                $summary["admin"] = true;
            } elseif ($role === "expert") {
                $summary["expert"] = true;
            }
        }
        if ($ch === AGENDA_NOTIFY_CHANNEL_EMAIL && $role === "guest" && $status === AGENDA_NOTIFY_STATUS_SKIPPED) {
            $summary["skipped_guest"] = true;
        }
    }

    return $summary;
}

function agenda_notifications_send_booking(mysqli $conn, int $appointmentId): array
{
    $empty = agenda_notifications_summarize_deliveries([]);
    $row = agenda_notifications_load_appointment($conn, $appointmentId);
    if ($row === null || (string)($row["status"] ?? "") !== "confirmed") {
        return $empty;
    }

    return agenda_notifications_summarize_deliveries(
        agenda_notifications_process_event($conn, $row, AGENDA_NOTIFY_EVENT_BOOKED)
    );
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function agenda_notifications_send_cancel(mysqli $conn, array $row): array
{
    return agenda_notifications_summarize_deliveries(
        agenda_notifications_process_event($conn, $row, AGENDA_NOTIFY_EVENT_CANCELLED)
    );
}

function agenda_notifications_status_label(string $status, ?string $detail): string
{
    $detail = $detail ?? "";
    if ($status === AGENDA_NOTIFY_STATUS_DELIVERED) {
        if ($detail === "in_app_created") {
            return "En el panel";
        }
        if ($detail === "email_sent") {
            return "Correo enviado";
        }

        return "Entregado";
    }
    if ($status === AGENDA_NOTIFY_STATUS_FAILED) {
        return "Falló el envío";
    }

    $skipped = [
        "no_valid_guest_email" => "Sin correo válido",
        "no_client_account" => "Sin cuenta de cliente",
        "no_client_account_no_email" => "Sin cuenta ni correo",
        "guest_email_notify_disabled" => "Cliente sin correos",
        "no_site_contact_email" => "Sin email del sitio",
        "no_expert_email" => "Experto sin email",
        "same_as_site_contact" => "Mismo email que el sitio",
        "smtp_failed" => "SMTP falló",
    ];

    return $skipped[$detail] ?? "No enviado";
}

function agenda_notifications_channel_label(string $channel, string $role): string
{
    if ($channel === AGENDA_NOTIFY_CHANNEL_IN_APP_ADMIN) {
        return "Panel admin";
    }
    if ($channel === AGENDA_NOTIFY_CHANNEL_IN_APP_CLIENT) {
        return "Área cliente";
    }
    if ($role === "guest") {
        return "Correo visitante";
    }
    if ($role === "expert") {
        return "Correo experto";
    }

    return "Correo sitio";
}

function agenda_notifications_count_admin_unread(mysqli $conn): int
{
    $st = $conn->query(
        "SELECT COUNT(*) AS c FROM agenda_notification_deliveries
         WHERE channel = '" . AGENDA_NOTIFY_CHANNEL_IN_APP_ADMIN . "'
           AND recipient_role = 'admin' AND is_read = 0"
    );
    if (!$st || !($row = $st->fetch_assoc())) {
        return 0;
    }

    return (int)($row["c"] ?? 0);
}

/**
 * @return list<array<string, mixed>>
 */
function agenda_notifications_list_admin(mysqli $conn, int $limit = 80): array
{
    $limit = max(1, min(200, $limit));
    $sql = "SELECT d.*, a.starts_at, a.guest_name, a.guest_email, a.guest_phone, e.display_name AS expert_name, s.title AS service_title
            FROM agenda_notification_deliveries d
            INNER JOIN expert_appointments a ON a.id = d.appointment_id
            INNER JOIN experts e ON e.id = a.expert_id
            INNER JOIN services s ON s.id = a.service_id
            WHERE d.channel = ?
            ORDER BY d.created_at DESC, d.id DESC
            LIMIT " . $limit;
    $st = $conn->prepare($sql);
    if ($st === false) {
        return [];
    }
    $ch = AGENDA_NOTIFY_CHANNEL_IN_APP_ADMIN;
    $st->bind_param("s", $ch);
    $st->execute();
    $res = $st->get_result();
    $rows = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $rows[] = $row;
    }
    $st->close();

    return $rows;
}

/**
 * Línea de tiempo de reservas y cancelaciones para el panel lateral del admin.
 *
 * @return list<array{
 *   event_key: string,
 *   appointment_id: int,
 *   event_type: string,
 *   event_at: string|null,
 *   starts_at: string,
 *   guest_name: string,
 *   guest_email: string,
 *   expert_name: string,
 *   expert_email: string,
 *   service_title: string,
 *   service_icon_class: string,
 *   appointment_status: string,
 *   deliveries: list<array<string, mixed>>
 * }>
 */
function agenda_appointment_history_timeline(mysqli $conn, int $limitEvents = 50): array
{
    $limitEvents = max(1, min(100, $limitEvents));
    $apptSql = "SELECT a.id, a.starts_at, a.guest_name, a.guest_email, a.status, a.created_at,
                       e.display_name AS expert_name, e.email AS expert_email,
                       s.title AS service_title, s.icon_class AS service_icon_class
                FROM expert_appointments a
                INNER JOIN experts e ON e.id = a.expert_id
                INNER JOIN services s ON s.id = a.service_id
                ORDER BY a.created_at DESC
                LIMIT 120";
    $apptRes = $conn->query($apptSql);
    if ($apptRes === false) {
        return [];
    }
    $appointments = [];
    $apptIds = [];
    while ($row = $apptRes->fetch_assoc()) {
        $aid = (int)($row["id"] ?? 0);
        if ($aid <= 0) {
            continue;
        }
        $appointments[] = $row;
        $apptIds[] = $aid;
    }
    $apptRes->free();
    if (count($appointments) === 0) {
        return [];
    }

    $deliveriesByKey = [];
    $placeholders = implode(",", array_fill(0, count($apptIds), "?"));
    $delSql = "SELECT id, appointment_id, event_type, channel, recipient_role, recipient_email,
                      status, status_detail, created_at
               FROM agenda_notification_deliveries
               WHERE appointment_id IN ($placeholders)
               ORDER BY created_at ASC, id ASC";
    $delSt = $conn->prepare($delSql);
    if ($delSt !== false) {
        $types = str_repeat("i", count($apptIds));
        $delSt->bind_param($types, ...$apptIds);
        $delSt->execute();
        $delRes = $delSt->get_result();
        while ($delRes && ($d = $delRes->fetch_assoc())) {
            $key = (int)($d["appointment_id"] ?? 0) . "_" . (string)($d["event_type"] ?? "");
            if (!isset($deliveriesByKey[$key])) {
                $deliveriesByKey[$key] = [];
            }
            $deliveriesByKey[$key][] = $d;
        }
        $delSt->close();
    }

    $events = [];
    foreach ($appointments as $a) {
        $aid = (int)($a["id"] ?? 0);
        if ($aid <= 0) {
            continue;
        }
        $meta = [
            "appointment_id" => $aid,
            "starts_at" => (string)($a["starts_at"] ?? ""),
            "guest_name" => (string)($a["guest_name"] ?? ""),
            "guest_email" => (string)($a["guest_email"] ?? ""),
            "expert_name" => (string)($a["expert_name"] ?? ""),
            "expert_email" => (string)($a["expert_email"] ?? ""),
            "service_title" => (string)($a["service_title"] ?? ""),
            "service_icon_class" => (string)($a["service_icon_class"] ?? ""),
            "appointment_status" => (string)($a["status"] ?? ""),
        ];
        $bookedKey = $aid . "_" . AGENDA_NOTIFY_EVENT_BOOKED;
        $bookedDeliveries = $deliveriesByKey[$bookedKey] ?? [];
        $events[] = array_merge($meta, [
            "event_key" => $bookedKey,
            "event_type" => AGENDA_NOTIFY_EVENT_BOOKED,
            "event_at" => (string)($a["created_at"] ?? ""),
            "deliveries" => $bookedDeliveries,
        ]);
        if ((string)($a["status"] ?? "") === "cancelled") {
            $cancelKey = $aid . "_" . AGENDA_NOTIFY_EVENT_CANCELLED;
            $cancelDeliveries = $deliveriesByKey[$cancelKey] ?? [];
            $cancelAt = null;
            if (count($cancelDeliveries) > 0) {
                $cancelAt = (string)($cancelDeliveries[0]["created_at"] ?? "");
            }
            $events[] = array_merge($meta, [
                "event_key" => $cancelKey,
                "event_type" => AGENDA_NOTIFY_EVENT_CANCELLED,
                "event_at" => $cancelAt,
                "deliveries" => $cancelDeliveries,
            ]);
        }
    }

    usort($events, static function (array $a, array $b): int {
        $ta = strtotime((string)($a["event_at"] ?? "")) ?: 0;
        $tb = strtotime((string)($b["event_at"] ?? "")) ?: 0;
        if ($ta === $tb) {
            return strcmp((string)($b["event_key"] ?? ""), (string)($a["event_key"] ?? ""));
        }

        return $tb <=> $ta;
    });

    return array_slice($events, 0, $limitEvents);
}

/** @deprecated Usar agenda_appointment_history_timeline() */
function agenda_notifications_delivery_log_groups(mysqli $conn, int $limitAppointments = 40): array
{
    return agenda_appointment_history_timeline($conn, $limitAppointments * 2);
}

function agenda_notifications_list_for_appointment(mysqli $conn, int $appointmentId): array
{
    if ($appointmentId <= 0) {
        return [];
    }
    $st = $conn->prepare(
        "SELECT * FROM agenda_notification_deliveries WHERE appointment_id = ? ORDER BY created_at ASC, id ASC"
    );
    if ($st === false) {
        return [];
    }
    $st->bind_param("i", $appointmentId);
    $st->execute();
    $res = $st->get_result();
    $rows = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $rows[] = $row;
    }
    $st->close();

    return $rows;
}

function agenda_notifications_mark_admin_read(mysqli $conn, ?int $deliveryId = null): void
{
    if ($deliveryId !== null && $deliveryId > 0) {
        $st = $conn->prepare(
            "UPDATE agenda_notification_deliveries SET is_read = 1, read_at = NOW()
             WHERE id = ? AND channel = ? AND recipient_role = 'admin' LIMIT 1"
        );
        if ($st !== false) {
            $ch = AGENDA_NOTIFY_CHANNEL_IN_APP_ADMIN;
            $st->bind_param("is", $deliveryId, $ch);
            $st->execute();
            $st->close();
        }
        return;
    }
    $conn->query(
        "UPDATE agenda_notification_deliveries SET is_read = 1, read_at = NOW()
         WHERE channel = '" . AGENDA_NOTIFY_CHANNEL_IN_APP_ADMIN . "' AND recipient_role = 'admin' AND is_read = 0"
    );
}

function agenda_notifications_count_client_unread(mysqli $conn, int $clientId): int
{
    if ($clientId <= 0) {
        return 0;
    }
    $st = $conn->prepare(
        "SELECT COUNT(*) AS c FROM agenda_notification_deliveries
         WHERE channel = ? AND client_id = ? AND is_read = 0 AND status = ?"
    );
    if ($st === false) {
        return 0;
    }
    $ch = AGENDA_NOTIFY_CHANNEL_IN_APP_CLIENT;
    $delivered = AGENDA_NOTIFY_STATUS_DELIVERED;
    $st->bind_param("sis", $ch, $clientId, $delivered);
    $st->execute();
    $res = $st->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $st->close();

    return (int)($row["c"] ?? 0);
}

/**
 * @return list<array<string, mixed>>
 */
function agenda_notifications_list_client(mysqli $conn, int $clientId, int $limit = 50): array
{
    if ($clientId <= 0) {
        return [];
    }
    $limit = max(1, min(100, $limit));
    $sql = "SELECT d.*, a.starts_at, a.guest_name, e.display_name AS expert_name, s.title AS service_title
            FROM agenda_notification_deliveries d
            INNER JOIN expert_appointments a ON a.id = d.appointment_id
            INNER JOIN experts e ON e.id = a.expert_id
            INNER JOIN services s ON s.id = a.service_id
            WHERE d.channel = ? AND d.client_id = ? AND d.status = ?
            ORDER BY d.created_at DESC, d.id DESC
            LIMIT " . $limit;
    $st = $conn->prepare($sql);
    if ($st === false) {
        return [];
    }
    $ch = AGENDA_NOTIFY_CHANNEL_IN_APP_CLIENT;
    $delivered = AGENDA_NOTIFY_STATUS_DELIVERED;
    $st->bind_param("sis", $ch, $clientId, $delivered);
    $st->execute();
    $res = $st->get_result();
    $rows = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $rows[] = $row;
    }
    $st->close();

    return $rows;
}

function agenda_notifications_mark_client_read(mysqli $conn, int $clientId, ?int $deliveryId = null): void
{
    if ($clientId <= 0) {
        return;
    }
    if ($deliveryId !== null && $deliveryId > 0) {
        $st = $conn->prepare(
            "UPDATE agenda_notification_deliveries SET is_read = 1, read_at = NOW()
             WHERE id = ? AND client_id = ? AND channel = ? LIMIT 1"
        );
        if ($st !== false) {
            $ch = AGENDA_NOTIFY_CHANNEL_IN_APP_CLIENT;
            $st->bind_param("iis", $deliveryId, $clientId, $ch);
            $st->execute();
            $st->close();
        }
        return;
    }
    $st = $conn->prepare(
        "UPDATE agenda_notification_deliveries SET is_read = 1, read_at = NOW()
         WHERE client_id = ? AND channel = ? AND is_read = 0"
    );
    if ($st !== false) {
        $ch = AGENDA_NOTIFY_CHANNEL_IN_APP_CLIENT;
        $st->bind_param("is", $clientId, $ch);
        $st->execute();
        $st->close();
    }
}
