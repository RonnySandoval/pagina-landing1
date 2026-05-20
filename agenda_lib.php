<?php
declare(strict_types=1);

/**
 * Agenda pública: huecos por servicio/día y validación de reservas.
 * Zona horaria: la predeterminada de PHP (date_default_timezone_get()).
 */

const AGENDA_SLOT_MINUTES = 30;
const AGENDA_MAX_DAYS_AHEAD = 90;
/** Límite al crear excepciones por fecha en admin (días desde hoy). */
const AGENDA_DATE_EXCEPTION_MAX_DAYS = 366;
/** Jornada por defecto lunes–viernes (una sola franja; horario del servidor). */
const AGENDA_DEFAULT_MON_FRI_START = "09:00:00";
const AGENDA_DEFAULT_MON_FRI_END = "18:00:00";

/** @return array<int, string> 0=Dom … 6=Sáb */
function agenda_weekday_labels_es(): array
{
    return ["Dom", "Lun", "Mar", "Mié", "Jue", "Vie", "Sáb"];
}

/** Iniciales para cabeceras compactas (p. ej. «María López» → ML). */
function agenda_expert_initials(string $displayName): string
{
    $displayName = trim($displayName);
    if ($displayName === "") {
        return "?";
    }
    $parts = preg_split('/\s+/u', $displayName) ?: [];
    if (count($parts) >= 2) {
        return mb_strtoupper(
            mb_substr($parts[0], 0, 1, "UTF-8") . mb_substr($parts[1], 0, 1, "UTF-8"),
            "UTF-8"
        );
    }

    return mb_strtoupper(mb_substr($displayName, 0, 2, "UTF-8"), "UTF-8");
}

/** Nombre completo del día (0=Domingo … 6=Sábado). */
function agenda_weekday_label_long(int $weekday): string
{
    $labels = [
        0 => "Domingo",
        1 => "Lunes",
        2 => "Martes",
        3 => "Miércoles",
        4 => "Jueves",
        5 => "Viernes",
        6 => "Sábado",
    ];

    return $labels[$weekday] ?? "";
}

/** Lunes de la semana que contiene $ymd (o hoy si vacío/inválido). */
function agenda_normalize_week_start(string $ymd = ""): string
{
    $tz = new DateTimeZone(date_default_timezone_get() ?: "UTC");
    $dt = null;
    if ($ymd !== "" && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
        $dt = DateTimeImmutable::createFromFormat("Y-m-d", $ymd, $tz);
    }
    if ($dt === false || $dt === null) {
        $dt = (new DateTimeImmutable("now", $tz))->setTime(0, 0, 0);
    } else {
        $dt = $dt->setTime(0, 0, 0);
    }
    $w = (int)$dt->format("w");
    $daysSinceMonday = ($w + 6) % 7;

    return $dt->modify("-" . $daysSinceMonday . " days")->format("Y-m-d");
}

/**
 * Huecos de 30 min dentro de la jornada del experto ese día (plantilla o excepción).
 *
 * @return array{closed: bool, slots: list<array{starts: string, ends: string, time: string}>}
 */
function agenda_expert_day_scheduled_slots(
    mysqli $conn,
    int $expertId,
    string $dateYmd,
    int $weekday,
    int $slotMinutes = AGENDA_SLOT_MINUTES
): array {
    $tz = new DateTimeZone(date_default_timezone_get() ?: "UTC");
    $dayStart = DateTimeImmutable::createFromFormat("Y-m-d", $dateYmd, $tz);
    if ($dayStart === false || $expertId <= 0) {
        return ["closed" => true, "slots" => []];
    }
    $daySpec = agenda_expert_day_windows($conn, $expertId, $dateYmd, $weekday);
    if ($daySpec["closed"]) {
        return ["closed" => true, "slots" => []];
    }
    $windows = $daySpec["windows"];
    if (count($windows) === 0) {
        return ["closed" => false, "slots" => []];
    }
    $slots = [];
    foreach ($windows as $win) {
        $t0 = $dayStart->format("Y-m-d") . " " . substr($win["start"], 0, 8);
        $t1 = $dayStart->format("Y-m-d") . " " . substr($win["end"], 0, 8);
        $cursor = DateTimeImmutable::createFromFormat("Y-m-d H:i:s", $t0, $tz);
        $winEnd = DateTimeImmutable::createFromFormat("Y-m-d H:i:s", $t1, $tz);
        if ($cursor === false || $winEnd === false || $winEnd <= $cursor) {
            continue;
        }
        while ($cursor < $winEnd) {
            $slotEnd = $cursor->modify("+" . $slotMinutes . " minutes");
            if ($slotEnd > $winEnd || $slotEnd <= $cursor) {
                break;
            }
            $slots[] = [
                "starts" => $cursor->format("Y-m-d H:i:s"),
                "ends" => $slotEnd->format("Y-m-d H:i:s"),
                "time" => $cursor->format("H:i"),
            ];
            $cursor = $slotEnd;
        }
    }

    return ["closed" => false, "slots" => $slots];
}

