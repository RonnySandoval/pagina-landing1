<?php
declare(strict_types=1);

require_once __DIR__ . "/agenda_lib.php";

/**
 * Gestión de expertos, disponibilidad y citas (panel admin).
 */

function experts_admin_clamp_sort_order(int $sortOrder): int
{
    if ($sortOrder < 0) {
        return 0;
    }
    if ($sortOrder > 999999) {
        return 999999;
    }

    return $sortOrder;
}

/**
 * @return array<int, true>
 */
function experts_admin_valid_service_ids(mysqli $conn): array
{
    $map = [];
    $vs = $conn->query("SELECT id FROM services");
    if ($vs) {
        while ($z = $vs->fetch_assoc()) {
            $map[(int)($z["id"] ?? 0)] = true;
        }
    }

    return $map;
}

/**
 * @return array{ok: true}|array{ok: false, error: string}
 */
function experts_admin_assert_exists(mysqli $conn, int $expertId): array
{
    if ($expertId <= 0) {
        return ["ok" => false, "error" => "invalid_expert"];
    }
    $chk = $conn->prepare("SELECT id FROM experts WHERE id = ? LIMIT 1");
    if ($chk === false) {
        return ["ok" => false, "error" => "load_failed"];
    }
    $chk->bind_param("i", $expertId);
    $chk->execute();
    $res = $chk->get_result();
    $ok = $res && $res->num_rows === 1;
    $chk->close();

    return $ok ? ["ok" => true] : ["ok" => false, "error" => "not_found"];
}

/**
 * @param array<string, mixed> $input
 * @return array{ok: true, fields: array<string, mixed>}|array{ok: false, error: string}
 */
function experts_admin_normalize_profile(array $input, bool $requireName): array
{
    $displayName = trim((string)($input["display_name"] ?? ""));
    if ($requireName && $displayName === "") {
        return ["ok" => false, "error" => "missing_display_name"];
    }
    if ($displayName === "" && !$requireName) {
        $displayName = "Sin nombre";
    }
    if (mb_strlen($displayName, "UTF-8") > 180) {
        $displayName = mb_substr($displayName, 0, 180, "UTF-8");
    }

    $email = trim((string)($input["email"] ?? ""));
    if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ["ok" => false, "error" => "invalid_email"];
    }

    $phone = trim((string)($input["phone"] ?? ""));
    if (mb_strlen($phone, "UTF-8") > 48) {
        $phone = mb_substr($phone, 0, 48, "UTF-8");
    }

    $notes = (string)($input["notes"] ?? "");
    if (strlen($notes) > 12000) {
        $notes = substr($notes, 0, 12000);
    }

    $sortOrder = experts_admin_clamp_sort_order((int)($input["sort_order"] ?? 999));
    $isActive = !array_key_exists("is_active", $input)
        || filter_var($input["is_active"], FILTER_VALIDATE_BOOLEAN);

    return [
        "ok" => true,
        "fields" => [
            "display_name" => $displayName,
            "email" => $email,
            "phone" => $phone,
            "notes" => $notes,
            "sort_order" => $sortOrder,
            "is_active" => $isActive ? 1 : 0,
        ],
    ];
}

/**
 * @param list<int|string> $serviceIdsRaw
 * @return array{ok: true}|array{ok: false, error: string}
 */
