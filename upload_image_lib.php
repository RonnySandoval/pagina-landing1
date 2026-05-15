<?php
declare(strict_types=1);

/**
 * Subida de imágenes a uploads/<subdir>/ (servicios, logo, etc.).
 */

function upload_store_image(array $file, string $subdir, string $prefix, ?string $projectRoot = null): array
{
    if (!isset($file["error"]) || $file["error"] === UPLOAD_ERR_NO_FILE) {
        return ["path" => null, "error" => ""];
    }
    if ($file["error"] !== UPLOAD_ERR_OK) {
        return ["path" => null, "error" => "No se pudo subir la imagen."];
    }

    $tmpPath = $file["tmp_name"] ?? "";
    if ($tmpPath === "" || !is_uploaded_file($tmpPath)) {
        return ["path" => null, "error" => "Archivo de imagen inválido."];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string)finfo_file($finfo, $tmpPath) : "";
    if ($finfo) {
        finfo_close($finfo);
    }

    $allowedMimeMap = [
        "image/jpeg" => "jpg",
        "image/png" => "png",
        "image/webp" => "webp",
        "image/gif" => "gif",
        "image/svg+xml" => "svg",
    ];
    if (!isset($allowedMimeMap[$mime])) {
        return ["path" => null, "error" => "Formato no permitido. Usa JPG, PNG, WEBP, GIF o SVG."];
    }

    $safeSubdir = preg_replace('/[^a-z0-9_-]/i', '', $subdir);
    if ($safeSubdir === "") {
        return ["path" => null, "error" => "Subcarpeta de uploads inválida."];
    }

    $root = $projectRoot ?? dirname(__FILE__);
    $uploadsDir = $root . "/uploads/" . $safeSubdir;
    if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0777, true) && !is_dir($uploadsDir)) {
        return ["path" => null, "error" => "No se pudo crear la carpeta de imágenes."];
    }

    $extension = $allowedMimeMap[$mime];
    $fileName = $prefix . bin2hex(random_bytes(8)) . "." . $extension;
    $targetPath = $uploadsDir . "/" . $fileName;

    if (!move_uploaded_file($tmpPath, $targetPath)) {
        return ["path" => null, "error" => "No se pudo guardar la imagen en el servidor."];
    }

    return ["path" => "uploads/" . $safeSubdir . "/" . $fileName, "error" => ""];
}

function upload_store_service_image(array $file, ?string $projectRoot = null): array
{
    return upload_store_image($file, "services", "service_", $projectRoot);
}

function upload_store_logo_image(array $file, ?string $projectRoot = null): array
{
    return upload_store_image($file, "logo", "logo_", $projectRoot);
}

function upload_delete_relative_file(string $relativePath, ?string $projectRoot = null): void
{
    $relativePath = trim($relativePath);
    if ($relativePath === "") {
        return;
    }
    $root = $projectRoot ?? dirname(__FILE__);
    $full = $root . "/" . ltrim($relativePath, "/");
    if (is_file($full)) {
        @unlink($full);
    }
}
