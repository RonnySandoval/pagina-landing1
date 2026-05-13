<#
.SYNOPSIS
  Elimina una landing creada con provision.ps1 (carpeta + BD MySQL).

.DESCRIPTION
  Es la operacion inversa de provision.ps1. Borra:
    1. La BD MySQL  pagina_<Slug>     (DROP DATABASE IF EXISTS)
    2. La carpeta   htdocs\pagina-<Slug>\

  Pide confirmacion interactiva por defecto. Pasale -Force para automatizarlo.
  Por seguridad, rechaza borrar la carpeta del template original (pag-template) o
  cualquier ruta fuera de htdocs.

  Vive dentro del repo en pag-template\tools\deprovision.ps1 para que viaje junto
  con provision.ps1. No se sube por FTP (ver .github/workflows/deploy.yml).

.PARAMETER Slug
  Identificador de la landing a borrar. Mismo formato que en provision.ps1
  (minusculas, numeros, guion y guion bajo). Si provisionaste con
  -Slug "demo", aqui pasas exactamente lo mismo.

.PARAMETER DbHost / DbUser / DbPass
  Credenciales de tu MySQL local. Defaults para XAMPP "out of the box".

.PARAMETER XamppRoot
  Ruta base de XAMPP. Default: C:\xampp.

.PARAMETER KeepDatabase
  Si lo pasas, NO se borra la BD MySQL (solo se borra la carpeta).
  Util si quieres guardar los datos para una migracion posterior.

.PARAMETER KeepFolder
  Si lo pasas, NO se borra la carpeta (solo se borra la BD).
  Util si quieres conservar uploads o configs locales.

.PARAMETER Force
  Salta la confirmacion interactiva. Pensado para CI o scripts envolventes.
  USALO CON CUIDADO: borrar es irreversible.

.EXAMPLE
  C:\xampp\htdocs\pag-template\tools\deprovision.ps1 -Slug "demo"
  # Pide confirmacion antes de borrar pagina_demo y C:\xampp\htdocs\pagina-demo\

.EXAMPLE
  C:\xampp\htdocs\pag-template\tools\deprovision.ps1 -Slug "demo" -Force
  # Borra todo sin preguntar.

.EXAMPLE
  C:\xampp\htdocs\pag-template\tools\deprovision.ps1 -Slug "demo" -KeepDatabase -Force
  # Solo borra la carpeta, conserva la BD pagina_demo.
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$Slug,
    [string]$DbHost     = "127.0.0.1",
    [string]$DbUser     = "root",
    [string]$DbPass     = "",
    [string]$XamppRoot  = "C:\xampp",
    [switch]$KeepDatabase,
    [switch]$KeepFolder,
    [switch]$Force
)

$ErrorActionPreference = "Stop"

function Write-Step([int]$Num, [int]$Total, [string]$Msg) {
    Write-Host ("[{0}/{1}] {2}" -f $Num, $Total, $Msg) -ForegroundColor Cyan
}

function Write-Ok([string]$Msg) {
    Write-Host "      OK  $Msg" -ForegroundColor Green
}

function Write-Warn2([string]$Msg) {
    Write-Host "      !   $Msg" -ForegroundColor Yellow
}

function Fail([string]$Msg) {
    Write-Host "ERROR: $Msg" -ForegroundColor Red
    exit 1
}

# --- Validaciones ----------------------------------------------------

if ($Slug -notmatch '^[a-z0-9_-]+$') {
    Fail "Slug invalido. Solo minusculas, numeros, '-' y '_' (recibido: $Slug)."
}

if ($KeepDatabase -and $KeepFolder) {
    Fail "-KeepDatabase y -KeepFolder juntos no borran nada. Aborta."
}

$htdocs       = Join-Path $XamppRoot "htdocs"
$projectName  = "pagina-$Slug"
$targetPath   = Join-Path $htdocs $projectName
$dbName       = "pagina_$Slug"
$mysqlExe     = Join-Path $XamppRoot "mysql\bin\mysql.exe"