/**
 * Parrilla semanal admin: filas = hora, columnas = día (lun–dom).
 *
 * @return array{
 *   week_start: string,
 *   week_end: string,
 *   week_label: string,
 *   days: list<array{date: string, weekday: int, label: string, day_num: string, closed: bool}>,
 *   rows: list<array{time: string, cells: array<string, array<string, mixed>>}>
 * }
 */
function agenda_expert_admin_week_grid(mysqli $conn, int $expertId, string $weekStartYmd): array
{
    $empty = [
        "week_start" => "",
        "week_end" => "",
        "week_label" => "",
        "days" => [],
        "rows" => [],
    ];
    if ($expertId <= 0) {
        return $empty;
    }
    $tz = new DateTimeZone(date_default_timezone_get() ?: "UTC");
    $weekStart = agenda_normalize_week_start($weekStartYmd);
    $monday = DateTimeImmutable::createFromFormat("Y-m-d", $weekStart, $tz);
    if ($monday === false) {
        return $empty;
    }
    $monday = $monday->setTime(0, 0, 0);
    $sunday = $monday->modify("+6 days");
    $weekEnd = $sunday->format("Y-m-d");
    $weekLabel = $monday->format("d/m/Y") . " – " . $sunday->format("d/m/Y");

    $days = [];
    $timeKeys = [];
    /** @var array<string, array<string, mixed>> */
    $cells = [];

    for ($offset = 0; $offset < 7; $offset++) {
        $dayDt = $monday->modify("+" . $offset . " days");
        $dateYmd = $dayDt->format("Y-m-d");
        $wd = (int)$dayDt->format("w");
        $daySched = agenda_expert_day_scheduled_slots($conn, $expertId, $dateYmd, $wd);
        $days[] = [
            "date" => $dateYmd,
            "weekday" => $wd,
            "label" => agenda_weekday_label_long($wd),
            "day_num" => $dayDt->format("d/m"),
            "closed" => $daySched["closed"],
        ];
        if ($daySched["closed"]) {
            continue;
        }
        foreach ($daySched["slots"] as $sl) {
            $timeKey = (string)$sl["time"];
            $timeKeys[$timeKey] = true;
            $cellKey = $dateYmd . "|" . $timeKey;
            $cells[$cellKey] = [
                "state" => "free",
                "starts" => (string)$sl["starts"],
                "ends" => (string)$sl["ends"],
                "guest_name" => "",
                "service_title" => "",
                "appointment_id" => 0,
            ];
        }
    }

    $weekEndExclusive = $monday->modify("+7 days");
    $apStmt = $conn->prepare(
        "SELECT a.id, a.starts_at, a.ends_at, a.guest_name, s.title AS service_title
         FROM expert_appointments a
         INNER JOIN services s ON s.id = a.service_id
         WHERE a.expert_id = ? AND a.status IN ('confirmed', 'postponed')
           AND a.starts_at < ? AND a.ends_at > ?"
    );
    if ($apStmt !== false) {
        $ws = $monday->format("Y-m-d H:i:s");
        $we = $weekEndExclusive->format("Y-m-d H:i:s");
        $apStmt->bind_param("iss", $expertId, $we, $ws);
        $apStmt->execute();
        $apRes = $apStmt->get_result();
        if ($apRes) {
            while ($ap = $apRes->fetch_assoc()) {
                $as = new DateTimeImmutable((string)$ap["starts_at"], $tz);
                $ae = new DateTimeImmutable((string)$ap["ends_at"], $tz);
                $cursor = $as;
                while ($cursor < $ae) {
                    $d = $cursor->format("Y-m-d");
                    $timeKey = $cursor->format("H:i");
                    $cellKey = $d . "|" . $timeKey;
                    if (isset($cells[$cellKey])) {
                        $cells[$cellKey] = [
                            "state" => "booked",
                            "starts" => (string)$ap["starts_at"],
                            "ends" => (string)$ap["ends_at"],
                            "guest_name" => (string)$ap["guest_name"],
                            "service_title" => (string)$ap["service_title"],
                            "appointment_id" => (int)$ap["id"],
                        ];
                    }
                    $cursor = $cursor->modify("+" . AGENDA_SLOT_MINUTES . " minutes");
                }
            }
        }
        $apStmt->close();
    }

    $timeList = array_keys($timeKeys);
    sort($timeList);
    $now = new DateTimeImmutable("now", $tz);
    $rows = [];
    foreach ($timeList as $timeKey) {
        $rowCells = [];
        foreach ($days as $day) {
            $dateYmd = (string)$day["date"];
            if (!empty($day["closed"])) {
                $rowCells[$dateYmd] = ["state" => "closed"];
                continue;
            }
            $cellKey = $dateYmd . "|" . $timeKey;
            if (!isset($cells[$cellKey])) {
                $rowCells[$dateYmd] = ["state" => "off"];
                continue;
            }
            $cell = $cells[$cellKey];
            if ($cell["state"] === "free") {
                $slotEnd = DateTimeImmutable::createFromFormat("Y-m-d H:i", $dateYmd . " " . $timeKey, $tz);
                if ($slotEnd !== false) {
                    $slotEnd = $slotEnd->modify("+" . AGENDA_SLOT_MINUTES . " minutes");
                    if ($slotEnd <= $now) {
                        $cell["state"] = "past";
                    }
                }
            }
            $rowCells[$dateYmd] = $cell;
        }
        $rows[] = ["time" => $timeKey, "cells" => $rowCells];
    }

    return [
        "week_start" => $weekStart,
        "week_end" => $weekEnd,
        "week_label" => $weekLabel,
        "days" => $days,
        "rows" => $rows,
    ];
}

