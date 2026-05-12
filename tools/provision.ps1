<#
.SYNOPSIS
  Crea una nueva landing page basada en una existente (template) en XAMPP local.

.DESCRIPTION
  Automatiza los pasos manuales de "Alternativa 1" (single-tenant por carpeta + BD propia):
    1. Copia la carpeta template -> pagina-<Slug>\ (sin uploads, logs ni configs).
    2. Crea la BD MySQL con CHARACTER SET utf8mb4.
    3. Pide credenciales SMTP de forma interactiva y genera mail_config.php.
    4. Genera db_config.php apuntando a la nueva BD.
    5. Genera admin_bootstrap.php con el correo y la clave de admin que pasaste.
    6. Llama una vez a admin.php para que db.php cree las tablas y siembre el admin.

  Vive dentro del repo en pagina1\tools\provision.ps1 para que se versione
  con el template. Se excluye del FTP deploy (ver .github/workflows/deploy.yml)
  porque es una herramienta local del desarrollador, no codigo de produccion.

.PARAMETER Slug
  Identificador del nuevo proyecto. Solo minusculas, numeros, guion y guion bajo.
  Ej: "juan", "maria-asesorias", "cliente_01".

.PARAMETER AdminEmail
  Correo real del admin de la nueva landing. Sera el correo de login y de
  recuperacion. NO debe coincidir con el correo SMTP (rol distinto).

.PARAMETER AdminPassword
  Clave inicial del admin (>=10 caracteres, con mayuscula, minuscula y numero).
  Esta clave ira en plano dentro de admin_bootstrap.php hasta que la borres
  tras el primer login. db.php la hashea con bcrypt al insertarla en MySQL.

.PARAMETER Template
  Carpeta que se va a clonar (default: pagina1). Debe estar dentro de
  C:\xampp\htdocs\ y contener al menos db.php, admin.php e index.php.

.PARAMETER DbHost / DbUser / DbPass
  Credenciales de tu MySQL local. Defaults pensados para XAMPP "out of the box"
  (127.0.0.1 / root / sin clave).

.PARAMETER XamppRoot
  Ruta base de XAMPP. Default: C:\xampp.

.PARAMETER SkipAutoSeed
  Si lo pasas, el script termina sin abrir admin.php. Tendras que visitarlo tu
  para que db.php cree las tablas y siembre el admin.

.PARAMETER Force
  Si la carpeta destino ya existe, la machaca. Por defecto el script aborta
  para no perder datos por error.

.PARAMETER NoWait
  No espera Enter al final. Usa esto en scripts/CI o si ejecutas provision.cmd
  (el .cmd ya hace pause).

