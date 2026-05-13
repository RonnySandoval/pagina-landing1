# Página personal multi-landing

Esqueleto en PHP + MySQL para crear y administrar varias landing pages
single-tenant con el mismo código base. Cada landing tiene su propia
carpeta, su propia BD y su propio admin, completamente aisladas entre sí.

## Índice

- [Qué es y qué no es](#qué-es-y-qué-no-es)
- [Requisitos](#requisitos)
- [Instalación local (primera vez)](#instalación-local-primera-vez)
- [Crear una landing nueva con `provision.ps1`](#crear-una-landing-nueva-con-provisionps1)
- [Borrar una landing local con `deprovision.ps1`](#borrar-una-landing-local-con-deprovisionps1)
- [Despliegue a producción](#despliegue-a-producción)
- [Portal de clientes](#portal-de-clientes)
- [Recuperación de clave del admin](#recuperación-de-clave-del-admin)
- [Estructura de archivos](#estructura-de-archivos)
- [Decisiones de arquitectura](#decisiones-de-arquitectura)
- [Rol dual de este repo (template + instancia)](#rol-dual-de-este-repo-template--instancia)

---

## Qué es y qué no es

**Es:**

- Una landing personalizable desde un panel de admin web (textos, servicios,
  galería, mensajes recibidos del formulario de contacto).
- Un esqueleto que puedes clonar para crear varias landings independientes,
  cada una con su BD y su admin propios.
- Un **portal de clientes** opcional: registro e inicio de sesión en la misma
  landing (`index.php#area-cliente`), sesión aislada del admin (ver [Portal de clientes](#portal-de-clientes)).

**No es:**

- Un SaaS multi-tenant donde varios usuarios firman desde un mismo panel
  (eso requeriría refactorizar todas las queries con `site_id`; ver
  [Decisiones](#decisiones-de-arquitectura)).
- Un CMS general como WordPress: el esquema es fijo y específico para
  portafolios personales.

## Requisitos

- **Local:** XAMPP (PHP 8.1+, MySQL/MariaDB, Apache). Probado en Windows.
- **Producción:** cualquier hosting compartido con PHP 8.1+, MySQL y
  acceso a SMTP (App Password de Gmail funciona). Probado en InfinityFree.
- Un Gmail (u otro proveedor SMTP) con **contraseña de aplicación** para el
  remitente del formulario de contacto y de los enlaces de recuperación.

## Instalación local (primera vez)

1. Clonar el **template** dentro de `htdocs` con el nombre `pag-template` (así coinciden las rutas de este README y de `provision.ps1`, que usa `pag-template` como carpeta plantilla por defecto):

   ```powershell
   cd C:\xampp\htdocs
   git clone <URL-de-tu-repo-o-fork> pag-template
   ```

   Sustituye `<URL-de-tu-repo-o-fork>` por la URL HTTPS o SSH de **tu** copia del proyecto (fork propio, repo de organización, etc.). Si clonas con otro nombre de carpeta, tendrás que usar `-Template "ese-nombre"` al provisionar landings adicionales.

2. Crear los archivos de configuración a partir de los `*.example.php`:

   ```powershell
   cd pag-template
   Copy-Item db_config.example.php        db_config.php
   Copy-Item mail_config.example.php     mail_config.php
   Copy-Item admin_bootstrap.example.php admin_bootstrap.php
   # Opcional (URLs fijas o depuración de rutas): descomenta la siguiente línea.
   # Copy-Item app_config.example.php app_config.php
   ```

3. Editar:
   - `db_config.php` → host, usuario, clave y nombre de la BD local (en hosting
     compartido el host **no** suele ser `127.0.0.1`; cópialo del panel del proveedor).
   - `mail_config.php` → credenciales SMTP (Gmail con contraseña de aplicación u otro).
   - `admin_bootstrap.php` → correo real y clave inicial del admin.
   - Opcional: si copiaste `app_config.php`, ajusta `public_base_url` si en el servidor
     la URL debe ser fija (proxy, dominio canónico). Si no existe el archivo, la base
     se infiere en cada petición. Tras iniciar sesión, el panel muestra las rutas en
     el acordeón **Rutas (landing y admin)**.

4. Crear la BD vacía en MySQL (con XAMPP/phpMyAdmin) con el nombre que
   pusiste en `db_config.php`. Por ejemplo:

   ```sql
   CREATE DATABASE web_personal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

5. Visitar `http://localhost/pag-template/admin.php` una vez. `db.php`
   creará todas las tablas y sembrará el admin desde `admin_bootstrap.php`.

6. Iniciar sesión con las credenciales del bootstrap y **borrar
   `admin_bootstrap.php`** (ya cumplió su rol). A partir de ahora la clave
   solo vive hasheada con bcrypt en MySQL.

   ```powershell
   Remove-Item admin_bootstrap.php
   ```

> Los archivos `db_config.php`, `mail_config.php`, `admin_bootstrap.php` y el
> opcional `app_config.php` están en `.gitignore`. Nunca subas secretos al repo;
> cada entorno los crea aparte.

> **Nota:** La primera instalación suele ser esta carpeta `pag-template` a mano.
> Las **siguientes** landings en el mismo PC se crean con `provision.ps1` en
> `C:\xampp\htdocs\pagina-<slug>\` (esa carpeta **no** está dentro del git de
> `pag-template` salvo que tú inicialices allí otro repo; ver [Rol dual](#rol-dual-de-este-repo-template--instancia)).

## Crear una landing nueva con `provision.ps1`

`provision.ps1` automatiza la copia del template, la BD vacía y la generación
de configs para una **landing nueva** (`pagina-juan`, `pagina-maria`, etc.).
Las **tablas** y el **admin** en BD se crean al visitar `admin.php` (como en la
instalación manual), salvo que uses `-SkipAutoSeed` y lo hagas tú después.

### Dónde vive

En `tools/provision.ps1` y `tools/provision.cmd`. Se versionan con el template
pero están **excluidos del deploy FTP** a producción.

### Quién lo usa

Solo tú (el dueño / desarrollador) en tu máquina local. Ningún cliente,
ningún usuario final, ni el servidor de producción lo ejecutan.

### Cuándo lo usas

- Cliente nuevo que necesita su propia landing (`pagina-juan`).
- Demo temporal para mostrarle algo a un prospecto.
- Sandbox para probar cambios sin tocar la landing principal.

### Cómo se invoca

Desde PowerShell (recomendado si ya tienes la ventana abierta):

```powershell
C:\xampp\htdocs\pag-template\tools\provision.ps1 `
  -Slug "juan" `
  -AdminEmail "juan@correo.com" `
  -AdminPassword "CambiaEstaClave2026!"
```

Si haces **doble clic** o la consola se cierra sola al terminar, usa el
lanzador que deja el resultado visible y pide **Enter** al final (o `pause`
tras el script):

```text
C:\xampp\htdocs\pag-template\tools\provision.cmd -Slug "juan" -AdminEmail "juan@correo.com" -AdminPassword "CambiaEstaClave2026!"
```

Para **CI o scripts** donde no debe haber pausa al final, añade **`-NoWait`**
al `.ps1`.

Esto crea de un solo golpe:

- Carpeta `C:\xampp\htdocs\pagina-juan\` con el código copiado desde el template
  (sin `.git`, `uploads`, configs sensibles del template, etc.). **Sí se copia**
  `.github/workflows/` (p. ej. `deploy.yml`) para que el clon local pueda
  versionarse con el mismo CI/FTP que el template.
- BD MySQL `pagina_juan` **vacía** (utf8mb4): el nombre de la BD usa el mismo
  *slug* con guion bajo (`pagina_<slug>`); la carpeta usa guion (`pagina-<slug>`).
  El esquema lo crea `db.php` al abrir `admin.php`.
- `db_config.php`, `mail_config.php`, `admin_bootstrap.php` generados dentro de
  la nueva carpeta.
- Por defecto intenta abrir `http://localhost/pagina-juan/admin.php` para
  sembrar tablas y admin; con **`-SkipAutoSeed`** ese paso lo haces tú en el
  navegador.

Luego abres `http://localhost/pagina-juan/admin.php` (si no lo hizo el script),
inicias sesión y borras `admin_bootstrap.php` igual que en la instalación
normal.

Si la copia falla, el script muestra el detalle de **robocopy** en consola.

### Flags útiles

| Flag                      | Para qué                                              |
| ------------------------- | ----------------------------------------------------- |
| `-NoSmtp`                 | No pregunta SMTP, deja `mail_config.php` con `use_smtp=false`. |
| `-SkipAutoSeed`           | No llama a `admin.php` automáticamente.               |
| `-Force`                  | Si la carpeta destino existe, la machaca y recrea.    |
| `-NoWait`                 | No pide Enter al final (automatización; el `.cmd` sigue haciendo `pause`). |
| `-Template "pagina-juan"` | Clona desde otra landing, no desde `pag-template`.         |
| `-DbHost / -DbUser / -DbPass` | Override de credenciales MySQL (default XAMPP).   |

### Lo que el script NO hace

- No sube archivos al hosting (el deploy lo hace GitHub Actions o FTP manual).
- No edita una landing existente (solo crea nuevas).
- No hace backup automático antes de `-Force`.

## Borrar una landing local con `deprovision.ps1`

Operación inversa de `provision.ps1`. Borra la carpeta `pagina-<slug>\` y
la BD `pagina_<slug>` de tu MySQL local. Útil para limpiar landings de
prueba sin dejar bases huérfanas.

```powershell
C:\xampp\htdocs\pag-template\tools\deprovision.ps1 -Slug "demo"
```

Por seguridad pide confirmación interactiva: tienes que volver a escribir
el slug exacto. Para automatizarlo (CI, scripts envolventes), pasa
`-Force`.

### Flags útiles

| Flag             | Para qué                                                   |
| ---------------- | ---------------------------------------------------------- |
| `-Force`         | Salta la confirmación interactiva. Borrar es irreversible. |
| `-KeepDatabase`  | Solo borra la carpeta. La BD se preserva.                  |
| `-KeepFolder`    | Solo borra la BD. La carpeta se preserva.                  |
| `-DbHost / -DbUser / -DbPass` | Override de credenciales MySQL.               |

### Salvaguardas

- Rechaza slugs con caracteres raros (`../`, espacios, etc.).
- Rechaza nombres reservados (`pag-template` no se puede borrar con este script).
- Verifica que la ruta resuelta esté dentro de `htdocs\` antes de tocar
  el filesystem.

## Despliegue a producción

`provision.ps1` no funciona en hosting compartido (sin shell, sin permisos
de `CREATE DATABASE` desde PHP). El **primer despliegue** de cada landing es
manual en el panel y por FTP; **GitHub Actions solo sube archivos** cuando
configuras el workflow: **no crea** cuentas, **no crea** la BD ni el
subdominio.

### Workflow FTP (`deploy.yml`)

En este repo el archivo **`.github/workflows/deploy.yml`** está versionado y
dispara el despliegue por FTP en cada `push` a `main` (y también con
**Run workflow** manual). Si tu copia **no** trae ese archivo (fork antiguo,
repo creado solo con archivos sueltos, o borraste `.github/` por error),
créalo tú:

1. En la raíz del proyecto, crea la ruta `.github/workflows/`.
2. Dentro, crea `deploy.yml` y **pega** el siguiente contenido (debe coincidir
   con el del repositorio; tras un `git pull` puedes copiarlo desde
   `.github/workflows/deploy.yml` en lugar de aquí).

```yaml
name: Deploy to InfinityFree

on:
  push:
    branches:
      - main
  workflow_dispatch:

jobs:
  ftp-deploy:
    name: FTP deploy
    runs-on: ubuntu-latest
    steps:
      - name: Detect FTP configuration
        id: ftp_check
        env:
          FTP_SERVER: ${{ secrets.FTP_SERVER }}
        run: |
          if [ -n "$FTP_SERVER" ]; then
            echo "configured=true" >> "$GITHUB_OUTPUT"
            echo "FTP_SERVER configurado; este repo desplegará al servidor."
          else
            echo "configured=false" >> "$GITHUB_OUTPUT"
            echo "FTP_SERVER no está configurado; modo plantilla, se omite el deploy."
          fi

      - name: Checkout
        if: steps.ftp_check.outputs.configured == 'true'
        uses: actions/checkout@v4

      - name: Sync via FTP
        if: steps.ftp_check.outputs.configured == 'true'
        uses: SamKirkland/FTP-Deploy-Action@v4.3.5
        with:
          server: ${{ secrets.FTP_SERVER }}
          username: ${{ secrets.FTP_USERNAME }}
          password: ${{ secrets.FTP_PASSWORD }}
          # El valor del secret debe terminar en / (p. ej. htdocs/).
          server-dir: ${{ secrets.FTP_SERVER_DIR }}
          local-dir: ./
          dangerous-clean-slate: false
          exclude: |
            **/.git*
            **/.git*/**
            **/.github/**
            **/node_modules/**
            tools/**
            README.md
            mail_config.php
            db_config.php
            admin_bootstrap.php
            app_config.php
            *.log
            uploads/**
            *.sql
```

Luego configura en GitHub **Settings → Secrets and variables → Actions** los
secrets `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD` y `FTP_SERVER_DIR` como
se indica más abajo.

**`FTP_SERVER_DIR`:** debe ser una carpeta remota y **terminar en barra `/`**
(p. ej. `htdocs/` o `htdocs/mi-landing/`). Si falta la barra final, el action
`FTP-Deploy-Action` falla con *server-dir should be a folder (must end with /)*.

### Antes del primer `push` con CI (secrets)

Tienes que tener **ya preparado** en el proveedor (p. ej. InfinityFree):

1. **Espacio web** accesible por FTP (usuario, contraseña, host).
2. **Carpeta remota** donde debe vivir esa instalación (`htdocs`, `public_html`,
   o una subcarpeta si usas `tudominio.com/juan/` o un subdominio que apunte a
   `htdocs/juan/`, etc.). Esa ruta es lo que suele ir en el secret
   **`FTP_SERVER_DIR`**.
3. **Base de datos MySQL** creada en el panel (host SQL, nombre, usuario y
   clave del proveedor).

Los **secrets** del repo (`FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`,
`FTP_SERVER_DIR`) son las credenciales de **ese** sitio concreto. Con **la misma
cuenta de hosting** y varias landings en **subcarpetas o subdominios**, a
menudo repites host/usuario/contraseña FTP y solo cambia **`FTP_SERVER_DIR`**
por repo; si cada landing es **otra cuenta** de hosting, suelen cambiar **los
cuatro** valores.

### Checklist una vez por landing

1. **Panel:** BD nueva (p. ej. nombre tipo `epiz_xxx_juan` en InfinityFree).
   Subdominio o ruta que apunte a una **carpeta nueva** si quieres varias
   landings en un solo hosting.
2. **FTP o CI:** Subir el código (desde `pagina-juan/` o desde la raíz del repo
   que versiones). El `deploy.yml` del template excluye `tools/`, secretos
   locales, `uploads/`, etc.; en el servidor deben existir los configs.
3. **En el servidor** (FTP o administrador de archivos del panel), crear o
   subir `db_config.php`, `mail_config.php` y `admin_bootstrap.php` con datos
   reales: el workflow **no** los sube desde el repo (están en `.gitignore` y en
   la lista `exclude` del deploy). Sin `db_config.php` en el servidor, la app
   intenta conectarse con los valores por defecto de XAMPP y suele responder
   **500**. Opcional: `app_config.php` con `public_base_url` si la URL canónica
   no se detecta bien.
4. Visitar `https://juan.tu-dominio.com/admin.php` una vez → creación de tablas
   y siembra del admin.
5. Iniciar sesión y borrar `admin_bootstrap.php` del servidor.

Si el repo tiene auto-deploy por FTP (`.github/workflows/deploy.yml`), cada
`push` a `main` **actualiza solo** el destino definido por esos secrets. Para
**varias landings** con multirepo, cada repo tiene **sus** secrets (a menudo
solo distinto `FTP_SERVER_DIR` si compartes el mismo FTP). Para un monorepo,
haría falta un workflow con matrix u otros jobs (no incluido por defecto).

## Portal de clientes

Cada landing puede tener **cuentas de cliente** (tabla `clients`). El visitante se **registra
e inicia sesión en la propia landing**, sección **Área de clientes** (`#area-cliente` en
`index.php`): es la misma web pública, con bloques extra cuando hay sesión (por ejemplo
datos pre-rellenados en el formulario de contacto y el panel **Mis mensajes** con el historial
de contacto, **seguimientos** enlazados (`in_reply_to` en `contact_messages`) y las respuestas que deje el admin). La sesión usa otra cookie y nombre
(`client_session_*` en `client_portal_lib.php`), con el mismo aislamiento por carpeta que el admin.

| Archivo / ruta | Uso |
| --- | --- |
| `index.php` + `#area-cliente` | Registro, login, mensajes (nueva consulta y seguimientos) y vista “modo cliente” en la misma página. |

En el panel (**admin.php** → bandeja lateral **Mensajes**), las entradas se **agrupan por cliente** (`client_id`) o, si el mensaje no llevaba sesión de portal, **por correo** del visitante; dentro de cada grupo se muestran los envíos en orden de tiempo.
| `client_login.php` / `client_dashboard.php` | Redirigen a la landing (compatibilidad con enlaces antiguos). |
| `client_logout.php` | Cierra la sesión de cliente y vuelve a la landing. |

El **administrador** no crea usuarios manualmente: solo **modera** (activar/desactivar o
eliminar) en el panel → acordeón **Portal de clientes**. La URL del portal también aparece
en **Rutas** (`app_client_portal_url()` en `app_urls.php`).

Política de clave al registrarse: al menos **10 caracteres**, **mayúscula**, **minúscula** y **número**
(igual que la recuperación de clave del admin). Los clientes **no** tienen “olvidé mi clave” en esta versión.

La tabla `clients` se crea con `db.php`; también figura en `setup.sql` para import manual.

## Recuperación de clave del admin

Cada landing tiene su propio flujo de recuperación, independiente:

1. En la pantalla de login del admin, hay un formulario "Recuperar clave
   por correo" debajo del login.
2. El correo introducido **debe coincidir exactamente** con el que está
   registrado en la tabla `admins` de esa landing (no es búsqueda fuzzy;
   es por seguridad).
3. Si coincide, se genera un token (sha256 hash en BD, válido 30 minutos)
   y se envía un enlace al correo del admin vía SMTP.
4. El enlace abre `admin.php?reset_token=...` donde el admin pone clave
   nueva.

Si el correo no coincide, el flujo aborta silenciosamente y el usuario
solo ve un mensaje genérico ("Si el correo existe, enviamos un enlace…")
para no revelar qué correos son admins.

> **Antes de poner una landing en producción real**, considera quitar la
> traza `admin_ajax_trace("password_reset link=" . $resetUrl)` en
> `admin.php`. Esa línea escribe el token completo en
> `contact_send_trace.log`, lo cual es útil en local pero peligroso en
> producción.

## Estructura de archivos

```
pag-template/
├── admin.php                  Panel de admin (configuración, servicios, mensajes, portal de clientes, reset).
├── index.php                  Landing pública + registro/login clientes (#area-cliente) y contacto.
├── app_urls.php               URLs públicas (landing, admin, portal clientes); respeta app_config opcional.
├── send.php                   Endpoint del formulario de contacto.
├── client_portal_lib.php      Sesión, registro, login y helpers del portal de clientes.
├── client_login.php           Redirección a la landing (#area-cliente); compatibilidad.
├── client_dashboard.php       Redirección a la landing (#area-cliente); compatibilidad.
├── client_logout.php          Cierre de sesión de cliente.
├── db.php                     Conexión + auto-init del esquema + bootstrap del admin.
├── smtp_mail.php              Cliente SMTP minimalista (sin dependencias).
├── setup.sql                  Esquema + seed de referencia (import opcional; ver cabecera del archivo).
├── styles.css, script.js      Frontend.
├── uploads/                   Imágenes subidas desde el admin (no versionado).
│
├── tools/
│   ├── provision.ps1          Script para crear nuevas landings en local
│   ├── provision.cmd          Lanzador: PowerShell + pause (doble clic / ventana que no desaparece)
│   └── deprovision.ps1        Script para borrar landings de prueba
│                              (versionado pero excluido del FTP deploy).
│
├── .github/
│   └── workflows/
│       └── deploy.yml         CI: auto-deploy por FTP de la instancia activa.
│
├── *.example.php              Plantillas públicas de configuración.
├── db_config.php              [GITIGNORED] Credenciales MySQL del entorno.
├── mail_config.php            [GITIGNORED] Credenciales SMTP del entorno.
├── admin_bootstrap.php        [GITIGNORED] Credenciales iniciales del primer admin.
├── app_config.php             [GITIGNORED] Opcional: public_base_url para producción/proxy.
└── README.md                  Este archivo.
```

Tablas en MySQL:

| Tabla                    | Para qué                                    |
| ------------------------ | ------------------------------------------- |
| `admins`                 | Cuentas del panel admin (1 por landing).    |
| `clients`                | Cuentas del portal de clientes (varias por landing). |
| `admin_password_resets`  | Tokens de recuperación de clave (sha256).   |
| `site_settings`          | Textos del sitio (1 fila, id=1).            |
| `services`               | Cards de servicios mostrados en la landing. |
| `service_gallery`        | Imágenes adicionales por servicio.          |
| `contact_messages`       | Mensajes del formulario y seguimientos del cliente; `client_id` si hay sesión; `in_reply_to` enlaza un seguimiento a un mensaje anterior. |

## Decisiones de arquitectura

**Single-tenant por instalación.** Cada landing es una carpeta + una BD
independiente. Pros: aislamiento total, mismo código simple, escalable
manualmente. Contras: no hay registro self-service de usuarios; cada
landing es provisionada por el dueño del proyecto.

**Portal de clientes:** varias filas en `clients` por landing; el alta la hace el visitante
en la landing y el admin solo modera. Sesión y rutas propias (`client_session_*`), sin mezclar
con el panel admin; sigue siendo single-tenant (un sitio por instalación).

**Por qué no multi-tenant compartido (todos en una BD con `site_id`):**
implicaba reescribir todas las queries de `admin.php` e `index.php` para
filtrar por `site_id`, y elevaba el riesgo de fugas de datos entre
clientes. Para hosting compartido y un volumen bajo de landings (10-20),
single-tenant es más simple y más seguro.

**Por qué `admin_bootstrap.php` y no un wizard web:** el wizard requeriría
distinguir "primer arranque" del flujo normal y abrir un endpoint extra de
mutación sin auth. Un archivo en disco que sólo se lee si `admins` está
vacía, y que el admin borra después, es más simple y más auditable.

**Por qué `password_hash` con compatibilidad para texto plano:** las
versiones viejas del proyecto guardaban claves en plano. Para no romper
admins ya existentes en producción al actualizar el código, el handler
de login acepta ambas y rehashea automáticamente al primer login válido.

**Por qué el token de reset se hashea en BD:** si la BD se filtra, los
tokens activos no son utilizables (se necesita el original). El usuario
recibe el original solo por correo (canal lateral).

## Plantilla y repos hijos

Este repositorio puede usarse en uno de dos roles, indistinguibles a nivel
de contenido pero distintos a nivel de configuración en GitHub:

1. **Repo plantilla** (canónico): la fuente de código común para todas las
   landings. **No tiene** secrets de FTP, por lo que `deploy.yml` se ejecuta
   pero detecta "FTP_SERVER no está configurado" y termina sin desplegar
   (modo plantilla, run verde sin acciones reales). Nunca apunta a un
   servidor concreto.
2. **Repo hijo** (instancia): copia del plantilla con sus propios secrets
   de FTP en *Settings > Secrets and variables > Actions*. Cada `push` a
   `main` despliega a **su** servidor. Cada landing real (Juan, Laura,
   Maria, …) tiene su propio repo hijo.

El **contenido versionado** es idéntico en ambos casos; lo único que cambia
es que el hijo añade los secrets `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`
y `FTP_SERVER_DIR`, y que el workflow detecta esa diferencia automáticamente.

### Flujo de trabajo: template + hijos

Cada repo hijo añade el plantilla como remote `upstream`. Eso te permite:

- **Trabajo normal en una landing:** `git add … && git commit && git push`
  (a `origin`, que despliega a su servidor). El plantilla no se entera.
- **Propagar una mejora a varias landings:** la haces en el plantilla, push.
  Luego en cada hijo, **cuando tú decidas**:

  ```powershell
  git fetch upstream
  git log HEAD..upstream/main --oneline      # preview de qué traería
  git merge upstream/main
  git push                                   # despliega solo a este hijo
  ```

- **Llevar un parche puntual de una landing a otra** (cherry-pick entre
  hijos, p. ej. un fix que hiciste en Laura hace 20 días y ahora quieres en
  Juan):

  ```powershell
  cd pagina-juan
  git remote add laura https://github.com/<usuario>/<repo-de-laura>.git
  git fetch laura
  git cherry-pick <hash-del-commit>
  git push
  ```

| Operación                                       | Comando clave                                                          |
| ----------------------------------------------- | ---------------------------------------------------------------------- |
| Preview de cambios de la plantilla              | `git fetch upstream && git log HEAD..upstream/main --oneline`          |
| Aplicar todos los cambios de la plantilla       | `git merge upstream/main`                                              |
| Aplicar **un solo** commit de la plantilla      | `git fetch upstream && git cherry-pick <hash>`                         |
| Llevar un parche de otra landing                | `git remote add <nombre> <url> && git fetch <nombre> && git cherry-pick <hash>` |

Archivos que **nunca viajan** en estos merges (siguen en `.gitignore`):
`db_config.php`, `mail_config.php`, `admin_bootstrap.php`, `app_config.php`,
`uploads/`. La BD MySQL de cada hijo tampoco se toca: el contenido editable
desde el admin sigue siendo exclusivo de esa landing.

### Modelos para escalar a varias landings

| Modelo | Cómo se hace | Cuándo usarlo |
| ------ | ------------ | ------------- |
| **A — Plantilla + repo por landing** *(recomendado)* | Un repo plantilla canónico. Cada landing (`paginajuan`, `paginalaura`, …) tiene su propio repo hijo con secrets de FTP propios; se sincronizan con la plantilla vía `upstream`. | Caso por defecto. Mejoras propagables, cada landing despliega sola, parches cruzables con `cherry-pick`. |
| **B — Monorepo con matriz** | Un solo repo con `landings/juan/`, `landings/maria/`, etc. Workflow con `strategy: matrix` que despliega cada carpeta a su FTP_SERVER_DIR. | Si tienes muchas landings y prefieres una sola consola de CI. Requiere refactor del layout. |
| **C — Provision local + FTP manual** | `provision.ps1` crea `pagina-juan/` local; subes a hosting por FTP a mano. La carpeta no es repo. | Pruebas, demos rápidas, sandbox. No tiene CI ni historial. |

Cuando aparezca un cliente que necesite CI:

1. **En GitHub web**: crea un repo nuevo (`paginajuan`, vacío, sin README).
2. **En local**: provision con `provision.ps1`, luego desde `pagina-juan/`:

   ```powershell
   git init
   git remote add origin https://github.com/<usuario>/paginajuan.git
   git remote add upstream https://github.com/<usuario>/<repo-plantilla>.git
   git add -A
   git commit -m "Initial commit (clonado de plantilla)"
   git push -u origin main
   ```

3. **En el repo nuevo** (`paginajuan` en GitHub): añade los Secrets
   `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`, `FTP_SERVER_DIR` apuntando
   al hosting de Juan. El workflow detectará que `FTP_SERVER` ya está y
   desplegará en el siguiente push.