function agenda_weekday_from_ymd(string $ymd): int
{
    $dt = DateTimeImmutable::createFromFormat("Y-m-d", $ymd);
    if ($dt === false) {
        return -1;
    }
    return (int)$dt->format("w");
}

/**
 * Franjas para un experto en un día calendario: excepciones por fecha sustituyen
 * la plantilla semanal; si hay un cierre explícito ese día, no hay huecos.
 *
 * @return array{closed: bool, windows: list<array{start: string, end: string}>}
 */
function agenda_expert_day_windows(mysqli $conn, int $expertId, string $dateYmd, int $weekday): array
{
    $closedStmt = $conn->prepare(
        "SELECT 1 FROM expert_availability_date
         WHERE expert_id = ? AND calendar_date = ? AND is_closed = 1 LIMIT 1"
    );
    if ($closedStmt === false) {
        return ["closed" => false, "windows" => []];
    }
    $closedStmt->bind_param("is", $expertId, $dateYmd);
    $closedStmt->execute();
    $cr = $closedStmt->get_result();
    $isClosed = $cr && $cr->num_rows >= 1;
    $closedStmt->close();
    if ($isClosed) {
        return ["closed" => true, "windows" => []];
    }

    $cwStmt = $conn->prepare(
        "SELECT start_time, end_time FROM expert_availability_date
         WHERE expert_id = ? AND calendar_date = ? AND is_closed = 0
         ORDER BY start_time ASC"
    );
    if ($cwStmt === false) {
        return ["closed" => false, "windows" => []];
    }
    $cwStmt->bind_param("is", $expertId, $dateYmd);
    $cwStmt->execute();
    $cwRes = $cwStmt->get_result();
    $dateWindows = [];
    if ($cwRes) {
        while ($w = $cwRes->fetch_assoc()) {
            $dateWindows[] = [
                "start" => (string)$w["start_time"],
                "end" => (string)$w["end_time"],
            ];
        }
    }
    $cwStmt->close();
    if (count($dateWindows) > 0) {
        return ["closed" => false, "windows" => $dateWindows];
    }

    $avStmt = $conn->prepare(
        "SELECT start_time, end_time FROM expert_availability WHERE expert_id = ? AND weekday = ? ORDER BY start_time ASC"
    );
    if ($avStmt === false) {
        return ["closed" => false, "windows" => []];
    }
    $avStmt->bind_param("ii", $expertId, $weekday);
    $avStmt->execute();
    $avRes = $avStmt->get_result();
    $weekly = [];
    if ($avRes) {
        while ($w = $avRes->fetch_assoc()) {
            $weekly[] = [
                "start" => (string)$w["start_time"],
                "end" => (string)$w["end_time"],
            ];
        }
    }
    $avStmt->close();

    return ["closed" => false, "windows" => $weekly];
}