.EXAMPLE
  C:\xampp\htdocs\pagina1\tools\provision.ps1 `
    -Slug "juan" `
    -AdminEmail "juan@correo.com" `
    -AdminPassword "Juan2026Seguro!"

.EXAMPLE
  # Reusando otro template como base (cualquier landing previa puede ser template)
  C:\xampp\htdocs\pagina1\tools\provision.ps1 -Slug "maria" `
    -AdminEmail "maria@correo.com" -AdminPassword "Maria2026!" `
    -Template "pagina-juan"
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$Slug,
    [Parameter(Mandatory = $true)][string]$AdminEmail,
    [Parameter(Mandatory = $true)][string]$AdminPassword,
    [string]$Template     = "pagina1",
    [string]$DbHost       = "127.0.0.1",
    [string]$DbUser       = "root",
    [string]$DbPass       = "",
    [string]$XamppRoot    = "C:\xampp",
    [switch]$SkipAutoSeed,
    [switch]$NoSmtp,
    [switch]$Force,
    [switch]$NoWait
)

$ErrorActionPreference = "Stop"

function Get-ProvisionFinalPauseNeeded {
    if ($NoWait) { return $false }
    # provision.cmd hace pause despues; evitar doble Enter.
    if ($env:PROVISION_FROM_BATCH -eq '1') { return $false }
    if (-not [Environment]::UserInteractive) { return $false }
    try {
        if ([Console]::IsInputRedirected) { return $false }
    } catch { }
    return $true
}
$script:ProvisionFinalPause = Get-ProvisionFinalPauseNeeded

function Write-ManualCloseHint([switch]$Failure) {
    Write-Host ""
    if ($Failure) {
        Write-Host "Operacion finalizada con error. Revisa los mensajes anteriores y cierra esta ventana manualmente." -ForegroundColor Yellow
    } else {
        Write-Host "Operacion finalizada. Cierra esta ventana manualmente cuando quieras." -ForegroundColor DarkCyan
    }
}

function Complete-ProvisionSession([int]$ExitCode) {
    if ($ExitCode -ne 0) {
        Write-ManualCloseHint -Failure
    } else {
        Write-ManualCloseHint
    }
    if ($script:ProvisionFinalPause) {
        Write-Host ""
        Read-Host "Pulsa Enter para cerrar esta ventana (si no, el proceso termina y puede cerrarse sola)"
    }
    exit $ExitCode
}

function Stop-Provision([int]$Code = 1) {
    Complete-ProvisionSession $Code
}

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
    Stop-Provision 1
}

function PhpQuote([string]$Value) {
    # Escapa ' y \ para encerrar en comilla simple PHP.
    if ($null -eq $Value) { $Value = "" }
    return ($Value -replace '\\', '\\') -replace "'", "\'"
}

function Read-HostTrim([string]$Prompt) {
    # Read-Host puede devolver $null (p. ej. fin de entrada); sin esto, .ToLower() rompe con $ErrorActionPreference Stop.
    $r = Read-Host $Prompt
    if ($null -eq $r) { return "" }
    return $r.Trim()
}

function Read-HostPort([string]$Prompt, [int]$Default = 587) {
    $raw = Read-HostTrim $Prompt
    if ($raw -eq "") { return $Default }
    $n = 0
    if ([int]::TryParse($raw, [ref]$n) -and $n -ge 1 -and $n -le 65535) {
        return $n
    }
    Fail "Puerto SMTP invalido ('$raw'). Usa 1-65535 o Enter para $Default."
}

function PlainFrom-SecureString([System.Security.SecureString]$Sec) {
    if ($null -eq $Sec) { return "" }
    $ptr = [IntPtr]::Zero
    try {
        $ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($Sec)
        return [Runtime.InteropServices.Marshal]::PtrToStringAuto($ptr)
    } finally {
        if ($ptr -ne [IntPtr]::Zero) {
            [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)
        }
    }
}

function Write-PhpFile([string]$Path, [string]$Content) {
    # Escribe en UTF-8 SIN BOM. Los archivos PHP con BOM rompen send.php / headers.
    $utf8NoBom = New-Object System.Text.UTF8Encoding $false
    [System.IO.File]::WriteAllText($Path, $Content, $utf8NoBom)
}

trap {
    Write-Host ""
    Write-Host "ERROR inesperado: $($_.Exception.Message)" -ForegroundColor Red
    if ($_.InvocationInfo.PositionMessage) {
        Write-Host $_.InvocationInfo.PositionMessage -ForegroundColor DarkGray
    }
    Complete-ProvisionSession 1
}

# --- Validaciones ----------------------------------------------------

if ($Slug -notmatch '^[a-z0-9_-]+$') {
    Fail "Slug invalido. Solo minusculas, numeros, '-' y '_' (recibido: $Slug)."
}

if ($AdminEmail -notmatch '^[^@\s]+@[^@\s]+\.[^@\s]+$') {
    Fail "AdminEmail no parece un correo valido: $AdminEmail"
}

if ($AdminPassword.Length -lt 10) {
    Fail "AdminPassword debe tener al menos 10 caracteres."
}
if ($AdminPassword -cnotmatch '[a-z]' -or $AdminPassword -cnotmatch '[A-Z]' -or $AdminPassword -notmatch '\d') {
    Fail "AdminPassword debe incluir minuscula, mayuscula y al menos un numero."
}

$htdocs       = Join-Path $XamppRoot "htdocs"
$templatePath = Join-Path $htdocs $Template
$projectName  = "pagina-$Slug"
$newPath      = Join-Path $htdocs $projectName
$dbName       = "pagina_$Slug"
$mysqlExe     = Join-Path $XamppRoot "mysql\bin\mysql.exe"
$publicUrl    = "http://localhost/$projectName"

if (-not (Test-Path $templatePath)) {
    Fail "No existe el template: $templatePath"
}
foreach ($req in @("db.php", "admin.php", "index.php")) {
    if (-not (Test-Path (Join-Path $templatePath $req))) {
        Fail "El template '$Template' no parece valido: falta $req"
    }
}
if (-not (Test-Path $mysqlExe)) {
    Fail "No encuentro mysql.exe en $mysqlExe. Ajusta -XamppRoot."
}
if (Test-Path $newPath) {
    if ($Force) {
        Write-Warn2 "Carpeta destino ya existe; -Force activo, se borra y recrea."
        Remove-Item -Recurse -Force $newPath
    } else {
        Fail "La carpeta $newPath ya existe. Usa -Force si quieres machacarla."
    }
}

Write-Host ""
Write-Host "==> Provisioning landing nueva" -ForegroundColor Magenta
Write-Host "    Slug         : $Slug"
Write-Host "    Carpeta      : $newPath"
Write-Host "    BD           : $dbName"
Write-Host "    Template     : $templatePath"
Write-Host "    Admin email  : $AdminEmail"
Write-Host "    URL local    : $publicUrl/admin.php"
Write-Host ""

# --- Paso 1: copiar template ----------------------------------------

Write-Step 1 6 "Copiando template -> $projectName\"
# Capturar stdout y stderr (robocopy suele escribir errores en stderr). ">" solo captura stdout.
$robocopyOutput = @(& robocopy $templatePath $newPath /E `
    /XD "uploads" ".git" `
    /XF "db_config.php" "mail_config.php" "admin_bootstrap.php" "*.log" "*.bak" "*.tmp" `
    /NFL /NDL /NJH /NJS /NP 2>&1)
