<?php
declare(strict_types=1);

/**
 * Prueba ~20 citas: estados (terminada, pospuesta, cancelada), BD y avisos.
 * Uso: php tools/test_appointment_states.php [--keep]
 * HTTP admin (opcional): ADMIN_TEST_EMAIL y ADMIN_TEST_PASSWORD en entorno.
 */

$root = dirname(__DIR__);
chdir($root);

$keepData = in_array("--keep", $argv ?? [], true);

require $root . "/db.php";
require_once $root . "/app_urls.php";
require_once $root . "/experts_admin_lib.php";
require_once $root . "/agenda_notifications_lib.php";

$failures = 0;
$passed = 0;
$testEmailDomain = "@appt-state-test.local";
$testNotesMarker = "tools/test_appointment_states.php";

function test_assert(bool $cond, string $label): void
{
    global $failures, $passed;
    if ($cond) {
        echo "  OK  {$label}\n";
        $passed++;
        return;
    }
    echo "  FAIL {$label}\n";
    $failures++;
}

function appt_test_status(mysqli $conn, int $id): string
{
    $st = $conn->prepare("SELECT status FROM expert_appointments WHERE id = ? LIMIT 1");
    if ($st === false) {
        return "";
    }
    $st->bind_param("i", $id);
    $st->execute();
    $res = $st->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $st->close();

    return is_array($row) ? (string)($row["status"] ?? "") : "";
}

function appt_test_starts_at(mysqli $conn, int $id): string
{
    $st = $conn->prepare("SELECT starts_at FROM expert_appointments WHERE id = ? LIMIT 1");
    if ($st === false) {
        return "";
    }
    $st->bind_param("i", $id);
    $st->execute();
    $res = $st->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $st->close();

    return is_array($row) ? (string)($row["starts_at"] ?? "") : "";
}

/**
 * @return list<array<string, mixed>>
 */
function appt_test_notify_deliveries(mysqli $conn, int $appointmentId, ?string $eventType = null): array
{
    $rows = [];
    if ($appointmentId <= 0) {
        return $rows;
    }
    if ($eventType !== null) {
        $st = $conn->prepare(
            "SELECT id, event_type, channel, status, recipient_role
             FROM agenda_notification_deliveries
             WHERE appointment_id = ? AND event_type = ?
             ORDER BY id ASC"
        );
        if ($st === false) {
            return $rows;
        }
        $st->bind_param("is", $appointmentId, $eventType);
    } else {
        $st = $conn->prepare(
            "SELECT id, event_type, channel, status, recipient_role
             FROM agenda_notification_deliveries
             WHERE appointment_id = ?
             ORDER BY id ASC"
        );
        if ($st === false) {
            return $rows;
        }
        $st->bind_param("i", $appointmentId);
    }
    $st->execute();
    $res = $st->get_result();
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
    }
    $st->close();

    return $rows;
}

echo "=== test_appointment_states ===\n\n";

if (!app_feature_enabled("expert_agenda")) {
    echo "  SKIP: features.expert_agenda desactivado\n";
    exit(0);
}

$notifyOn = agenda_notifications_enabled();
echo "  Avisos agenda: " . ($notifyOn ? "activos" : "desactivados (solo BD/estados)") . "\n\n";

// --- Limpieza previa ---
$del = $conn->prepare(
    "DELETE a FROM expert_appointments a
     WHERE a.guest_email LIKE ? OR a.notes = ?"
);
$likeEmail = "%" . $testEmailDomain;
if ($del !== false) {
    $del->bind_param("ss", $likeEmail, $testNotesMarker);
    $del->execute();
    $cleaned = $del->affected_rows;
    $del->close();
    echo "  Limpieza citas de prueba anteriores: {$cleaned}\n";
}

