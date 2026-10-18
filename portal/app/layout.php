<?php
declare(strict_types=1);

function page_start(string $title): void
{
    ?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<link rel="icon" href="img/favicon.svg">
<link rel="stylesheet" href="assets/portal.css?v=2">
</head>
<body>
<?php
}

function page_end(): void
{
    echo "</body>\n</html>\n";
}

/** Cabecera del portal. $query conserva ?cliente= cuando el administrador ve un cliente. */
function site_header(string $active, array $access, array $user): void
{
    $query = $access['preview'] ? '?cliente=' . urlencode($access['preview']) : '';
    $account = $access['admin']
        ? ($access['preview'] ? 'Administrador · viendo cliente ' . $access['preview'] : 'Administrador')
        : 'Cliente ' . ($access['customer'] ?? '');
    ?>
<header class="header">
  <div class="logo">DANBEL<span>DISTRIBUCIONES EXCLUSIVAS</span></div>
  <nav class="nav">
    <a class="<?= $active === 'catalogo' ? 'active' : '' ?>" href="index.php<?= e($query) ?>">Catálogo</a>
    <a class="<?= $active === 'tarifa' ? 'active' : '' ?>" href="tarifa.php<?= e($query) ?>">Mi tarifa</a>
    <?php if ($access['admin']): ?><a class="<?= $active === 'admin' ? 'active' : '' ?>" href="admin.php">Administración</a><?php endif ?>
  </nav>
  <div class="header-right">
    <span class="sample-tag">Catálogo privado</span>
    <span class="account"><?= e($account) ?></span>
    <?php if ($access['customer'] !== null): ?><button type="button" class="btn btn-outline btn-sm" data-open="customer-data">Datos Cliente</button><?php endif ?>
    <form action="logout.php" method="post"><?= csrf_field() ?><button type="submit" class="btn btn-outline btn-sm">Salir</button></form>
  </div>
</header>
<?php
    if ($access['customer'] !== null) {
        customer_data_dialog($access['customer']);
    }
}

/**
 * Ventana "Datos Cliente". De momento solo hay el código y los descuentos: la ficha del cliente
 * (nombre, NIF, dirección, contacto) llegará cuando se cargue la tabla de clientes del ERP.
 */
function customer_data_dialog(string $customer): void
{
    $stmt = db()->prepare('SELECT COALESCE(f.name, d.family) AS name, d.dto FROM discounts d JOIN families f ON f.code = d.family WHERE d.customer_code = ? AND d.dto > 0 ORDER BY f.name');
    $stmt->execute([(int) $customer]);
    $families = $stmt->fetchAll();
    $pending = '<span class="muted">Pendiente de cargar del ERP</span>';
    ?>
<div class="modal" id="customer-data" hidden role="dialog" aria-modal="true" aria-labelledby="customer-data-title">
  <div class="modal-box">
    <button type="button" class="modal-close" data-close aria-label="Cerrar">×</button>
    <p class="eyebrow">DATOS CLIENTE</p>
    <h2 id="customer-data-title">Cliente <?= e($customer) ?></h2>
    <dl class="customer-facts">
      <div><dt>Código de cliente</dt><dd><?= e($customer) ?></dd></div>
      <div><dt>Nombre comercial</dt><dd><?= $pending ?></dd></div>
      <div><dt>Nombre fiscal</dt><dd><?= $pending ?></dd></div>
      <div><dt>NIF</dt><dd><?= $pending ?></dd></div>
      <div><dt>Dirección</dt><dd><?= $pending ?></dd></div>
      <div><dt>Teléfono</dt><dd><?= $pending ?></dd></div>
      <div><dt>Email</dt><dd><?= $pending ?></dd></div>
    </dl>
    <h3>Familias con descuento (<?= count($families) ?>)</h3>
    <ul class="customer-families">
      <?php foreach ($families as $f): ?><li><span><?= e($f['name']) ?></span><strong><?= e(percent((float) $f['dto'])) ?></strong></li><?php endforeach ?>
    </ul>
  </div>
</div>
<script>
(function () {
  var modal = document.getElementById("customer-data");
  var opener = document.querySelector('[data-open="customer-data"]');
  var close = function () { modal.hidden = true; opener.focus(); };
  opener.addEventListener("click", function () { modal.hidden = false; modal.querySelector("[data-close]").focus(); });
  modal.addEventListener("click", function (ev) { if (ev.target === modal || ev.target.closest("[data-close]")) close(); });
  document.addEventListener("keydown", function (ev) { if (ev.key === "Escape" && !modal.hidden) close(); });
})();
</script>
<?php
}
