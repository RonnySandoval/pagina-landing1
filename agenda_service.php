<?php
declare(strict_types=1);

require_once __DIR__ . "/app_urls.php";
require_once __DIR__ . "/agenda_lib.php";
require_once __DIR__ . "/agenda_public_bootstrap.php";
require_once __DIR__ . "/agenda_notifications_lib.php";

/**
 * @return array{expert_id: int, starts_at: string}|null
 */
function agenda_service_parse_slot_token(string $token): ?array
{
    $token = trim($token);
    $p = strpos($token, "@");
    if ($p === false) {
        return null;
    }
    $expertId = (int)substr($token, 0, $p);
    $startsAt = trim(substr($token, $p + 1));
    if ($expertId <= 0 || $startsAt === "") {
        return null;
    }

    return ["expert_id" => $expertId, "starts_at" => $startsAt];
}

function agenda_service_slot_token(int $expertId, string $startsAt): string
{
    return $expertId . "@" . $startsAt;
}

/**
 * @param array<string, string|int> $slots
 * @return list<array<string, mixed>>
 */
function agenda_service_slots_with_tokens(array $slots): array
{
    $out = [];
    foreach ($slots as $s) {
        $eid = (int)($s["expert_id"] ?? 0);
        $starts = (string)($s["starts"] ?? "");
        $row = $s;
        $row["slot_token"] = agenda_service_slot_token($eid, $starts);
        $out[] = $row;
    }

    return $out;
}

/**
 * Huecos y metadatos para un servicio y día (API o UI).
 *
 * @param array{agenda_service?: int|string, service_id?: int|string, agenda_date?: string, date?: string} $query
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string}
 */
function agenda_service_get_slots(mysqli $conn, array $query): array
{
    if (!app_feature_enabled("expert_agenda")) {
        return ["ok" => false, "error" => "feature_disabled"];
    }

    $get = [
        "agenda_service" => $query["agenda_service"] ?? $query["service_id"] ?? 0,
        "agenda_date" => $query["agenda_date"] ?? $query["date"] ?? "",
    ];

    $state = agenda_public_load_state($conn, $get, true);
    if (!$state["publicExpertAgenda"]) {
        return ["ok" => false, "error" => "feature_disabled"];
    }

    $services = [];
    foreach ($state["agendaBookableServices"] as $svc) {
        $services[] = [
            "id" => (int)($svc["id"] ?? 0),
            "title" => (string)($svc["title"] ?? ""),
            "icon_class" => (string)($svc["icon_class"] ?? ""),
        ];
    }

    $slots = agenda_service_slots_with_tokens($state["agendaSlots"]);

    return [
        "ok" => true,
        "data" => [
            "service_id" => (int)$state["agendaSelectedServiceId"],
            "service_title" => (string)$state["agendaSelectedServiceTitle"],
            "date" => (string)$state["agendaSelectedDate"],
            "min_date" => (string)$state["agendaMinDate"],
            "max_date" => (string)$state["agendaMaxDate"],
            "show_expert_names" => (bool)$state["agendaShowExpertNames"],
            "slot_minutes" => AGENDA_SLOT_MINUTES,
            "max_slot_units" => AGENDA_MAX_SLOT_UNITS,
            "bookable_services" => $services,
            "slots" => $slots,
            "table" => $state["agendaSlotTable"],
        ],
    ];
}

function agenda_service_booking_http_status(string $message): int
{
    if (
        str_contains($message, "ocup")
        || str_contains($message, "disponible")
        || str_contains($message, "hueco")
    ) {
        return 409;
    }
    if (str_contains($message, "No se pudo guardar") || str_contains($message, "No se pudo validar")) {
        return 500;
    }

    return 400;
}

/**
 * @param array<string, mixed> $input
 * @param array{client_id?: int|null} $context
 * @return array{
 *   ok: true,
 *   appointment_id: int,
 *   service_id: int,
 *   expert_id: int,
 *   starts_at: string,
 *   ends_at: string,
 *   slot_units: int,
 *   notifications: array{guest: bool, admin: bool, expert: bool, skipped_guest: bool}
 * } | array{ok: false, error: string, message: string}
 */
function agenda_service_create_booking(mysqli $conn, array $input, array $context = []): array
{
    if (!app_feature_enabled("expert_agenda")) {
        return ["ok" => false, "error" => "feature_disabled", "message" => "La agenda no está activa en esta instalación."];
    }

    $serviceId = (int)($input["service_id"] ?? $input["agenda_service_id"] ?? 0);
    $slotTok = trim((string)($input["agenda_slot"] ?? $input["slot_token"] ?? ""));
    $expertId = (int)($input["expert_id"] ?? 0);
    $startsAt = trim((string)($input["starts_at"] ?? ""));

    if ($slotTok !== "") {
        $parsed = agenda_service_parse_slot_token($slotTok);
        if ($parsed === null) {
            return [
                "ok" => false,
                "error" => "invalid_slot_token",
                "message" => "El identificador de hueco no es válido.",
            ];
        }
        $expertId = $parsed["expert_id"];
        $startsAt = $parsed["starts_at"];
    }

    $guestName = (string)($input["guest_name"] ?? "");
    $guestEmail = (string)($input["guest_email"] ?? "");
    $guestPhone = (string)($input["guest_phone"] ?? "");
    $notes = (string)($input["agenda_notes"] ?? $input["notes"] ?? "");
    $slotUnits = (int)($input["agenda_slot_units"] ?? $input["slot_units"] ?? 1);
    if ($slotUnits < 1) {
        $slotUnits = 1;
    }

    $clientId = $context["client_id"] ?? null;
    if ($clientId !== null) {
        $clientId = (int)$clientId;
        if ($clientId <= 0) {
            $clientId = null;
        }
    }

    $appointmentId = null;
    $err = agenda_try_insert_booking(
        $conn,
        $serviceId,
        $expertId,
        $startsAt,
        $guestName,
        $guestEmail,
        $guestPhone,
        $notes,
        $clientId,
        $slotUnits,
        $appointmentId
    );

    if ($err !== null) {
        return ["ok" => false, "error" => "booking_rejected", "message" => $err];
    }

    $tz = new DateTimeZone(date_default_timezone_get() ?: "UTC");
    $start = DateTimeImmutable::createFromFormat("Y-m-d H:i:s", $startsAt, $tz);
    $endsAt = $startsAt;
    if ($start !== false) {
        $endsAt = $start->modify("+" . ($slotUnits * AGENDA_SLOT_MINUTES) . " minutes")->format("Y-m-d H:i:s");
    }

    $aid = (int)($appointmentId ?? 0);
    $notifications = [
        "guest" => false,
        "admin" => false,
        "expert" => false,
        "skipped_guest" => false,
        "in_app_admin" => false,
        "in_app_client" => false,
        "deliveries" => [],
    ];
    if ($aid > 0) {
        agenda_notifications_link_appointment_client($conn, $aid, $clientId, $guestEmail);
        $notifications = agenda_notifications_send_booking($conn, $aid);
    }

    return [
        "ok" => true,
        "appointment_id" => $aid,
        "service_id" => $serviceId,
        "expert_id" => $expertId,
        "starts_at" => $startsAt,
        "ends_at" => $endsAt,
        "slot_units" => $slotUnits,
        "notifications" => $notifications,
    ];
}