$rcExit = $LASTEXITCODE
# Robocopy considera 0-7 como exito; >=8 son errores reales.
if ($rcExit -ge 8) {
    Write-Host ""
    Write-Host "ERROR: La clonacion del template fallo (robocopy codigo de salida $rcExit)." -ForegroundColor Red
    $detail = ($robocopyOutput | ForEach-Object { "$_" }) -join [Environment]::NewLine
    if ($detail.Trim().Length -gt 0) {
        Write-Host "Salida de robocopy:" -ForegroundColor Yellow
        Write-Host $detail
    } else {
        Write-Host "Robocopy no devolvio texto de diagnostico. Revisa permisos, espacio en disco, antivirus y que origen y destino sean accesibles." -ForegroundColor Yellow
        Write-Host "  Origen : $templatePath"
        Write-Host "  Destino: $newPath"
    }
    Write-Host ""
    Stop-Provision 1
}

foreach ($req in @("db.php", "admin.php", "index.php")) {
    if (-not (Test-Path (Join-Path $newPath $req))) {
        Write-Host ""
        Write-Host "ERROR: La copia no quedo completa: falta '$req' en el destino." -ForegroundColor Red
        Write-Host "  Destino: $newPath" -ForegroundColor Yellow
        Write-Host "  Revisa permisos, espacio en disco o si algun proceso tiene bloqueados archivos." -ForegroundColor Yellow
        Write-Host ""
        Stop-Provision 1
    }
}

# Limpieza post-copia: borrar dumps y backups SQL personales del template,
# preservando solo setup.sql (esquema generico).
Get-ChildItem -Path $newPath -Filter '*.sql' -File -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -ne 'setup.sql' } |
    ForEach-Object {
        Remove-Item -Force $_.FullName
        Write-Warn2 "Excluido del clon: $($_.Name)"
    }
Write-Ok "Carpeta clonada"

# --- Paso 2: crear BD ------------------------------------------------

Write-Step 2 6 "Creando BD MySQL '$dbName'"
$mysqlArgs = @("-h", $DbHost, "-u", $DbUser)
if ($DbPass -ne "") { $mysqlArgs += "-p$DbPass" }

$createSql = "CREATE DATABASE IF NOT EXISTS ``$dbName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
$createOut = & $mysqlExe @mysqlArgs -e $createSql 2>&1
if ($LASTEXITCODE -ne 0) {
    Fail "No se pudo crear la BD. Salida: $createOut"
}
Write-Ok "BD creada (o ya existia)"

# --- Paso 3: mail_config.php (interactivo) ---------------------------

Write-Step 3 6 "Configuracion SMTP para esta landing"

if ($NoSmtp) {
    Write-Warn2 "-NoSmtp activo. Se genera mail_config.php con use_smtp=false (configuralo despues)."
    $useSmtp = $false
} else {
    Write-Host "      (presiona Enter para aceptar el default entre corchetes)" -ForegroundColor DarkGray
    $useSmtpRaw = Read-HostTrim "      Usar SMTP? [s/n] (default s)"
    $useSmtp    = ($useSmtpRaw -eq "" -or $useSmtpRaw.ToLower() -eq "s")
}

if ($useSmtp) {
    $smtpHost = Read-HostTrim "      Host SMTP [smtp.gmail.com]"
    if ($smtpHost -eq "") { $smtpHost = "smtp.gmail.com" }

    $smtpPort = Read-HostPort "      Puerto [587]" 587

    $smtpEnc = Read-HostTrim "      Encryption (tls/ssl) [tls]"
    if ($smtpEnc -eq "") { $smtpEnc = "tls" }

    $smtpUser = Read-HostTrim "      Usuario SMTP (correo completo)"
    while ($smtpUser -eq "") { $smtpUser = Read-HostTrim "      Usuario SMTP (obligatorio)" }

    $smtpPwdSecure = Read-Host "      Clave / App Password (oculta)" -AsSecureString
    $smtpPwd = PlainFrom-SecureString $smtpPwdSecure

    $fromEmail = Read-HostTrim "      From email [$smtpUser]"
    if ($fromEmail -eq "") { $fromEmail = $smtpUser }

    $fromName = Read-HostTrim "      From name [Formulario web]"
    if ($fromName -eq "") { $fromName = "Formulario web" }
} else {
    $smtpHost = "smtp.gmail.com"; $smtpPort = 587; $smtpEnc = "tls"
    $smtpUser = ""; $smtpPwd = ""; $fromEmail = ""; $fromName = "Formulario web"
}