# Salvaguarda fuerte: no permitir borrar el template ni nada fuera de htdocs.
$reservedNames = @("pag-template")
if ($reservedNames -contains $projectName) {
    Fail "Nombre reservado: $projectName. Este script no borra el template original."
}

# Normalizar y comparar contra htdocs para evitar slugs raros tipo "..\..\Windows".
$resolvedHtdocs = (Resolve-Path $htdocs -ErrorAction Stop).Path.TrimEnd('\')
$intendedTarget = [System.IO.Path]::GetFullPath($targetPath).TrimEnd('\')
if (-not $intendedTarget.StartsWith($resolvedHtdocs + '\', [StringComparison]::OrdinalIgnoreCase)) {
    Fail "Ruta fuera de htdocs: $intendedTarget. Aborta por seguridad."
}

if (-not $KeepDatabase -and -not (Test-Path $mysqlExe)) {
    Fail "No encuentro mysql.exe en $mysqlExe. Ajusta -XamppRoot o usa -KeepDatabase."
}

$folderExists = Test-Path $targetPath

Write-Host ""
Write-Host "==> Deprovisioning landing" -ForegroundColor Magenta
Write-Host "    Slug         : $Slug"
Write-Host "    Carpeta      : $targetPath  $(if ($folderExists) { '(existe)' } else { '(no existe)' })"
Write-Host "    BD           : $dbName"
Write-Host ("    Borrar BD    : {0}" -f $(if ($KeepDatabase) { 'NO (-KeepDatabase)' } else { 'si' }))
Write-Host ("    Borrar carp. : {0}" -f $(if ($KeepFolder)   { 'NO (-KeepFolder)'   } else { 'si' }))
Write-Host ""

if (-not $folderExists -and $KeepDatabase) {
    Write-Warn2 "La carpeta no existe y pediste -KeepDatabase. No hay nada que borrar."
    exit 0
}

# --- Confirmacion ----------------------------------------------------

if (-not $Force) {
    $confirm = Read-Host "Confirma escribiendo el slug '$Slug' (o 'n' para abortar)"
    if ($confirm -ne $Slug) {
        Write-Warn2 "Confirmacion no coincide. No se borra nada."
        exit 0
    }
}

# --- Paso 1: drop BD --------------------------------------------------

if ($KeepDatabase) {
    Write-Step 1 2 "Drop BD omitido (-KeepDatabase)"
} else {
    Write-Step 1 2 "Borrando BD MySQL '$dbName'"
    $mysqlArgs = @("-h", $DbHost, "-u", $DbUser)
    if ($DbPass -ne "") { $mysqlArgs += "-p$DbPass" }

    $dropSql = "DROP DATABASE IF EXISTS ``$dbName``;"
    $dropOut = & $mysqlExe @mysqlArgs -e $dropSql 2>&1
    if ($LASTEXITCODE -ne 0) {
        Fail "No se pudo borrar la BD. Salida: $dropOut"
    }
    Write-Ok "BD eliminada (o no existia)"
}

# --- Paso 2: borrar carpeta ------------------------------------------

if ($KeepFolder) {
    Write-Step 2 2 "Borrado de carpeta omitido (-KeepFolder)"
} elseif (-not $folderExists) {
    Write-Step 2 2 "Carpeta '$targetPath' no existe; nada que borrar"
} else {
    Write-Step 2 2 "Borrando carpeta '$targetPath'"
    Remove-Item -Recurse -Force $targetPath
    Write-Ok "Carpeta eliminada"
}

# --- Resumen ---------------------------------------------------------

Write-Host ""
Write-Host "============================================================" -ForegroundColor Magenta
Write-Host " LANDING ELIMINADA: $projectName" -ForegroundColor Magenta
Write-Host "============================================================" -ForegroundColor Magenta
Write-Host ""
Write-Host " Para volver a crearla:"
Write-Host "   C:\xampp\htdocs\pag-template\tools\provision.ps1 -Slug `"$Slug`" ``"
Write-Host "     -AdminEmail `"...`" -AdminPassword `"...`""
Write-Host ""