function experts_admin_replace_services(mysqli $conn, int $expertId, array $serviceIdsRaw): array
{
    $valid = experts_admin_valid_service_ids($conn);
    $ids = [];
    foreach ($serviceIdsRaw as $sidRaw) {
        $sid = (int)$sidRaw;
        if ($sid > 0 && isset($valid[$sid])) {
            $ids[$sid] = true;
        }
    }

    $delEs = $conn->prepare("DELETE FROM expert_services WHERE expert_id = ?");
    $insEs = $conn->prepare("INSERT INTO expert_services (expert_id, service_id) VALUES (?, ?)");
    if ($delEs === false || $insEs === false) {
        return ["ok" => false, "error" => "services_link_failed"];
    }
    $delEs->bind_param("i", $expertId);
    if (!$delEs->execute()) {
        $delEs->close();
        $insEs->close();
        return ["ok" => false, "error" => "services_link_failed"];
    }
    $delEs->close();

    foreach (array_keys($ids) as $sid) {
        $insEs->bind_param("ii", $expertId, $sid);
        if (!$insEs->execute()) {
            $insEs->close();
            return ["ok" => false, "error" => "services_link_failed"];
        }
    }
    $insEs->close();

    return ["ok" => true];
}

/**
 * @return list<array<string, mixed>>
 */
