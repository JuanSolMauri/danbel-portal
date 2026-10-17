<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/layout.php';
require __DIR__ . '/app/catalog.php';

$user = require_user();
$access = resolve_access($user);
$customer = $access['customer'];
$tariff = [];
if ($customer !== null) {
    $stmt = db()->prepare('SELECT d.family AS code, COALESCE(f.name, d.family) AS name, d.dto FROM discounts d LEFT JOIN families f ON f.code = d.family WHERE d.customer_code = ? ORDER BY name');
    $stmt->execute([(int) $customer]);
    $tariff = $stmt->fetchAll();
}
$catalogHref = 'index.php' . ($access['preview'] ? '?cliente=' . urlencode($access['preview']) : '');

page_start('Mi tarifa · Danbel');
site_header('tarifa', $access, $user);
?>
<main class="main customer-page">
  <div class="breadcrumb"><a href="<?= e($catalogHref) ?>">Catálogo</a><span>/</span>Mi tarifa</div>
  <div class="intro">
    <div><p class="eyebrow">PORTAL PROFESIONAL · DANBEL</p><h1><?= $customer !== null ? 'Tarifa del cliente ' . e($customer) : 'Tarifas de clientes' ?></h1><p>Descuentos por familia tal como constan en el ERP.</p></div>
    <a class="btn btn-outline" href="<?= e($catalogHref) ?>">Volver al catálogo</a>
  </div>
  <?php if ($access['admin']): ?>
  <form action="tarifa.php" method="get" class="customer-picker">
    <label for="customer-code">Consultar cliente</label>
    <div><input class="input" id="customer-code" name="cliente" placeholder="Código de cliente" value="<?= e($access['preview'] ?? '') ?>" required pattern="[0-9]{1,6}" inputmode="numeric"><button type="submit" class="btn">Ver tarifa</button></div>
  </form>
  <?php endif ?>
  <?php if ($customer !== null): ?>
  <section class="customer-panel customer-tariff">
    <h2>Familias y descuentos asignados</h2>
    <?php if ($tariff): ?>
    <div class="customer-tariff-table"><table>
      <thead><tr><th>Familia</th><th>Código</th><th>Descuento</th></tr></thead>
      <tbody><?php foreach ($tariff as $t): ?><tr><td><?= e($t['name']) ?></td><td><?= e($t['code']) ?></td><td><?= e(percent((float) $t['dto'])) ?></td></tr><?php endforeach ?></tbody>
    </table></div>
    <?php else: ?><p>Este cliente no tiene familias con descuento asignado.</p><?php endif ?>
  </section>
  <?php else: ?>
  <div class="empty"><?= $access['admin'] ? 'Introduce un código de cliente para consultar su tarifa.' : 'Tu cuenta no está asociada a un código de cliente. Contacta con Danbel.' ?></div>
  <?php endif ?>
</main>
<?php
page_end();