/**
 * Sustituye la plantilla de los días indicados (0=Dom … 6=Sáb) por las franjas dadas.
 * Cada día recibe exactamente las mismas franjas (sustituye las anteriores de esos días).
 *
 * @param list<int> $weekdays
 * @param list<array{start: string, end: string}> $windows
 */
function agenda_replace_weekdays_windows(mysqli $conn, int $expertId, array $weekdays, array $windows): bool
{
    if ($expertId <= 0 || $weekdays === [] || $windows === []) {
        return false;
    }
    $wdList = [];
    foreach ($weekdays as $wd) {
        $wd = (int)$wd;
        if ($wd >= 0 && $wd <= 6) {
            $wdList[$wd] = true;
        }
    }
    $wdList = array_keys($wdList);
    sort($wdList);
    if ($wdList === []) {
        return false;
    }

    $placeholders = implode(",", array_fill(0, count($wdList), "?"));
    $delSql = "DELETE FROM expert_availability WHERE expert_id = ? AND weekday IN ($placeholders)";
    $del = $conn->prepare($delSql);
    if ($del === false) {
        return false;
    }
    $delTypes = "i" . str_repeat("i", count($wdList));
    $delParams = array_merge([$expertId], $wdList);
    $del->bind_param($delTypes, ...$delParams);
    if (!$del->execute()) {
        $del->close();
        return false;
    }
    $del->close();

    $ins = $conn->prepare(
        "INSERT INTO expert_availability (expert_id, weekday, start_time, end_time) VALUES (?, ?, ?, ?)"
    );
    if ($ins === false) {
        return false;
    }
    foreach ($wdList as $wd) {
        foreach ($windows as $win) {
            $startSql = (string)($win["start"] ?? "");
            $endSql = (string)($win["end"] ?? "");
            if ($startSql === "" || $endSql === "") {
                continue;
            }
            $ins->bind_param("iiss", $expertId, $wd, $startSql, $endSql);
            if (!$ins->execute()) {
                $ins->close();
                return false;
            }
        }
    }
    $ins->close();

    return true;
}

/**
 * Sustituye la plantilla de lunes a viernes (weekday 1–5) por una sola franja horaria.
 * No modifica sábado ni domingo.
 */
function agenda_replace_mon_fri_single_window(mysqli $conn, int $expertId, string $startSql, string $endSql): bool
{
    return agenda_replace_weekdays_windows(
        $conn,
        $expertId,
        [1, 2, 3, 4, 5],
        [["start" => $startSql, "end" => $endSql]]
    );
}

/**
 * @param list<array{expert_id:int, display_name:string, starts:string, ends:string, label:string}> $slots
 * @return array{
 *   experts: array<int, string>,
 *   expert_order: list<int>,
 *   rows: list<array{starts: string, cells: array<int, array{expert_id:int, display_name:string, starts:string, ends:string, label:string}|null>}>
 * }
 */
