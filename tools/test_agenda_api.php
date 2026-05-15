<?php
declare(strict_types=1);

/**
 * Prueba local de agenda_service y API (CLI + HTTP).
 * Uso: php tools/test_agenda_api.php
 */

$root = dirname(__DIR__);
chdir($root);

require $root . "/db.php";
require_once $root . "/app_urls.php";
require_once $root . "/agenda_service.php";

$failures = 0;
$passed = 0;

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

echo "=== agenda_service (CLI) ===\n\n";

if (!app_feature_enabled("expert_agenda")) {
    echo "  SKIP: expert_agenda desactivado en app_config.php\n";
    exit(0);
}

// parse slot token
$tok = agenda_service_slot_token(3, "2026-05-20 10:00:00");
$p = agenda_service_parse_slot_token($tok);
test_assert($p !== null && $p["expert_id"] === 3 && $p["starts_at"] === "2026-05-20 10:00:00", "slot_token roundtrip");
test_assert(agenda_service_parse_slot_token("bad") === null, "slot_token inválido → null");

// GET slots
$slotsResult = agenda_service_get_slots($conn, []);
test_assert($slotsResult["ok"] === true, "get_slots ok");
$data = $slotsResult["data"] ?? [];
test_assert(isset($data["service_id"]) && isset($data["slots"]) && is_array($data["slots"]), "get_slots estructura data");
$serviceId = (int)($data["service_id"] ?? 0);
$date = (string)($data["date"] ?? "");
test_assert($serviceId > 0, "hay service_id seleccionado");
test_assert(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1, "date Y-m-d");

$firstSlot = null;
$bookServiceId = $serviceId;
$bookDate = $date;
foreach ($data["slots"] as $sl) {
    if (!empty($sl["slot_token"])) {
        $firstSlot = $sl;
        break;
    }
}
if ($firstSlot === null && $serviceId > 0) {
    $tryDate = DateTimeImmutable::createFromFormat("Y-m-d", $date) ?: new DateTimeImmutable($date);
    $maxDate = (string)($data["max_date"] ?? $date);
    $maxTry = DateTimeImmutable::createFromFormat("Y-m-d", $maxDate) ?: $tryDate;
    for ($d = 1; $d <= 14 && $firstSlot === null; $d++) {
        $tryDate = $tryDate->modify("+1 day");
        if ($tryDate > $maxTry) {
            break;
        }
        $tryYmd = $tryDate->format("Y-m-d");
        $tryRes = agenda_service_get_slots($conn, ["service_id" => $serviceId, "date" => $tryYmd]);
        if (!($tryRes["ok"] ?? false)) {
            continue;
        }
        foreach ($tryRes["data"]["slots"] ?? [] as $sl) {
            if (!empty($sl["slot_token"])) {
                $firstSlot = $sl;
                $bookDate = $tryYmd;
                break;
            }
        }
    }
}
if ($firstSlot === null) {
    echo "  SKIP reserva: no hay huecos en 14 días (configura expertos/horario en admin)\n";
} else {
    echo "  (hueco en {$bookDate})\n";
    test_assert(isset($firstSlot["slot_token"]), "slot con slot_token");

    $bad = agenda_service_create_booking($conn, [
        "service_id" => $bookServiceId,
        "slot_token" => $firstSlot["slot_token"],
        "guest_name" => "",
        "guest_email" => "bad",
    ], []);
    test_assert($bad["ok"] === false, "reserva rechaza datos inválidos");

    $uniqueEmail = "agenda-api-" . time() . "@example.local";
    $book = agenda_service_create_booking($conn, [
        "service_id" => $bookServiceId,
        "slot_token" => $firstSlot["slot_token"],
        "guest_name" => "Test Agenda API",
        "guest_email" => $uniqueEmail,
        "guest_phone" => "",
        "notes" => "tools/test_agenda_api.php",
        "slot_units" => 1,
    ], []);
    test_assert($book["ok"] === true, "reserva válida");
    $aid = (int)($book["appointment_id"] ?? 0);
    test_assert($aid > 0, "appointment_id > 0");

    if ($aid > 0) {
        $chk = $conn->prepare("SELECT id, guest_email FROM expert_appointments WHERE id = ? LIMIT 1");
        $chk->bind_param("i", $aid);
        $chk->execute();
        $res = $chk->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $chk->close();
        test_assert($row !== null && (string)$row["guest_email"] === $uniqueEmail, "cita en expert_appointments");

        $dup = agenda_service_create_booking($conn, [
            "service_id" => $bookServiceId,
            "slot_token" => $firstSlot["slot_token"],
            "guest_name" => "Dup",
            "guest_email" => "dup@example.local",
        ], []);
        test_assert($dup["ok"] === false, "mismo hueco → rechazado");
    }
}

echo "\n=== HTTP API ===\n\n";

$slotsUrl = getenv("AGENDA_SLOTS_TEST_URL");
if (!is_string($slotsUrl) || $slotsUrl === "") {
    $slotsUrl = "http://localhost/pag-template/api/v1/agenda/slots.php";
}
$bookUrl = getenv("AGENDA_BOOKINGS_TEST_URL");
if (!is_string($bookUrl) || $bookUrl === "") {
    $bookUrl = "http://localhost/pag-template/api/v1/agenda/bookings.php";
}

echo "GET  {$slotsUrl}\n";
echo "POST {$bookUrl}\n";

if (function_exists("curl_init")) {
    $ch = curl_init($slotsUrl . "?service_id=" . urlencode((string)$serviceId) . "&date=" . urlencode($date));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ["Accept: application/json"],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) {
        echo "  SKIP HTTP GET slots\n";
    } else {
        test_assert($code === 200, "GET slots HTTP 200");
        $decoded = json_decode((string)$body, true);
        test_assert(is_array($decoded) && ($decoded["ok"] ?? false) === true, "GET slots JSON ok");
        $httpSlots = $decoded["data"]["slots"] ?? [];
        test_assert(is_array($httpSlots), "GET slots devuelve slots[]");
    }

    $ch = curl_init($slotsUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => "",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body !== false) {
        test_assert($code === 405, "POST slots → 405");
    }

    if ($firstSlot !== null) {
        $payload = json_encode([
            "service_id" => $serviceId,
            "slot_token" => $firstSlot["slot_token"],
            "guest_name" => "HTTP Agenda",
            "guest_email" => "http-agenda-" . time() . "@example.local",
            "notes" => "curl bookings",
        ], JSON_UNESCAPED_UNICODE);
        $ch = curl_init($bookUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ["Content-Type: application/json", "Accept: application/json"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body !== false) {
            $decoded = json_decode((string)$body, true);
            if ($code === 201) {
                test_assert(($decoded["ok"] ?? false) === true, "POST booking HTTP 201");
            } else {
                test_assert($code === 409 || $code === 400, "POST booking HTTP 409/400 si hueco ya reservado en CLI");
            }
        }
    }
} else {
    echo "  SKIP HTTP (sin ext-curl)\n";
}

echo "\n=== Resumen ===\n";
echo "Pasaron: {$passed}\n";
echo "Fallaron: {$failures}\n";

exit($failures > 0 ? 1 : 0);