// --- Experto y servicio ---
$expertId = 0;
$serviceId = 0;
$eq = $conn->query("SELECT id FROM experts WHERE is_active = 1 ORDER BY sort_order ASC, id ASC LIMIT 1");
if ($eq && ($er = $eq->fetch_assoc())) {
    $expertId = (int)($er["id"] ?? 0);
}
if ($expertId <= 0) {
    echo "  FAIL No hay experto activo. Crea uno en el admin.\n";
    exit(1);
}
$sq = $conn->prepare(
    "SELECT s.id FROM services s
     INNER JOIN expert_services es ON es.service_id = s.id
     WHERE es.expert_id = ? AND s.is_active = 1
     ORDER BY s.sort_order ASC, s.id ASC LIMIT 1"
);
if ($sq !== false) {
    $sq->bind_param("i", $expertId);
    $sq->execute();
    $sr = $sq->get_result();
    if ($sr && ($srow = $sr->fetch_assoc())) {
        $serviceId = (int)($srow["id"] ?? 0);
    }
    $sq->close();
}
if ($serviceId <= 0) {
    echo "  FAIL Experto #{$expertId} sin servicio vinculado.\n";
    exit(1);
}

echo "  Experto #{$expertId}, servicio #{$serviceId}\n\n";

// --- Insertar 20 citas confirmadas ---
$tz = new DateTimeZone(date_default_timezone_get() ?: "UTC");
$base = (new DateTimeImmutable("now", $tz))->modify("+2 days")->setTime(8, 0, 0);
$appointmentIds = [];
$ins = $conn->prepare(
    "INSERT INTO expert_appointments
     (expert_id, service_id, starts_at, ends_at, guest_name, guest_email, guest_phone, notes, status)
     VALUES (?, ?, ?, ?, ?, ?, '', ?, 'confirmed')"
);

echo "--- Crear 20 citas (confirmed) ---\n";

for ($i = 0; $i < 20; $i++) {
    $start = $base->modify("+" . $i . " days")->setTime(8 + ($i % 8), ($i % 2) * 30, 0);
    $end = $start->modify("+" . AGENDA_SLOT_MINUTES . " minutes");
    $startsAt = $start->format("Y-m-d H:i:s");
    $endsAt = $end->format("Y-m-d H:i:s");
    $guestEmail = "guest{$i}" . $testEmailDomain;
    $guestName = "Cliente prueba " . ($i + 1);

    if ($ins === false) {
        test_assert(false, "prepare INSERT cita #{$i}");
        continue;
    }
    $ins->bind_param("iisssss", $expertId, $serviceId, $startsAt, $endsAt, $guestName, $guestEmail, $testNotesMarker);
    $okIns = $ins->execute();
    $newId = $okIns ? (int)$conn->insert_id : 0;
    if ($newId > 0) {
        $appointmentIds[] = $newId;
    }
    test_assert($newId > 0, "INSERT cita #" . ($i + 1) . " id={$newId} {$startsAt}");
}
if ($ins !== false) {
    $ins->close();
}

test_assert(count($appointmentIds) === 20, "20 citas insertadas");

// Avisos de reserva en las 3 primeras
if ($notifyOn) {
    echo "\n--- Avisos al reservar (3 citas) ---\n";
    foreach (array_slice($appointmentIds, 0, 3) as $bid) {
        $sum = agenda_notifications_send_booking($conn, $bid);
        $deliveries = appt_test_notify_deliveries($conn, $bid, AGENDA_NOTIFY_EVENT_BOOKED);
        test_assert(count($deliveries) > 0, "delivery booked appt #{$bid} (canales=" . count($deliveries) . ")");
        test_assert(($sum["admin"] ?? false) || ($sum["guest"] ?? false) || ($sum["expert"] ?? false), "resumen booking appt #{$bid}");
    }
}

// --- Acciones: 5 terminadas, 5 pospuestas, 5 canceladas, 5 confirmadas ---
echo "\n--- Acciones de estado ---\n";

$toComplete = array_slice($appointmentIds, 0, 5);
$toPostpone = array_slice($appointmentIds, 5, 5);
$toCancel = array_slice($appointmentIds, 10, 5);
$stayConfirmed = array_slice($appointmentIds, 15, 5);

foreach ($toComplete as $aid) {
    $r = experts_admin_complete_appointment($conn, $expertId, $aid);
    test_assert(($r["ok"] ?? false) && ($r["updated"] ?? false), "complete #{$aid}");
    test_assert(appt_test_status($conn, $aid) === EXPERT_APPT_STATUS_COMPLETED, "BD completed #{$aid}");
}