function agenda_build_public_slot_table(array $slots, bool $showExpertNames = true): array
{
    $empty = [
        "layout" => $showExpertNames ? "by_expert" : "by_time",
        "experts" => [],
        "expert_order" => [],
        "rows" => [],
        "show_expert_names" => $showExpertNames,
    ];
    if (count($slots) === 0) {
        return $empty;
    }

    $byTime = [];
    foreach ($slots as $s) {
        $t = (string)$s["starts"];
        if (!isset($byTime[$t])) {
            $byTime[$t] = [];
        }
        $byTime[$t][] = $s;
    }
    ksort($byTime);

    if (!$showExpertNames) {
        $rows = [];
        foreach ($byTime as $starts => $timeSlots) {
            usort($timeSlots, static function (array $a, array $b): int {
                return strcmp((string)$a["starts"], (string)$b["starts"]);
            });
            $rows[] = ["starts" => $starts, "slots" => $timeSlots];
        }

        return [
            "layout" => "by_time",
            "experts" => [],
            "expert_order" => [],
            "rows" => $rows,
            "show_expert_names" => false,
        ];
    }

    $experts = [];
    $expertOrder = [];
    $seenExpert = [];
    foreach ($slots as $s) {
        $eid = (int)$s["expert_id"];
        if (isset($seenExpert[$eid])) {
            continue;
        }
        $seenExpert[$eid] = true;
        $expertOrder[] = $eid;
        $experts[$eid] = (string)$s["display_name"];
    }
    foreach ($expertOrder as $eid) {
        if ($experts[$eid] === "") {
            $experts[$eid] = "Profesional";
        }
    }

    $rows = [];
    foreach ($byTime as $starts => $timeSlots) {
        $cellsByExpert = [];
        foreach ($timeSlots as $s) {
            $eid = (int)$s["expert_id"];
            $cellsByExpert[$eid] = $s;
        }
        $rowCells = [];
        foreach ($expertOrder as $eid) {
            $rowCells[$eid] = $cellsByExpert[$eid] ?? null;
        }
        $rows[] = ["starts" => $starts, "cells" => $rowCells];
    }

    return [
        "layout" => "by_expert",
        "experts" => $experts,
        "expert_order" => $expertOrder,
        "rows" => $rows,
        "show_expert_names" => true,
    ];
}

/**
 * @return list<array{expert_id:int, display_name:string, starts:string, ends:string, label:string}>
 */
function agenda_slots_for_service_day(mysqli $conn, int $serviceId, string $dateYmd, int $slotMinutes = AGENDA_SLOT_MINUTES): array
{
    if ($serviceId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateYmd)) {
        return [];
    }
    $tz = new DateTimeZone(date_default_timezone_get() ?: "UTC");
    $dayStart = DateTimeImmutable::createFromFormat("Y-m-d", $dateYmd, $tz);
    if ($dayStart === false) {
        return [];
    }
    $today = (new DateTimeImmutable("now", $tz))->setTime(0, 0, 0);
    $maxDay = $today->modify("+" . AGENDA_MAX_DAYS_AHEAD . " days");
    if ($dayStart < $today || $dayStart > $maxDay) {
        return [];
    }

    $wd = agenda_weekday_from_ymd($dateYmd);
    if ($wd < 0) {
        return [];
    }

    $stmt = $conn->prepare(
        "SELECT e.id, e.display_name
         FROM experts e
         INNER JOIN expert_services es ON es.expert_id = e.id AND es.service_id = ?
         WHERE e.is_active = 1
         ORDER BY e.sort_order ASC, e.id ASC"
    );
    if ($stmt === false) {
        return [];
    }
    $stmt->bind_param("i", $serviceId);
    $stmt->execute();
    $res = $stmt->get_result();
    $experts = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $experts[] = [
                "id" => (int)$row["id"],
                "display_name" => (string)$row["display_name"],
            ];
        }
    }
    $stmt->close();

    $out = [];
    foreach ($experts as $ex) {
        $eid = $ex["id"];
        $daySpec = agenda_expert_day_windows($conn, $eid, $dateYmd, $wd);
        if ($daySpec["closed"]) {
            continue;
        }
        $windows = $daySpec["windows"];
        if (count($windows) === 0) {
            continue;
        }

        $apStmt = $conn->prepare(
            "SELECT starts_at, ends_at FROM expert_appointments
             WHERE expert_id = ? AND status IN ('confirmed', 'postponed')
               AND starts_at < ? AND ends_at > ?"
        );
        $dayEnd = $dayStart->modify("+1 day");
        $dayStartS = $dayStart->format("Y-m-d H:i:s");
        $dayEndS = $dayEnd->format("Y-m-d H:i:s");
        if ($apStmt === false) {
            continue;
        }
        $apStmt->bind_param("iss", $eid, $dayEndS, $dayStartS);
        $apStmt->execute();
        $apRes = $apStmt->get_result();
        $busy = [];
        if ($apRes) {
            while ($b = $apRes->fetch_assoc()) {
                $busy[] = [
                    "s" => new DateTimeImmutable((string)$b["starts_at"], $tz),
                    "e" => new DateTimeImmutable((string)$b["ends_at"], $tz),
                ];
            }
        }
        $apStmt->close();

        foreach ($windows as $win) {
            $t0 = $dayStart->format("Y-m-d") . " " . substr($win["start"], 0, 8);
            $t1 = $dayStart->format("Y-m-d") . " " . substr($win["end"], 0, 8);
            $cursor = DateTimeImmutable::createFromFormat("Y-m-d H:i:s", $t0, $tz);
            $winEnd = DateTimeImmutable::createFromFormat("Y-m-d H:i:s", $t1, $tz);
            if ($cursor === false || $winEnd === false) {
                continue;
            }
            if ($winEnd <= $cursor) {
                continue;
            }
            while ($cursor < $winEnd) {
                $slotEnd = $cursor->modify("+" . $slotMinutes . " minutes");
                if ($slotEnd > $winEnd) {
                    break;
                }
                if ($slotEnd <= $cursor) {
                    break;
                }
                $overlap = false;
                foreach ($busy as $b) {
                    if ($cursor < $b["e"] && $slotEnd > $b["s"]) {
                        $overlap = true;
                        break;
                    }
                }
                if (!$overlap) {
                    $now = new DateTimeImmutable("now", $tz);
                    if ($slotEnd > $now) {
                        $out[] = [
                            "expert_id" => $eid,
                            "display_name" => $ex["display_name"],
                            "starts" => $cursor->format("Y-m-d H:i:s"),
                            "ends" => $slotEnd->format("Y-m-d H:i:s"),
                            "label" => $cursor->format("H:i") . "–" . $slotEnd->format("H:i"),
                        ];
                    }
                }
                $cursor = $slotEnd;
            }
        }
    }

    usort($out, static function (array $a, array $b): int {
        return strcmp($a["starts"], $b["starts"]) ?: ($a["expert_id"] <=> $b["expert_id"]);
    });

    return $out;
}

