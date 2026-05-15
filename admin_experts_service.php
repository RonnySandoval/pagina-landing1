<?php
declare(strict_types=1);

require_once __DIR__ . "/app_urls.php";
require_once __DIR__ . "/experts_admin_lib.php";

function admin_experts_require_agenda(): ?array
{
    if (!app_feature_enabled("expert_agenda")) {
        return ["ok" => false, "error" => "feature_disabled"];
    }

    return null;
}

/**
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string}
 */
function admin_experts_service_list(mysqli $conn): array
{
    $deny = admin_experts_require_agenda();
    if ($deny !== null) {
        return $deny;
    }

    $catalog = experts_admin_load_admin_catalog($conn);

    return ["ok" => true, "data" => ["experts" => $catalog["experts"]]];
}

/**
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string}
 */
function admin_experts_service_get(mysqli $conn, int $expertId, bool $includeSchedule = false, string $weekStart = ""): array
{
    $deny = admin_experts_require_agenda();
    if ($deny !== null) {
        return $deny;
    }

    $got = experts_admin_get($conn, $expertId);
    if (!$got["ok"]) {
        return ["ok" => false, "error" => (string)($got["error"] ?? "not_found")];
    }

    $data = experts_admin_format_expert($got["expert"], $got["service_ids"]);
    if ($includeSchedule) {
        $sched = experts_admin_load_schedule($conn, $expertId, $weekStart);
        if ($sched["ok"]) {
            $data["schedule"] = $sched["schedule"];
        }
    }

    return ["ok" => true, "data" => $data];
}

/**
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string}
 */
function admin_experts_service_create(mysqli $conn, array $input): array
{
    $deny = admin_experts_require_agenda();
    if ($deny !== null) {
        return $deny;
    }

    $serviceIds = $input["service_ids"] ?? $input["expert_services"] ?? [];
    if (!is_array($serviceIds)) {
        $serviceIds = [];
    }

    $created = experts_admin_create($conn, $input, $serviceIds);
    if (!$created["ok"]) {
        return ["ok" => false, "error" => (string)($created["error"] ?? "create_failed")];
    }

    return admin_experts_service_get($conn, (int)$created["expert_id"], true);
}

/**
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string}
 */
function admin_experts_service_update(mysqli $conn, int $expertId, array $input): array
{
    $deny = admin_experts_require_agenda();
    if ($deny !== null) {
        return $deny;
    }

    $serviceIds = $input["service_ids"] ?? $input["expert_services"] ?? [];
    if (!is_array($serviceIds)) {
        $serviceIds = [];
    }

    $updated = experts_admin_update($conn, $expertId, $input, $serviceIds);
    if (!$updated["ok"]) {
        return ["ok" => false, "error" => (string)($updated["error"] ?? "update_failed")];
    }

    return admin_experts_service_get($conn, $expertId, false);
}

/**
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string}
 */
function admin_experts_service_delete(mysqli $conn, int $expertId): array
{
    $deny = admin_experts_require_agenda();
    if ($deny !== null) {
        return $deny;
    }

    $deleted = experts_admin_delete($conn, $expertId);
    if (!$deleted["ok"]) {
        return ["ok" => false, "error" => (string)($deleted["error"] ?? "delete_failed")];
    }

    return ["ok" => true, "data" => ["expert_id" => $expertId]];
}

/**
 * @return array{ok: true, data: array<string, mixed>} | array{ok: false, error: string}
 */
function admin_experts_service_week_grid(mysqli $conn, int $expertId, string $weekStart): array
{
    $deny = admin_experts_require_agenda();
    if ($deny !== null) {
        return $deny;
    }

    $exists = experts_admin_assert_exists($conn, $expertId);
    if (!$exists["ok"]) {
        return ["ok" => false, "error" => (string)($exists["error"] ?? "not_found")];
    }

    return [
        "ok" => true,
        "data" => [
            "expert_id" => $expertId,
            "week_grid" => agenda_expert_admin_week_grid($conn, $expertId, $weekStart),
        ],
    ];
}