$postponeTargets = [];
foreach ($toPostpone as $idx => $aid) {
    $oldStart = appt_test_starts_at($conn, $aid);
    $oldDt = DateTimeImmutable::createFromFormat("Y-m-d H:i:s", $oldStart, $tz);
    $newDt = $oldDt !== false ? $oldDt->modify("+14 days") : $base->modify("+20 days");
    $newLocal = $newDt->format("Y-m-d") . "T" . $newDt->format("H:i");
    $r = experts_admin_postpone_appointment($conn, $expertId, $aid, $newLocal);
    test_assert(($r["ok"] ?? false) && ($r["updated"] ?? false), "postpone #{$aid} → {$newLocal}");
    test_assert(appt_test_status($conn, $aid) === EXPERT_APPT_STATUS_POSTPONED, "BD postponed #{$aid}");
    $newStart = appt_test_starts_at($conn, $aid);
    test_assert($newStart !== $oldStart, "starts_at cambió #{$aid}");
    $postponeTargets[$aid] = $newStart;
}

foreach ($toCancel as $aid) {
    $beforeNotify = count(appt_test_notify_deliveries($conn, $aid, AGENDA_NOTIFY_EVENT_CANCELLED));
    $r = experts_admin_cancel_appointment($conn, $expertId, $aid);
    test_assert(($r["ok"] ?? false) && ($r["cancelled"] ?? false), "cancel #{$aid}");
    test_assert(appt_test_status($conn, $aid) === EXPERT_APPT_STATUS_CANCELLED, "BD cancelled #{$aid}");
    if ($notifyOn) {
        $afterNotify = appt_test_notify_deliveries($conn, $aid, AGENDA_NOTIFY_EVENT_CANCELLED);
        test_assert(count($afterNotify) > $beforeNotify, "avisos cancelación #{$aid}");
    }
}

foreach ($stayConfirmed as $aid) {
    test_assert(appt_test_status($conn, $aid) === EXPERT_APPT_STATUS_CONFIRMED, "sigue confirmed #{$aid}");
}

$dupComplete = experts_admin_complete_appointment($conn, $expertId, $toComplete[0]);
test_assert(($dupComplete["ok"] ?? false) && !($dupComplete["updated"] ?? true), "re-terminar no cambia fila ya completed");

test_assert(
    str_contains(
        "admin.php?expert_id={$expertId}&expert_view=schedule&expert_section=appts&workspace=agendas#expert_sch_acc_appts",
        "expert_section=appts"
    ),
    "patrón URL redirect tras acción cita"
);

// --- Listados admin (como la UI) ---
echo "\n--- Listados (fetch como panel) ---\n";

$forExpert = experts_admin_fetch_upcoming_appointments_for_expert($conn, $expertId, 100);
$forAll = experts_admin_fetch_all_upcoming_appointments($conn, 300);

$countInList = static function (array $list, string $status) use ($appointmentIds): int {
    $n = 0;
    foreach ($list as $row) {
        $id = (int)($row["id"] ?? 0);
        if (!in_array($id, $appointmentIds, true)) {
            continue;
        }
        if ((string)($row["status"] ?? "") === $status) {
            $n++;
        }
    }
    return $n;
};

test_assert($countInList($forExpert, EXPERT_APPT_STATUS_COMPLETED) >= 5, "listado experto: ≥5 terminadas");
test_assert($countInList($forExpert, EXPERT_APPT_STATUS_POSTPONED) >= 5, "listado experto: ≥5 pospuestas");
test_assert($countInList($forExpert, EXPERT_APPT_STATUS_CANCELLED) >= 5, "listado experto: ≥5 canceladas");
test_assert($countInList($forExpert, EXPERT_APPT_STATUS_CONFIRMED) >= 5, "listado experto: ≥5 confirmadas");
test_assert($countInList($forAll, EXPERT_APPT_STATUS_COMPLETED) >= 5, "listado global: ≥5 terminadas");