/** Máximo de franjas de AGENDA_SLOT_MINUTES en una sola reserva (p. ej. 8 h). */
const AGENDA_MAX_SLOT_UNITS = 16;

/**
 * Hora en formato 24 h (HH:MM) desde TIME, datetime SQL o fragmento con hora.
 */
function agenda_format_time_24(string $value): string
{
    $value = trim($value);
    if ($value === "") {
        return "";
    }
    if (preg_match('/\b(\d{1,2}):(\d{2})(?::\d{2})?\b/', $value, $m)) {
        $h = (int)$m[1];
        $min = (int)$m[2];
        if ($h >= 0 && $h <= 23 && $min >= 0 && $min <= 59) {
            return sprintf("%02d:%02d", $h, $min);
        }
    }

    return substr($value, 0, 5);
}

/**
 * Fecha y hora en formato 24 h: dd/mm/aaaa HH:MM
 */
function agenda_format_datetime_24(string $value): string
{
    $value = trim($value);
    if ($value === "") {
        return "";
    }
    $tz = new DateTimeZone(date_default_timezone_get() ?: "UTC");
    foreach (["Y-m-d H:i:s", "Y-m-d H:i", "d/m/Y H:i", "d/m/Y H:i:s"] as $fmt) {
        $dt = DateTimeImmutable::createFromFormat($fmt, $value, $tz);
        if ($dt !== false) {
            return $dt->format("d/m/Y H:i");
        }
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})\s+(\d{1,2}:\d{2})/', $value, $m)) {
        return $m[3] . "/" . $m[2] . "/" . $m[1] . " " . agenda_format_time_24($m[4]);
    }

    return $value;
}

/**
 * Fecha corta + hora 24 h: dd/mm · HH:MM
 */
function agenda_format_datetime_short_24(string $value): string
{
    $full = agenda_format_datetime_24($value);
    if (preg_match('/^(\d{2}\/\d{2})\/\d{4}\s+(\d{2}:\d{2})$/', $full, $m)) {
        return $m[1] . " · " . $m[2];
    }

    return $full;
}

/**
 * Rango horario 24 h: HH:MM–HH:MM
 */
