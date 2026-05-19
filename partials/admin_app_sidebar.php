<?php
declare(strict_types=1);
/** @var string $adminWorkspace */
/** @var array<int, array{id:string, label:string, icon:string, href:string}> $adminWorkspaceNavItems */
/** @var array<int, array{hash:string, label:string, icon:string, panel?:string}> $adminSidebarToolItems */
/** @var array<int, array{hash:string, label:string, icon:string, collapse?:string}> $adminSidebarAgendaItems */
/** @var array<int, array{hash:string, label:string, icon:string, collapse:string, scroll:string}> $adminSidebarInboxItems */
?>
<nav class="admin-app-sidebar" aria-label="Navegación del panel">
  <?php if (count($adminWorkspaceNavItems) > 0): ?>
    <div class="admin-app-sidebar__section">
      <div class="admin-app-sidebar__heading">Áreas</div>
      <ul class="admin-app-sidebar__list">
        <?php foreach ($adminWorkspaceNavItems as $wsItem): ?>
          <li>
            <a
              href="<?= h($wsItem["href"]) ?>"
              class="admin-app-sidebar__link<?= $adminWorkspace === $wsItem["id"] ? " is-active" : "" ?>"
              data-label="<?= h($wsItem["label"]) ?>"
              title="<?= h($wsItem["label"]) ?>"
              <?= $adminWorkspace === $wsItem["id"] ? ' aria-current="page"' : "" ?>
            >
              <i class="<?= h(admin_nav_icon_class($wsItem["icon"])) ?>" aria-hidden="true"></i>
              <span><?= h($wsItem["label"]) ?></span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($adminWorkspace === "manage" && count($adminSidebarToolItems) > 0): ?>
    <div class="admin-app-sidebar__section">
      <div class="admin-app-sidebar__heading">Herramientas</div>
      <ul class="admin-app-sidebar__list">
        <?php foreach ($adminSidebarToolItems as $toolItem): ?>
          <li>
            <a
              href="<?= h(admin_workspace_url("manage", "", $toolItem["hash"])) ?>"
              class="admin-app-sidebar__link js-admin-sidebar-tool"
              data-admin-panel="<?= h($toolItem["panel"] ?? "") ?>"
              data-label="<?= h($toolItem["label"]) ?>"
              title="<?= h($toolItem["label"]) ?>"
            >
              <i class="<?= h(admin_nav_icon_class($toolItem["icon"])) ?>" aria-hidden="true"></i>
              <span><?= h($toolItem["label"]) ?></span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php elseif ($adminWorkspace === "agendas" && count($adminSidebarAgendaItems) > 0): ?>
    <div class="admin-app-sidebar__section">
      <div class="admin-app-sidebar__heading">Agendas</div>
      <ul class="admin-app-sidebar__list">
        <?php foreach ($adminSidebarAgendaItems as $agItem): ?>
          <li>
            <a
              href="<?= h(admin_workspace_url("agendas", "", $agItem["hash"])) ?>"
              class="admin-app-sidebar__link js-admin-sidebar-agenda"
              data-admin-collapse="<?= h($agItem["collapse"] ?? "") ?>"
              data-label="<?= h($agItem["label"]) ?>"
              title="<?= h($agItem["label"]) ?>"
            >
              <i class="<?= h(admin_nav_icon_class($agItem["icon"])) ?>" aria-hidden="true"></i>
              <span><?= h($agItem["label"]) ?></span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php elseif ($adminWorkspace === "inbox" && count($adminSidebarInboxItems) > 0): ?>
    <div class="admin-app-sidebar__section">
      <div class="admin-app-sidebar__heading">Bandeja</div>
      <ul class="admin-app-sidebar__list">
        <?php foreach ($adminSidebarInboxItems as $inboxItem): ?>
          <li>
            <a
              href="<?= h(admin_workspace_url("inbox", "", $inboxItem["hash"])) ?>"
              class="admin-app-sidebar__link js-admin-sidebar-inbox"
              data-admin-collapse="<?= h($inboxItem["collapse"]) ?>"
              data-admin-scroll="<?= h($inboxItem["scroll"]) ?>"
              data-label="<?= h($inboxItem["label"]) ?>"
              title="<?= h($inboxItem["label"]) ?>"
            >
              <i class="<?= h(admin_nav_icon_class($inboxItem["icon"])) ?>" aria-hidden="true"></i>
              <span><?= h($inboxItem["label"]) ?></span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
</nav>
