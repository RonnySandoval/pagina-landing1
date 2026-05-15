<?php
declare(strict_types=1);

/**
 * Estado de la agenda pública (landing o agenda.php). Requiere $conn y app_urls cargado.
 *
 * @param array<string, mixed>|null $getParams Si es null, usa $_GET.
 * @param bool $loadSlots Si false, no calcula huecos (p. ej. vista compacta en la landing).
 * @return array{
 *   publicExpertAgenda: bool,
 *   agendaBookableServices: list<array<string, mixed>>,
 *   agendaSlots: list<array<string, mixed>>,
 *   agendaSlotTable: array{experts: array<int, string>, expert_order: list<int>, rows: list<array<string, mixed>>},
 *   agendaSelectedServiceId: int,
 *   agendaSelectedDate: string,
 *   agendaMinDate: string,
 *   agendaMaxDate: string,
 *   agendaWeekdayLabels: array<int, string>,
 *   agendaShowExpertNames: bool,
 *   agendaSelectedServiceTitle: string
 * }
 */
function agenda_public_load_state(mysqli $conn, ?array $getParams = null, bool $loadSlots = true): array
{
    $get = $getParams ?? $_GET;
    $publicExpertAgenda = app_feature_enabled("expert_agenda");
    $agendaBookableServices = [];
    $agendaSlots = [];
    $agendaSlotTable = ["experts" => [], "expert_order" => [], "rows" => []];
    $agendaSelectedServiceId = 0;
    $agendaSelectedDate = "";
    $agendaMinDate = "";
    $agendaMaxDate = "";
    $agendaWeekdayLabels = [];
    $agendaShowExpertNames = false;
    $agendaSelectedServiceTitle = "";

    if (!$publicExpertAgenda) {
        return [
            "publicExpertAgenda" => false,
            "agendaBookableServices" => [],
            "agendaSlots" => [],
            "agendaSlotTable" => $agendaSlotTable,
            "agendaSelectedServiceId" => 0,
            "agendaSelectedDate" => "",
            "agendaMinDate" => "",
            "agendaMaxDate" => "",
            "agendaWeekdayLabels" => [],
            "agendaShowExpertNames" => false,
            "agendaSelectedServiceTitle" => "",
        ];
    }

    require_once __DIR__ . "/agenda_lib.php";
    $agendaShowExpertNames = false;
    $ssAg = $conn->query("SELECT agenda_show_expert_names FROM site_settings WHERE id = 1 LIMIT 1");
    if ($ssAg && ($ssRow = $ssAg->fetch_assoc())) {
        $agendaShowExpertNames = (int)($ssRow["agenda_show_expert_names"] ?? 0) === 1;
    }
    $agendaWeekdayLabels = agenda_weekday_labels_es();
    $abs = $conn->query(
        "SELECT DISTINCT s.id, s.title, s.icon_class, s.sort_order
         FROM services s
         INNER JOIN expert_services es ON es.service_id = s.id
         INNER JOIN experts e ON e.id = es.expert_id AND e.is_active = 1
         WHERE s.is_active = 1
         ORDER BY s.sort_order ASC, s.id ASC"
    );
    if ($abs) {
        while ($br = $abs->fetch_assoc()) {
            $agendaBookableServices[] = $br;
        }
    }
    $tzAg = new DateTimeZone(date_default_timezone_get() ?: "UTC");
    $todayAg = (new DateTimeImmutable("now", $tzAg))->setTime(0, 0, 0);
    $agendaMinDate = $todayAg->format("Y-m-d");
    $agendaMaxDate = $todayAg->modify("+" . AGENDA_MAX_DAYS_AHEAD . " days")->format("Y-m-d");
    $agendaSelectedServiceId = (int)($get["agenda_service"] ?? 0);
    $agendaSelectedDate = trim((string)($get["agenda_date"] ?? ""));
    if ($agendaSelectedServiceId <= 0 && count($agendaBookableServices) > 0) {
        $agendaSelectedServiceId = (int)$agendaBookableServices[0]["id"];
    }
    if ($agendaSelectedServiceId > 0 && count($agendaBookableServices) > 0) {
        $allowedIds = [];
        foreach ($agendaBookableServices as $bsRow) {
            $allowedIds[(int)($bsRow["id"] ?? 0)] = true;
        }
        if (!isset($allowedIds[$agendaSelectedServiceId])) {
            $agendaSelectedServiceId = (int)$agendaBookableServices[0]["id"];
        }
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $agendaSelectedDate)) {
        $agendaSelectedDate = $agendaMinDate;
    } else {
        $parsedAg = DateTimeImmutable::createFromFormat("Y-m-d", $agendaSelectedDate, $tzAg);
        if ($parsedAg === false) {
            $agendaSelectedDate = $agendaMinDate;
        } else {
            if ($parsedAg < $todayAg) {
                $agendaSelectedDate = $agendaMinDate;
            } elseif ($parsedAg > DateTimeImmutable::createFromFormat("Y-m-d", $agendaMaxDate, $tzAg)) {
                $agendaSelectedDate = $agendaMaxDate;
            } else {
                $agendaSelectedDate = $parsedAg->format("Y-m-d");
            }
        }
    }
    foreach ($agendaBookableServices as $bsTitleRow) {
        if ((int)($bsTitleRow["id"] ?? 0) === $agendaSelectedServiceId) {
            $agendaSelectedServiceTitle = (string)($bsTitleRow["title"] ?? "");
            break;
        }
    }
    if ($loadSlots && $agendaSelectedServiceId > 0) {
        $agendaSlots = agenda_slots_for_service_day($conn, $agendaSelectedServiceId, $agendaSelectedDate);
        $agendaSlotTable = agenda_build_public_slot_table($agendaSlots, $agendaShowExpertNames);
    }

    return [
        "publicExpertAgenda" => true,
        "agendaBookableServices" => $agendaBookableServices,
        "agendaSlots" => $agendaSlots,
        "agendaSlotTable" => $agendaSlotTable,
        "agendaSelectedServiceId" => $agendaSelectedServiceId,
        "agendaSelectedDate" => $agendaSelectedDate,
        "agendaMinDate" => $agendaMinDate,
        "agendaMaxDate" => $agendaMaxDate,
        "agendaWeekdayLabels" => $agendaWeekdayLabels,
        "agendaShowExpertNames" => $agendaShowExpertNames,
        "agendaSelectedServiceTitle" => $agendaSelectedServiceTitle,
    ];
}