$mailUseSmtp = if ($useSmtp) { "true" } else { "false" }
$mailConfig = @"
<?php
declare(strict_types=1);

return [
    'use_smtp'   => $mailUseSmtp,
    'host'       => '$(PhpQuote $smtpHost)',
    'port'       => $smtpPort,
    'encryption' => '$(PhpQuote $smtpEnc)',
    'username'   => '$(PhpQuote $smtpUser)',
    'password'   => '$(PhpQuote $smtpPwd)',
    'from_email' => '$(PhpQuote $fromEmail)',
    'from_name'  => '$(PhpQuote $fromName)',
];
"@
Write-PhpFile (Join-Path $newPath "mail_config.php") $mailConfig
Write-Ok "mail_config.php creado"

# --- Paso 4: db_config.php ------------------------------------------

Write-Step 4 6 "Generando db_config.php"
$dbConfig = @"
<?php
declare(strict_types=1);

return [
    'host'     => '$(PhpQuote $DbHost)',
    'user'     => '$(PhpQuote $DbUser)',
    'password' => '$(PhpQuote $DbPass)',
    'database' => '$(PhpQuote $dbName)',
];
"@
Write-PhpFile (Join-Path $newPath "db_config.php") $dbConfig
Write-Ok "db_config.php creado"

# --- Paso 5: admin_bootstrap.php ------------------------------------

Write-Step 5 6 "Generando admin_bootstrap.php"
$bootstrap = @"
<?php
declare(strict_types=1);

// Generado automaticamente por provision.ps1.
// BORRA este archivo despues de iniciar sesion la primera vez.
return [
    'email'    => '$(PhpQuote $AdminEmail)',
    'password' => '$(PhpQuote $AdminPassword)',
];
"@
Write-PhpFile (Join-Path $newPath "admin_bootstrap.php") $bootstrap
Write-Ok "admin_bootstrap.php creado"

# --- Paso 6: seed automatico ----------------------------------------

if ($SkipAutoSeed) {
    Write-Step 6 6 "Auto-seed omitido (-SkipAutoSeed)"
    Write-Warn2 "Visita $publicUrl/admin.php para que db.php cree las tablas y siembre el admin."
} else {
    Write-Step 6 6 "Disparando admin.php para sembrar BD ($publicUrl/admin.php)"
    try {
        $resp = Invoke-WebRequest -Uri "$publicUrl/admin.php" -UseBasicParsing -TimeoutSec 30 -ErrorAction Stop
        if ($resp.StatusCode -eq 200) {
            Write-Ok "Apache respondio 200 OK ($($resp.RawContentLength) bytes)"
        } else {
            Write-Warn2 "Status inesperado: $($resp.StatusCode)"
        }
    } catch {
        Write-Warn2 "No se pudo contactar Apache. Revisa que XAMPP este arriba."
        Write-Warn2 "Detalle: $($_.Exception.Message)"
    }

    # Verificar que el admin se sembro.
    $verify = & $mysqlExe @mysqlArgs $dbName -B -N -e "SELECT COUNT(*) FROM admins WHERE email='$AdminEmail'" 2>&1
    if ($LASTEXITCODE -eq 0 -and $verify.Trim() -eq "1") {
        Write-Ok "Admin sembrado correctamente en BD"
    } else {
        Write-Warn2 "No pude verificar el admin en BD (resp='$verify'). Visita $publicUrl/admin.php manualmente."
    }
}

# --- Resumen final --------------------------------------------------

Write-Host ""
Write-Host "============================================================" -ForegroundColor Magenta
Write-Host " LANDING NUEVA LISTA: $projectName" -ForegroundColor Magenta
Write-Host "============================================================" -ForegroundColor Magenta
Write-Host ""
Write-Host " URL admin     : $publicUrl/admin.php"
Write-Host " URL publica   : $publicUrl/"
Write-Host " Admin email   : $AdminEmail"
Write-Host " Admin pass    : (la que pasaste por -AdminPassword)"
Write-Host " BD MySQL      : $dbName"
Write-Host ""
Write-Host " SIGUIENTES PASOS:" -ForegroundColor Yellow
Write-Host "   1. Abre $publicUrl/admin.php e inicia sesion."
Write-Host "   2. Configura los textos del sitio en 'Configuracion General'."
Write-Host "   3. Borra admin_bootstrap.php manualmente:"
Write-Host "      Remove-Item '$newPath\admin_bootstrap.php'"
Write-Host "   4. (Opcional) Prueba la recuperacion de clave por correo."
Write-Host ""

Complete-ProvisionSession 0