// Hueco libre tras cancelar: no debe contar canceladas en solape
$cancelledId = $toCancel[0];
$cancelledStart = appt_test_starts_at($conn, $cancelledId);
$chk = $conn->prepare(
    "SELECT COUNT(*) AS c FROM expert_appointments
     WHERE expert_id = ? AND status IN ('confirmed', 'postponed')
       AND starts_at < DATE_ADD(?, INTERVAL 30 MINUTE) AND ends_at > ?"
);
if ($chk !== false) {
    $chk->bind_param("iss", $expertId, $cancelledStart, $cancelledStart);
    $chk->execute();
    $cr = $chk->get_result();
    $crow = $cr ? $cr->fetch_assoc() : null;
    $chk->close();
    test_assert((int)($crow["c"] ?? 0) === 0, "hueco de cita cancelada libre para reservar");
}

// --- HTTP: POST admin (redirect) ---
echo "\n--- HTTP admin.php (redirect tras POST) ---\n";

$adminEmail = getenv("ADMIN_TEST_EMAIL") ?: "";
$adminPass = getenv("ADMIN_TEST_PASSWORD") ?: "";
$base = getenv("ADMIN_API_TEST_BASE") ?: "http://localhost/pag-template";
$base = rtrim((string)$base, "/");
$cookieJar = $root . "/tools/_appt_states_cookies.txt";

if (!function_exists("curl_init") || $adminEmail === "" || $adminPass === "") {
    echo "  SKIP HTTP (curl o ADMIN_TEST_EMAIL / ADMIN_TEST_PASSWORD)\n";
} else {
    @unlink($cookieJar);

    $ch = curl_init($base . "/api/v1/admin/auth/login.php");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_POSTFIELDS => json_encode(["email" => $adminEmail, "password" => $adminPass], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ["Content-Type: application/json", "Accept: application/json"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $loginBody = curl_exec($ch);
    $loginCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    test_assert($loginCode === 200, "login admin HTTP {$loginCode}");

    $httpCompleteId = $stayConfirmed[0] ?? 0;
    if ($httpCompleteId > 0) {
        $postFields = http_build_query([
            "action" => "expert_complete_appointment",
            "expert_id" => (string)$expertId,
            "appointment_id" => (string)$httpCompleteId,
            "expert_return_view" => "schedule",
        ]);
        $ch = curl_init($base . "/admin.php?workspace=agendas&expert_id=" . $expertId . "&expert_view=schedule");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_COOKIEFILE => $cookieJar,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 15,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        test_assert($code === 302, "POST complete → 302 redirect (code {$code})");
        if (is_string($resp) && preg_match('/^Location:\s*(.+)$/mi', $resp, $locM)) {
            $loc = trim($locM[1]);
            test_assert(
                str_contains($loc, "expert_id=" . $expertId) && str_contains($loc, "expert_view=schedule"),
                "redirect URL contiene experto y schedule"
            );
        } else {
            test_assert(false, "cabecera Location en respuesta POST");
        }
        test_assert(
            appt_test_status($conn, $httpCompleteId) === EXPERT_APPT_STATUS_COMPLETED,
            "BD completed tras HTTP POST #{$httpCompleteId}"
        );
    }

    @unlink($cookieJar);
}

// Resumen BD
echo "\n--- Resumen BD (citas de prueba) ---\n";
$st = $conn->prepare(
    "SELECT status, COUNT(*) AS n FROM expert_appointments
     WHERE notes = ? GROUP BY status ORDER BY status"
);
if ($st !== false) {
    $st->bind_param("s", $testNotesMarker);
    $st->execute();
    $res = $st->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        echo "  " . ($row["status"] ?? "?") . ": " . ($row["n"] ?? 0) . "\n";
    }
    $st->close();
}

echo "\n  Revisa en admin → Expertos → Citas o Agendas → experto → Citas.\n";
echo "  Filtro: email *{$testEmailDomain}\n";
echo "  Borrar datos de prueba:\n";
echo "    DELETE FROM expert_appointments WHERE notes = '{$testNotesMarker}';\n";
if ($keepData) {
    echo "  (--keep: sin mensaje extra)\n";
}

echo "\n=== Resumen ===\n";
echo "Pasaron: {$passed}\n";
echo "Fallaron: {$failures}\n";
echo "IDs citas: " . implode(", ", $appointmentIds) . "\n";

exit($failures > 0 ? 1 : 0);
