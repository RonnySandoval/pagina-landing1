<?php
declare(strict_types=1);

require_once __DIR__ . "/upload_image_lib.php";

/**
 * Configuración global del sitio (tabla site_settings, fila id=1).
 */

/**
 * @return array<string, mixed>
 */
function site_settings_defaults(): array
{
    return [
        "id" => 1,
        "person_name" => "",
        "brand_name" => "",
        "hero_title" => "",
        "hero_intro" => "",
        "about_text" => "",
        "contact_intro" => "",
        "contact_email" => "",
        "contact_whatsapp" => null,
        "contact_whatsapp_country_code" => null,
        "footer_text" => "",
        "logo_image_path" => null,
        "agenda_show_expert_names" => 0,
    ];
}

/**
 * @return array<string, mixed>|null
 */
function site_settings_get(mysqli $conn): ?array
{
    $q = $conn->query("SELECT * FROM site_settings WHERE id = 1 LIMIT 1");
    if (!$q || $q->num_rows !== 1) {
        return null;
    }
    $row = $q->fetch_assoc();
    if (!is_array($row)) {
        return null;
    }

    return $row;
}

/**
 * Normaliza WhatsApp desde campos de formulario o API.
 *
 * @return array{whatsapp: ?string, country_code: ?string}
 */
function site_settings_parse_whatsapp_input(array $input): array
{
    if (array_key_exists("contact_whatsapp", $input) && !array_key_exists("contact_whatsapp_local", $input)) {
        $localDigits = preg_replace('/\D+/', '', (string)($input["contact_whatsapp"] ?? "")) ?? "";
        $countryDigits = preg_replace('/\D+/', '', (string)($input["contact_whatsapp_country_code"] ?? "")) ?? "";
        $localDigits = substr($localDigits, 0, 32);
        $countryDigits = substr($countryDigits, 0, 3);
    } else {
        $waCountryRaw = trim((string)($input["contact_whatsapp_country"] ?? $input["contact_whatsapp_country_code"] ?? ""));
        $waLocalRaw = trim((string)($input["contact_whatsapp_local"] ?? $input["contact_whatsapp"] ?? ""));
        $countryDigits = preg_replace('/\D+/', '', $waCountryRaw) ?? "";
        $localDigits = preg_replace('/\D+/', '', $waLocalRaw) ?? "";
        $countryDigits = substr($countryDigits, 0, 3);
        $localDigits = substr($localDigits, 0, 32);
    }

    if ($localDigits === "") {
        return ["whatsapp" => null, "country_code" => null];
    }
    if ($countryDigits === "") {
        return ["whatsapp" => $localDigits, "country_code" => null];
    }

    return ["whatsapp" => $localDigits, "country_code" => $countryDigits];
}

/**
 * @param array<string, mixed> $fields
 */
function site_settings_validate_update(array $fields): ?string
{
    $required = [
        "person_name",
        "brand_name",
        "hero_title",
        "hero_intro",
        "about_text",
        "contact_intro",
        "contact_email",
        "footer_text",
    ];
    foreach ($required as $key) {
        if (trim((string)($fields[$key] ?? "")) === "") {
            return "missing_" . $key;
        }
    }
    $email = trim((string)($fields["contact_email"] ?? ""));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return "invalid_contact_email";
    }

    return null;
}

/**
 * @param array<string, mixed> $input
 * @return array{ok: true, settings: array<string, mixed>}|array{ok: false, error: string}
 */