function agenda_format_time_range_24(string $start, string $end): string
{
    $s = agenda_format_time_24($start);
    $e = agenda_format_time_24($end);
    if ($s === "" && $e === "") {
        return "";
    }

    return $s . "–" . $e;
}

function agenda_format_booking_label(string $startsAt, string $endsAt): string
{
    $tz = new DateTimeZone(date_default_timezone_get() ?: "UTC");
    $s = DateTimeImmutable::createFromFormat("Y-m-d H:i:s", $startsAt, $tz);
    $e = DateTimeImmutable::createFromFormat("Y-m-d H:i:s", $endsAt, $tz);
    if ($s === false || $e === false) {
        return "";
    }

    return $s->format("H:i") . "–" . $e->format("H:i");
}

/**
 * Convierte "HH:MM" a minutos desde medianoche.
 */
function agenda_hhmm_to_minutes(string $hhmm): int
{
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $hhmm, $m)) {
        return -1;
    }

    return ((int)$m[1]) * 60 + (int)$m[2];
}

/**
 * Clases CSS para cortes visibles de jornada (mediodía, tarde, huecos, cuartiles).
 *
 * @return list<string>
 */
function agenda_slot_row_separator_classes(string $rowTimeHhMm, ?string $prevRowTimeHhMm, int $rowIndex = 0, int $totalRows = 0): array
{
    $classes = [];
    $cur = agenda_hhmm_to_minutes($rowTimeHhMm);
    if ($cur < 0) {
        return $classes;
    }
    $prev = $prevRowTimeHhMm !== null ? agenda_hhmm_to_minutes($prevRowTimeHhMm) : null;

    if ($cur >= 13 * 60 && ($prev === null || $prev < 13 * 60)) {
        $classes[] = "agenda-slot-row--sep-midday";
    }
    if ($cur >= 17 * 60 && ($prev === null || $prev < 17 * 60)) {
        $classes[] = "agenda-slot-row--sep-evening";
    }
    if ($prev !== null && $cur - $prev > AGENDA_SLOT_MINUTES) {
        $classes[] = "agenda-slot-row--sep-gap";
    }

    if ($totalRows >= 6 && $rowIndex > 0) {
        $quartileAt = [
            (int)floor($totalRows / 4),
            (int)floor($totalRows / 2),
            (int)floor((3 * $totalRows) / 4),
        ];
        if (in_array($rowIndex, $quartileAt, true) && !in_array("agenda-slot-row--sep-midday", $classes, true)
            && !in_array("agenda-slot-row--sep-evening", $classes, true)) {
            $classes[] = "agenda-slot-row--sep-quartile";
        }
    }

    return $classes;
}

/**
 * @return string|null mensaje de error, o null si OK
 */