function experts_admin_list(mysqli $conn): array
{
    $rows = [];
    $q = $conn->query(
        "SELECT id, display_name, email, phone, notes, sort_order, is_active, created_at
         FROM experts ORDER BY sort_order ASC, id ASC"
    );
    if ($q) {
        while ($row = $q->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    return $rows;
}

/**
 * @return array<int, list<int>>
 */
function experts_admin_service_ids_by_expert(mysqli $conn, array $expertIds): array
{
    $expertIds = array_values(array_filter(array_map("intval", $expertIds), static fn(int $id): bool => $id > 0));
    $map = [];
    if (count($expertIds) === 0) {
        return $map;
    }
    $ph = implode(",", array_fill(0, count($expertIds), "?"));
    $types = str_repeat("i", count($expertIds));
    $stmt = $conn->prepare("SELECT expert_id, service_id FROM expert_services WHERE expert_id IN ($ph)");
    if ($stmt === false) {
        return $map;
    }
    $stmt->bind_param($types, ...$expertIds);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        while ($pair = $res->fetch_assoc()) {
            $eid = (int)($pair["expert_id"] ?? 0);
            $sid = (int)($pair["service_id"] ?? 0);
            if (!isset($map[$eid])) {
                $map[$eid] = [];
            }
            $map[$eid][] = $sid;
        }
    }
    $stmt->close();

    return $map;
}

/**
 * @return array{ok: true, expert: array<string, mixed>, service_ids: list<int>}|array{ok: false, error: string}
 */
function experts_admin_get(mysqli $conn, int $expertId): array
{
    if ($expertId <= 0) {
        return ["ok" => false, "error" => "invalid_expert"];
    }
    $stmt = $conn->prepare(
        "SELECT id, display_name, email, phone, notes, sort_order, is_active, created_at FROM experts WHERE id = ? LIMIT 1"
    );
    if ($stmt === false) {
        return ["ok" => false, "error" => "load_failed"];
    }
    $stmt->bind_param("i", $expertId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!is_array($row)) {
        return ["ok" => false, "error" => "not_found"];
    }

    $svcMap = experts_admin_service_ids_by_expert($conn, [$expertId]);

    return [
        "ok" => true,
        "expert" => $row,
        "service_ids" => $svcMap[$expertId] ?? [],
    ];
}

/**
 * Citas confirmadas próximas de un experto.
 *
 * @return list<array<string, mixed>>
 */
function experts_admin_fetch_upcoming_appointments_for_expert(mysqli $conn, int $expertId, int $limit = 80): array
{
    $rows = [];
    if ($expertId <= 0 || $limit <= 0) {
        return $rows;
    }
    $apStmt = $conn->prepare(
        "SELECT a.id, a.expert_id, a.starts_at, a.ends_at, a.status, a.guest_name, a.guest_email, a.guest_phone,
                s.title AS service_title
         FROM expert_appointments a
         INNER JOIN services s ON s.id = a.service_id
         WHERE a.expert_id = ? AND a.status = 'confirmed' AND a.ends_at >= (NOW() - INTERVAL 1 HOUR)
         ORDER BY a.starts_at ASC
         LIMIT ?"
    );
    if ($apStmt === false) {
        return $rows;
    }
    $apStmt->bind_param("ii", $expertId, $limit);
    $apStmt->execute();
    $apRes = $apStmt->get_result();
    if ($apRes) {
        while ($ar = $apRes->fetch_assoc()) {
            $rows[] = $ar;
        }
    }
    $apStmt->close();

    return $rows;
}

/**
 * Citas confirmadas próximas de todos los expertos (orden global fecha+hora).
 *
 * @return list<array<string, mixed>>
 */
function experts_admin_fetch_all_upcoming_appointments(mysqli $conn, int $limit = 200): array
{
    $rows = [];
    if ($limit <= 0) {
        return $rows;
    }
    $apStmt = $conn->prepare(
        "SELECT a.id, a.expert_id, a.starts_at, a.ends_at, a.status, a.guest_name, a.guest_email, a.guest_phone,
                s.title AS service_title, e.display_name AS expert_name
         FROM expert_appointments a
         INNER JOIN services s ON s.id = a.service_id
         INNER JOIN experts e ON e.id = a.expert_id
         WHERE a.status = 'confirmed' AND a.ends_at >= (NOW() - INTERVAL 1 HOUR)
         ORDER BY a.starts_at ASC
         LIMIT ?"
    );
    if ($apStmt === false) {
        return $rows;
    }
    $apStmt->bind_param("i", $limit);
    $apStmt->execute();
    $apRes = $apStmt->get_result();
    if ($apRes) {
        while ($ar = $apRes->fetch_assoc()) {
            $rows[] = $ar;
        }
    }
    $apStmt->close();

    return $rows;
}

/**
 * @return array{ok: true, schedule: array<string, mixed>}|array{ok: false, error: string}
 */
function experts_admin_load_schedule(mysqli $conn, int $expertId, string $weekStart = ""): array
{
    $exists = experts_admin_assert_exists($conn, $expertId);
    if (!$exists["ok"]) {
        return ["ok" => false, "error" => (string)($exists["error"] ?? "not_found")];
    }

    $weekly = [];
    $eaStmt = $conn->prepare(
        "SELECT id, weekday, start_time, end_time FROM expert_availability WHERE expert_id = ? ORDER BY weekday ASC, start_time ASC"
    );
    if ($eaStmt !== false) {
        $eaStmt->bind_param("i", $expertId);
        $eaStmt->execute();
        $eaRes = $eaStmt->get_result();
        if ($eaRes) {
            while ($ar = $eaRes->fetch_assoc()) {
                $weekly[] = $ar;
            }
        }
        $eaStmt->close();
    }

    $dateRows = [];
    $adStmt = $conn->prepare(
        "SELECT id, calendar_date, is_closed, start_time, end_time
         FROM expert_availability_date
         WHERE expert_id = ? AND calendar_date >= (CURDATE() - INTERVAL 14 DAY)
         ORDER BY calendar_date ASC, is_closed DESC, start_time ASC
         LIMIT 300"
    );
    if ($adStmt !== false) {
        $adStmt->bind_param("i", $expertId);
        $adStmt->execute();
        $adRes = $adStmt->get_result();
        if ($adRes) {
            while ($dr = $adRes->fetch_assoc()) {
                $dateRows[] = $dr;
            }
        }
        $adStmt->close();
    }

    $appointments = experts_admin_fetch_upcoming_appointments_for_expert($conn, $expertId);

    return [
        "ok" => true,
        "schedule" => [
            "weekly_availability" => $weekly,
            "date_exceptions" => $dateRows,
            "upcoming_appointments" => $appointments,
            "week_grid" => agenda_expert_admin_week_grid($conn, $expertId, $weekStart),
        ],
    ];
}

/**
 * @param array<string, mixed> $input
 * @param list<int|string> $serviceIds
 * @return array{ok: true, expert_id: int}|array{ok: false, error: string}
 */
function experts_admin_create(mysqli $conn, array $input, array $serviceIds = []): array
{
    $norm = experts_admin_normalize_profile($input, true);
    if (!$norm["ok"]) {
        return ["ok" => false, "error" => (string)($norm["error"] ?? "invalid")];
    }
    $f = $norm["fields"];

    $ins = $conn->prepare(
        "INSERT INTO experts (display_name, email, phone, notes, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?)"
    );
    if ($ins === false) {
        return ["ok" => false, "error" => "insert_failed"];
    }
    $ins->bind_param(
        "ssssii",
        $f["display_name"],
        $f["email"],
        $f["phone"],
        $f["notes"],
        $f["sort_order"],
        $f["is_active"]
    );
    if (!$ins->execute()) {
        $ins->close();
        return ["ok" => false, "error" => "insert_failed"];
    }
    $newId = (int)$ins->insert_id;
    $ins->close();

    $link = experts_admin_replace_services($conn, $newId, $serviceIds);
    if (!$link["ok"]) {
        return ["ok" => false, "error" => (string)($link["error"] ?? "services_link_failed")];
    }

    agenda_replace_mon_fri_single_window(
        $conn,
        $newId,
        AGENDA_DEFAULT_MON_FRI_START,
        AGENDA_DEFAULT_MON_FRI_END
    );

    return ["ok" => true, "expert_id" => $newId];
}

/**
 * @param array<string, mixed> $input
 * @param list<int|string> $serviceIds
 * @return array{ok: true}|array{ok: false, error: string}
 */
function experts_admin_update(mysqli $conn, int $expertId, array $input, array $serviceIds): array
{
    $exists = experts_admin_assert_exists($conn, $expertId);
    if (!$exists["ok"]) {
        return ["ok" => false, "error" => (string)($exists["error"] ?? "not_found")];
    }

    $norm = experts_admin_normalize_profile($input, false);
    if (!$norm["ok"]) {
        return ["ok" => false, "error" => (string)($norm["error"] ?? "invalid")];
    }
    $f = $norm["fields"];

    $upd = $conn->prepare(
        "UPDATE experts SET display_name = ?, email = ?, phone = ?, notes = ?, sort_order = ?, is_active = ? WHERE id = ?"
    );
    if ($upd === false) {
        return ["ok" => false, "error" => "update_failed"];
    }
    $upd->bind_param(
        "ssssiii",
        $f["display_name"],
        $f["email"],
        $f["phone"],
        $f["notes"],
        $f["sort_order"],
        $f["is_active"],
        $expertId
    );
    if (!$upd->execute()) {
        $upd->close();
        return ["ok" => false, "error" => "update_failed"];
    }
    $upd->close();

    $link = experts_admin_replace_services($conn, $expertId, $serviceIds);
    if (!$link["ok"]) {
        return ["ok" => false, "error" => (string)($link["error"] ?? "services_link_failed")];
    }

    return ["ok" => true];
}

/**
 * @return array{ok: true}|array{ok: false, error: string}
 */
function experts_admin_delete(mysqli $conn, int $expertId): array
{
    if ($expertId <= 0) {
        return ["ok" => false, "error" => "invalid_expert"];
    }
    $stmt = $conn->prepare("DELETE FROM experts WHERE id = ?");
    if ($stmt === false) {
        return ["ok" => false, "error" => "delete_failed"];
    }
    $stmt->bind_param("i", $expertId);
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
 * @return array{ok: true, start: string, end: string}|array{ok: false, error: string}
 */
function experts_admin_parse_time_range(string $startRaw, string $endRaw): array
{
    $tz = new DateTimeZone(date_default_timezone_get() ?: "UTC");
    $tStart = DateTimeImmutable::createFromFormat("H:i", $startRaw, $tz);
    if ($tStart === false) {
        $tStart = DateTimeImmutable::createFromFormat("H:i:s", $startRaw, $tz);
    }
    $tEnd = DateTimeImmutable::createFromFormat("H:i", $endRaw, $tz);
    if ($tEnd === false) {
        $tEnd = DateTimeImmutable::createFromFormat("H:i:s", $endRaw, $tz);
    }
    if ($tStart === false || $tEnd === false) {
        return ["ok" => false, "error" => "invalid_time"];
    }
    if ($tEnd <= $tStart) {
        return ["ok" => false, "error" => "invalid_time_range"];
    }

    return ["ok" => true, "start" => $tStart->format("H:i:s"), "end" => $tEnd->format("H:i:s")];
}

/**
 * @return array{ok: true, availability_id: int}|array{ok: false, error: string}
 */
function experts_admin_add_weekly_availability(
    mysqli $conn,
    int $expertId,
    int $weekday,
    string $startRaw,
    string $endRaw
): array {
    $exists = experts_admin_assert_exists($conn, $expertId);
    if (!$exists["ok"]) {
        return ["ok" => false, "error" => (string)($exists["error"] ?? "not_found")];
    }
    if ($weekday < 0 || $weekday > 6) {
        return ["ok" => false, "error" => "invalid_weekday"];
    }

    $times = experts_admin_parse_time_range($startRaw, $endRaw);
    if (!$times["ok"]) {
        return ["ok" => false, "error" => (string)($times["error"] ?? "invalid_time")];
    }

    $ins = $conn->prepare(
        "INSERT INTO expert_availability (expert_id, weekday, start_time, end_time) VALUES (?, ?, ?, ?)"
    );
    if ($ins === false) {
        return ["ok" => false, "error" => "insert_failed"];
    }
    $ins->bind_param("iiss", $expertId, $weekday, $times["start"], $times["end"]);
    if (!$ins->execute()) {
        $ins->close();
        return ["ok" => false, "error" => "insert_failed"];
    }
    $id = (int)$ins->insert_id;
    $ins->close();

    return ["ok" => true, "availability_id" => $id];
}

/**
 * @return array{ok: true}|array{ok: false, error: string}
 */
function experts_admin_delete_weekly_availability(mysqli $conn, int $expertId, int $availabilityId): array
{
    if ($expertId <= 0 || $availabilityId <= 0) {
        return ["ok" => false, "error" => "invalid_request"];
    }
    $del = $conn->prepare("DELETE FROM expert_availability WHERE id = ? AND expert_id = ? LIMIT 1");
    if ($del === false) {
        return ["ok" => false, "error" => "delete_failed"];
    }
    $del->bind_param("ii", $availabilityId, $expertId);
    $del->execute();
    $del->close();

    return ["ok" => true];
}

/**
 * @return array{ok: true, start: string, end: string}|array{ok: false, error: string}
 */
function experts_admin_resolve_mon_fri_times(array $input): array
{
    $useDef = filter_var($input["use_defaults"] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($useDef) {
        return ["ok" => true, "start" => AGENDA_DEFAULT_MON_FRI_START, "end" => AGENDA_DEFAULT_MON_FRI_END];
    }

    return experts_admin_parse_time_range(
        trim((string)($input["mon_fri_start"] ?? $input["start_time"] ?? "")),
        trim((string)($input["mon_fri_end"] ?? $input["end_time"] ?? ""))
    );
}

/**
 * @return array{ok: true}|array{ok: false, error: string}
 */
function experts_admin_set_mon_fri_window(mysqli $conn, int $expertId, array $input): array
{
    $exists = experts_admin_assert_exists($conn, $expertId);
    if (!$exists["ok"]) {
        return ["ok" => false, "error" => (string)($exists["error"] ?? "not_found")];
    }

    $times = experts_admin_resolve_mon_fri_times($input);
    if (!$times["ok"]) {
        return ["ok" => false, "error" => (string)($times["error"] ?? "invalid_time")];
    }

    if (!agenda_replace_mon_fri_single_window($conn, $expertId, $times["start"], $times["end"])) {
        return ["ok" => false, "error" => "update_failed"];
    }

    return ["ok" => true];
}

/**
 * @return array{ok: true, experts_updated: int}|array{ok: false, error: string}
 */
function experts_admin_bulk_mon_fri_all(mysqli $conn, array $input): array
{
    $times = experts_admin_resolve_mon_fri_times($input);
    if (!$times["ok"]) {
        return ["ok" => false, "error" => (string)($times["error"] ?? "invalid_time")];
    }

    $count = 0;
    $allQ = $conn->query("SELECT id FROM experts");
    if ($allQ) {
        while ($erow = $allQ->fetch_assoc()) {
            $xid = (int)($erow["id"] ?? 0);
            if ($xid > 0 && agenda_replace_mon_fri_single_window($conn, $xid, $times["start"], $times["end"])) {
                $count++;
            }
        }
    }

    return ["ok" => true, "experts_updated" => $count];
}

/**
 * @return array{ok: true}|array{ok: false, error: string}
 */
function experts_admin_add_date_exception(mysqli $conn, int $expertId, array $input): array
{
    $exists = experts_admin_assert_exists($conn, $expertId);
    if (!$exists["ok"]) {
        return ["ok" => false, "error" => (string)($exists["error"] ?? "not_found")];
    }

    $cal = trim((string)($input["calendar_date"] ?? $input["date"] ?? ""));
    $mode = trim((string)($input["date_av_mode"] ?? $input["mode"] ?? ""));
    $tz = new DateTimeZone(date_default_timezone_get() ?: "UTC");
    $dtCal = DateTimeImmutable::createFromFormat("Y-m-d", $cal, $tz);
    if ($dtCal === false) {
        return ["ok" => false, "error" => "invalid_date"];
    }
    $today0 = (new DateTimeImmutable("now", $tz))->setTime(0, 0, 0);
    $maxD = $today0->modify("+" . AGENDA_DATE_EXCEPTION_MAX_DAYS . " days");
    if ($dtCal < $today0 || $dtCal > $maxD) {
        return ["ok" => false, "error" => "date_out_of_range"];
    }
    if ($mode !== "closed" && $mode !== "window") {
        return ["ok" => false, "error" => "invalid_mode"];
    }

    $calSql = $dtCal->format("Y-m-d");

    if ($mode === "closed") {
        $delAll = $conn->prepare("DELETE FROM expert_availability_date WHERE expert_id = ? AND calendar_date = ?");
        if ($delAll === false) {
            return ["ok" => false, "error" => "update_failed"];
        }
        $delAll->bind_param("is", $expertId, $calSql);
        if (!$delAll->execute()) {
            $delAll->close();
            return ["ok" => false, "error" => "update_failed"];
        }
        $delAll->close();

        $insC = $conn->prepare(
            "INSERT INTO expert_availability_date (expert_id, calendar_date, is_closed, start_time, end_time)
             VALUES (?, ?, 1, NULL, NULL)"
        );
        if ($insC === false) {
            return ["ok" => false, "error" => "insert_failed"];
        }
        $insC->bind_param("is", $expertId, $calSql);
        if (!$insC->execute()) {
            $insC->close();
            return ["ok" => false, "error" => "insert_failed"];
        }
        $insC->close();

        return ["ok" => true];
    }

    $times = experts_admin_parse_time_range(
        trim((string)($input["date_start_time"] ?? $input["start_time"] ?? "")),
        trim((string)($input["date_end_time"] ?? $input["end_time"] ?? ""))
    );
    if (!$times["ok"]) {
        return ["ok" => false, "error" => (string)($times["error"] ?? "invalid_time")];
    }

    $delClosed = $conn->prepare(
        "DELETE FROM expert_availability_date WHERE expert_id = ? AND calendar_date = ? AND is_closed = 1"
    );
    if ($delClosed !== false) {
        $delClosed->bind_param("is", $expertId, $calSql);
        $delClosed->execute();
        $delClosed->close();
    }

    $insW = $conn->prepare(
        "INSERT INTO expert_availability_date (expert_id, calendar_date, is_closed, start_time, end_time)
         VALUES (?, ?, 0, ?, ?)"
    );
    if ($insW === false) {
        return ["ok" => false, "error" => "insert_failed"];
    }
    $insW->bind_param("isss", $expertId, $calSql, $times["start"], $times["end"]);
    if (!$insW->execute()) {
        $insW->close();
        return ["ok" => false, "error" => "insert_failed"];
    }
    $insW->close();

    return ["ok" => true];
}

/**
 * @return array{ok: true}|array{ok: false, error: string}
 */
function experts_admin_delete_date_exception(mysqli $conn, int $expertId, int $avDateId): array
{
    if ($expertId <= 0 || $avDateId <= 0) {
        return ["ok" => false, "error" => "invalid_request"];
    }
    $delD = $conn->prepare("DELETE FROM expert_availability_date WHERE id = ? AND expert_id = ? LIMIT 1");
    if ($delD === false) {
        return ["ok" => false, "error" => "delete_failed"];
    }
    $delD->bind_param("ii", $avDateId, $expertId);
    $delD->execute();
    $delD->close();

    return ["ok" => true];
}

/**
 * @return array{ok: true, cancelled: bool}|array{ok: false, error: string}
 */
function experts_admin_cancel_appointment(mysqli $conn, int $expertId, int $appointmentId): array
{
    if ($expertId <= 0 || $appointmentId <= 0) {
        return ["ok" => false, "error" => "invalid_request"];
    }
    $upd = $conn->prepare(
        "UPDATE expert_appointments SET status = 'cancelled' WHERE id = ? AND expert_id = ? AND status = 'confirmed' LIMIT 1"
    );
    if ($upd === false) {
        return ["ok" => false, "error" => "update_failed"];
    }
    $upd->bind_param("ii", $appointmentId, $expertId);
    $upd->execute();
    $cancelled = $upd->affected_rows > 0;
    $upd->close();

    return ["ok" => true, "cancelled" => $cancelled];
}

/**
 * @param array<string, mixed> $expert
 * @param list<int> $serviceIds
 * @return array<string, mixed>
 */
function experts_admin_format_expert(array $expert, array $serviceIds = []): array
{
    return [
        "id" => (int)($expert["id"] ?? 0),
        "display_name" => (string)($expert["display_name"] ?? ""),
        "email" => $expert["email"] ?? null,
        "phone" => $expert["phone"] ?? null,
        "notes" => $expert["notes"] ?? null,
        "sort_order" => (int)($expert["sort_order"] ?? 999),
        "is_active" => (int)($expert["is_active"] ?? 0) === 1,
        "created_at" => $expert["created_at"] ?? null,
        "service_ids" => array_values(array_map("intval", $serviceIds)),
        "initials" => agenda_expert_initials((string)($expert["display_name"] ?? "")),
    ];
}

/**
 * @return array{experts: list<array<string, mixed>>, expert_service_ids: array<int, list<int>>}
 */
function experts_admin_load_admin_catalog(mysqli $conn): array
{
    $experts = experts_admin_list($conn);
    $ids = array_map(static fn(array $e): int => (int)($e["id"] ?? 0), $experts);
    $svcByExpert = experts_admin_service_ids_by_expert($conn, $ids);
    $formatted = [];
    foreach ($experts as $expert) {
        $eid = (int)($expert["id"] ?? 0);
        $formatted[] = experts_admin_format_expert($expert, $svcByExpert[$eid] ?? []);
    }

    return [
        "experts" => $formatted,
        "expert_service_ids" => $svcByExpert,
        "raw_experts" => $experts,
    ];
}