function site_settings_update(mysqli $conn, array $input): array
{
    $wa = site_settings_parse_whatsapp_input($input);

    $fields = [
        "person_name" => trim((string)($input["person_name"] ?? "")),
        "brand_name" => trim((string)($input["brand_name"] ?? "")),
        "hero_title" => trim((string)($input["hero_title"] ?? "")),
        "hero_intro" => trim((string)($input["hero_intro"] ?? "")),
        "about_text" => trim((string)($input["about_text"] ?? "")),
        "contact_intro" => trim((string)($input["contact_intro"] ?? "")),
        "contact_email" => trim((string)($input["contact_email"] ?? "")),
        "footer_text" => trim((string)($input["footer_text"] ?? "")),
    ];

    $validation = site_settings_validate_update($fields);
    if ($validation !== null) {
        return ["ok" => false, "error" => $validation];
    }

    $agendaShow = null;
    if (array_key_exists("agenda_show_expert_names", $input)) {
        $agendaShow = filter_var($input["agenda_show_expert_names"], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $agendaShow = $agendaShow === null ? 0 : ($agendaShow ? 1 : 0);
    }

    $stmt = $conn->prepare(
        "UPDATE site_settings
         SET person_name = ?, brand_name = ?, hero_title = ?, hero_intro = ?, about_text = ?,
             contact_intro = ?, contact_email = ?, contact_whatsapp = ?, contact_whatsapp_country_code = ?,
             footer_text = ?"
        . ($agendaShow !== null ? ", agenda_show_expert_names = ?" : "")
        . " WHERE id = 1"
    );
    if ($stmt === false) {
        return ["ok" => false, "error" => "update_failed"];
    }

    $whatsapp = $wa["whatsapp"];
    $country = $wa["country_code"];

    if ($agendaShow !== null) {
        $stmt->bind_param(
            "ssssssssssi",
            $fields["person_name"],
            $fields["brand_name"],
            $fields["hero_title"],
            $fields["hero_intro"],
            $fields["about_text"],
            $fields["contact_intro"],
            $fields["contact_email"],
            $whatsapp,
            $country,
            $fields["footer_text"],
            $agendaShow
        );
    } else {
        $stmt->bind_param(
            "ssssssssss",
            $fields["person_name"],
            $fields["brand_name"],
            $fields["hero_title"],
            $fields["hero_intro"],
            $fields["about_text"],
            $fields["contact_intro"],
            $fields["contact_email"],
            $whatsapp,
            $country,
            $fields["footer_text"]
        );
    }

    if (!$stmt->execute()) {
        $stmt->close();
        return ["ok" => false, "error" => "update_failed"];
    }
    $stmt->close();

    $settings = site_settings_get($conn);

    return [
        "ok" => true,
        "settings" => $settings ?? array_merge(site_settings_defaults(), $fields),
    ];
}

/**
 * @return array{ok: true, logo_image_path: ?string, settings: array<string, mixed>}|array{ok: false, error: string, message?: string}
 */
function site_settings_update_logo(
    mysqli $conn,
    array $file,
    string $currentLogoPath,
    bool $removeLogo,
    ?string $projectRoot = null
): array {
    $root = $projectRoot ?? dirname(__FILE__);
    $currentLogoPath = trim($currentLogoPath);
    $logoPath = $currentLogoPath;
    $logoError = "";

    $logoUpload = upload_store_logo_image($file, $root);
    if ($logoUpload["error"] !== "") {
        return ["ok" => false, "error" => "logo_upload_failed", "message" => (string)$logoUpload["error"]];
    }
    if ($logoUpload["path"] !== null) {
        if ($currentLogoPath !== "") {
            upload_delete_relative_file($currentLogoPath, $root);
        }
        $logoPath = (string)$logoUpload["path"];
    } elseif ($removeLogo) {
        if ($currentLogoPath !== "") {
            upload_delete_relative_file($currentLogoPath, $root);
        }
        $logoPath = "";
    }

    $logoForDb = $logoPath !== "" ? $logoPath : null;
    $stmt = $conn->prepare("UPDATE site_settings SET logo_image_path = ? WHERE id = 1");
    if ($stmt === false) {
        return ["ok" => false, "error" => "update_failed"];
    }
    $stmt->bind_param("s", $logoForDb);
    if (!$stmt->execute()) {
        $stmt->close();
        return ["ok" => false, "error" => "update_failed"];
    }
    $stmt->close();

    $settings = site_settings_get($conn);

    return [
        "ok" => true,
        "logo_image_path" => $logoForDb,
        "settings" => $settings ?? site_settings_defaults(),
    ];
}

/**
 * @return array{ok: true, agenda_show_expert_names: bool, settings: array<string, mixed>}|array{ok: false, error: string}
 */
function site_settings_set_agenda_show_expert_names(mysqli $conn, bool $show): array
{
    $val = $show ? 1 : 0;
    $stmt = $conn->prepare("UPDATE site_settings SET agenda_show_expert_names = ? WHERE id = 1");
    if ($stmt === false) {
        return ["ok" => false, "error" => "update_failed"];
    }
    $stmt->bind_param("i", $val);
    if (!$stmt->execute()) {
        $stmt->close();
        return ["ok" => false, "error" => "update_failed"];
    }
    $stmt->close();

    $settings = site_settings_get($conn);

    return [
        "ok" => true,
        "agenda_show_expert_names" => $show,
        "settings" => $settings ?? site_settings_defaults(),
    ];
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function site_settings_format_for_api(array $row): array
{
    return [
        "id" => (int)($row["id"] ?? 1),
        "person_name" => (string)($row["person_name"] ?? ""),
        "brand_name" => (string)($row["brand_name"] ?? ""),
        "hero_title" => (string)($row["hero_title"] ?? ""),
        "hero_intro" => (string)($row["hero_intro"] ?? ""),
        "about_text" => (string)($row["about_text"] ?? ""),
        "contact_intro" => (string)($row["contact_intro"] ?? ""),
        "contact_email" => (string)($row["contact_email"] ?? ""),
        "contact_whatsapp" => $row["contact_whatsapp"] ?? null,
        "contact_whatsapp_country_code" => $row["contact_whatsapp_country_code"] ?? null,
        "footer_text" => (string)($row["footer_text"] ?? ""),
        "logo_image_path" => $row["logo_image_path"] ?? null,
        "agenda_show_expert_names" => (int)($row["agenda_show_expert_names"] ?? 0) === 1,
    ];
}