function agenda_try_insert_booking(
    mysqli $conn,
    int $serviceId,
    int $expertId,
    string $startsAt,
    string $guestName,
    string $guestEmail,
    string $guestPhone,
    string $notes,
    ?int $clientId,
    int $slotUnits = 1,
    ?int &$appointmentId = null
): ?string {
    $appointmentId = null;
    $tz = new DateTimeZone(date_default_timezone_get() ?: "UTC");
    $start = DateTimeImmutable::createFromFormat("Y-m-d H:i:s", $startsAt, $tz);
    if ($start === false) {
        return "Fecha u hora no válida.";
    }
    if ($slotUnits < 1) {
        $slotUnits = 1;
    }
    if ($slotUnits > AGENDA_MAX_SLOT_UNITS) {
        return "El bloque horario supera el máximo permitido.";
    }
    $end = $start->modify("+" . ($slotUnits * AGENDA_SLOT_MINUTES) . " minutes");
    $endsAt = $end->format("Y-m-d H:i:s");

    if ($serviceId <= 0 || $expertId <= 0) {
        return "Datos incompletos.";
    }

    $chk = $conn->prepare(
        "SELECT 1 FROM expert_services es
         INNER JOIN experts e ON e.id = es.expert_id
         INNER JOIN services s ON s.id = es.service_id
         WHERE es.expert_id = ? AND es.service_id = ? AND e.is_active = 1 AND s.is_active = 1 LIMIT 1"
    );
    if ($chk === false) {
        return "No se pudo validar el servicio.";
    }
    $chk->bind_param("ii", $expertId, $serviceId);
    $chk->execute();
    $chkRes = $chk->get_result();
    $okLink = $chkRes && $chkRes->num_rows === 1;
    $chk->close();
    if (!$okLink) {
        return "Este experto no ofrece ese servicio o está inactivo.";
    }

    $slots = agenda_slots_for_service_day($conn, $serviceId, $start->format("Y-m-d"), AGENDA_SLOT_MINUTES);
    $slotIndex = [];
    foreach ($slots as $sl) {
        if ($sl["expert_id"] === $expertId) {
            $slotIndex[(string)$sl["starts"]] = true;
        }
    }
    for ($u = 0; $u < $slotUnits; $u++) {
        $unitStart = $start->modify("+" . ($u * AGENDA_SLOT_MINUTES) . " minutes")->format("Y-m-d H:i:s");
        if (!isset($slotIndex[$unitStart])) {
            return "El bloque horario ya no está disponible. Elige otro tramo.";
        }
    }

    $guestName = trim($guestName);
    $guestEmail = trim($guestEmail);
    $guestPhone = trim($guestPhone);
    $notes = trim($notes);
    if ($guestName === "" || mb_strlen($guestName, "UTF-8") > 180) {
        return "Indica un nombre válido.";
    }
    $guestEmailValid = $guestEmail !== "" && filter_var($guestEmail, FILTER_VALIDATE_EMAIL);
    $guestPhoneOk = mb_strlen($guestPhone, "UTF-8") >= 6;
    if (!$guestEmailValid && !$guestPhoneOk) {
        return "Indica un correo válido o un teléfono de contacto (mín. 6 caracteres).";
    }
    if (!$guestEmailValid) {
        $guestEmail = "";
    }
    if (mb_strlen($guestPhone, "UTF-8") > 48) {
        $guestPhone = mb_substr($guestPhone, 0, 48, "UTF-8");
    }
    if (strlen($notes) > 2000) {
        $notes = substr($notes, 0, 2000);
    }

    $conn->begin_transaction();
    try {
        $exLock = $conn->prepare("SELECT id FROM experts WHERE id = ? AND is_active = 1 FOR UPDATE");
        if ($exLock === false) {
            throw new RuntimeException("prepare ex");
        }
        $exLock->bind_param("i", $expertId);
        $exLock->execute();
        $exLr = $exLock->get_result();
        $exOk = $exLr && $exLr->num_rows === 1;
        $exLock->close();
        if (!$exOk) {
            $conn->rollback();
            return "Este experto no está disponible para reservas.";
        }

        $lock = $conn->prepare(
            "SELECT COUNT(*) AS c FROM expert_appointments
             WHERE expert_id = ? AND status IN ('confirmed', 'postponed') AND starts_at < ? AND ends_at > ? FOR UPDATE"
        );
        if ($lock === false) {
            throw new RuntimeException("prepare");
        }
        $lock->bind_param("iss", $expertId, $endsAt, $startsAt);
        $lock->execute();
        $lr = $lock->get_result();
        $row = $lr ? $lr->fetch_assoc() : null;
        $lock->close();
        $c = (int)($row["c"] ?? 0);
        if ($c > 0) {
            $conn->rollback();
            return "Ese hueco acaba de ocuparse. Prueba otro.";
        }

        $cidBind = $clientId !== null && $clientId > 0 ? $clientId : 0;
        $ins = $conn->prepare(
            "INSERT INTO expert_appointments (expert_id, service_id, starts_at, ends_at, guest_name, guest_email, guest_phone, notes, client_id, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, 0), 'confirmed')"
        );
        if ($ins === false) {
            throw new RuntimeException("prepare ins");
        }
        $ins->bind_param(
            "iissssssi",
            $expertId,
            $serviceId,
            $startsAt,
            $endsAt,
            $guestName,
            $guestEmail,
            $guestPhone,
            $notes,
            $cidBind
        );
        if (!$ins->execute()) {
            $dup = (int)($conn->errno ?? 0) === 1062;
            $ins->close();
            if ($dup) {
                $conn->rollback();
                return "Ese hueco acaba de ocuparse. Prueba otro.";
            }
            throw new RuntimeException("execute");
        }
        $appointmentId = (int)$conn->insert_id;
        $ins->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return "No se pudo guardar la reserva.";
    }

    return null;
}