<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/catalog.php';

$user = require_user();
$access = resolve_access($user);
$customer = $access['customer'];
$overview = $access['admin'] && !$access['preview'];
$families = (!$access['admin'] && $customer === null) ? [] : visible_families($customer);

$selected = null;
foreach ($families as $f) {
    if ($f['code'] === ($_GET['familia'] ?? '')) {
        $selected = $f;
    }
}
$selected ??= $families[0] ?? null;
$catalog = $selected ? family_catalog($selected['code'], (float) ($selected['discount'] ?? 0)) : null;
$totalProducts = array_sum(array_column($families, 'count'));
$record = $customer !== null ? customer_record($customer) : null;
$extra = ['dto1' => (float) ($record['dto1'] ?? 0), 'dto2' => (float) ($record['dto2'] ?? 0)];
$showStock = can_see_stock($access);
$link = fn (string $family) => 'index.php?' . http_build_query(array_filter(['cliente' => $access['preview'], 'familia' => $family]));

page_start('Danbel | Portal de clientes');
site_header('catalogo', $access, $user);
?>
<main class="main" id="catalogo">
  <div class="breadcrumb">Inicio <span>/</span> Catálogo</div>
  <div class="intro">
    <div>
      <p class="eyebrow">PORTAL PROFESIONAL · DANBEL</p>
      <h1>Catálogo de productos</h1>
      <p><?= $overview ? 'Vista de administración: todas las familias a precio de tarifa, sin descuento.' : 'Solo aparecen las familias incluidas en tu tarifa, con tu precio ya calculado.' ?></p>
    </div>
    <span class="catalog-count"><?= count($families) ?> familias · <?= $totalProducts ?> referencias</span>
  </div>

  <?php if ($access['admin']): ?>
  <form class="preview-form" action="index.php" method="get">
    <label for="cliente-preview">Ver el catálogo como un cliente</label>
    <input class="input" id="cliente-preview" name="cliente" inputmode="numeric" pattern="[0-9]{1,6}" placeholder="Código de cliente" value="<?= e($access['preview'] ?? '') ?>">
    <button type="submit" class="btn">Ver catálogo</button>
    <?php if ($access['preview']): ?><a href="index.php">Salir de esta vista</a><?php endif ?>
  </form>
  <?php endif ?>

  <?php if (!$access['admin'] && $customer === null): ?>
    <div class="empty access-empty">Tu cuenta no está asociada a un código de cliente. Contacta con Danbel para activar tu acceso.</div>
  <?php elseif ($customer !== null && !$families): ?>
    <div class="empty access-empty">No hay ninguna familia con descuento asignado para el cliente <?= e($customer) ?>.</div>
  <?php endif ?>

  <?php if ($selected && $catalog): ?>
  <div class="catalog" id="marcas">
    <aside class="filters">
      <div class="filter-title"><?= $overview ? 'FAMILIAS' : 'FAMILIAS DE TU TARIFA' ?></div>
      <div class="family-search"><input class="input" id="family-search" placeholder="Buscar familia..." aria-label="Buscar familia"></div>
      <div class="family-list">
        <?php foreach ($families as $f): ?>
        <a class="brand <?= $f['code'] === $selected['code'] ? 'selected' : '' ?>" href="<?= e($link($f['code'])) ?>" data-name="<?= e(mb_strtolower($f['name'] . ' ' . $f['code'])) ?>">
          <span class="brand-name"><?= e($f['name']) ?><?php if ($f['discount'] !== null): ?><small>DESCUENTO <?= e(percent($f['discount'])) ?></small><?php endif ?></span>
          <span><?= $f['count'] ?></span>
        </a>
        <?php endforeach ?>
      </div>
    </aside>

    <section class="results"
      data-order-key="<?= e(($customer ?? 'admin') . ':' . $selected['code']) ?>"
      data-brand="<?= e($selected['name']) ?>"
      data-customer="<?= e($customer ?? '') ?>"
      data-discount="<?= e(percent((float) ($selected['discount'] ?? 0))) ?>"
      data-dto1="<?= e((string) $extra['dto1']) ?>" data-dto2="<?= e((string) $extra['dto2']) ?>"
      data-order-email="<?= e((string) config('order_email', '')) ?>">
      <div class="results-head">
        <div>
          <h2><?= e($selected['name']) ?></h2>
          <p class="result-count"><?= $selected['count'] ?> referencias<?= $selected['discount'] !== null ? ' · Descuento ' . e(percent($selected['discount'])) : '' ?></p>
        </div>
        <div class="search"><input class="input" id="product-search" placeholder="Buscar producto, referencia o EAN..." aria-label="Buscar producto"></div>
      </div>

      <div class="tabs" role="tablist">
        <button type="button" role="tab" class="tab active" data-tab="pedido" aria-selected="true">Pedido</button>
        <button type="button" role="tab" class="tab" data-tab="fotos" aria-selected="false">Fotos</button>
      </div>

      <div class="tab-panel" data-panel="pedido">
        <section class="inline-order">
          <div class="inline-order-head">
            <div>
              <?php if ($catalog['logo']): ?><img class="sergio-logo" src="<?= e($catalog['logo']) ?>" alt="<?= e($selected['name']) ?>"><?php endif ?>
              <span class="sheet-eyebrow">TARIFA DANBEL</span>
              <strong>Pedido por líneas</strong>
              <p><?= $overview ? 'Precios de tarifa sin descuento.' : 'Precios con tu descuento aplicado.' ?> Los testers (T) van a PVP y son gratis hasta el 10% del importe de los productos (P). Indica las unidades en cada línea.</p>
            </div>
            <span class="order-summary">0 productos · 0,00 €</span>
          </div>
          <div class="sergio-sections">
            <?php foreach ($catalog['sections'] as $section): ?>
            <div class="sergio-section">
              <div class="sergio-section-heading"><h3><?= e($section['name']) ?></h3><span><?= count($section['codes']) ?> referencias</span></div>
              <div class="sergio-section-content">
                <div class="order-table-scroll">
                  <table class="order-table">
                    <thead><tr><th>EAN / Ref.</th><th>Descripción</th><th>Tipo</th><th>PVP</th><th>Tarifa</th><th>Dto.</th><th>Precio neto</th><?php if ($showStock): ?><th>Stock</th><?php endif ?><th>Unidades</th><th>Total</th></tr></thead>
                    <tbody>
                    <?php foreach ($section['codes'] as $code): $p = $catalog['products'][$code]; ?>
                      <tr data-code="<?= e($p['code']) ?>" data-ean="<?= e($p['ean'] ?? '') ?>" data-name="<?= e($p['name']) ?>" data-net="<?= e((string) $p['net']) ?>" data-type="<?= $p['tester'] ? 'T' : 'P' ?>" data-stock="<?= $p['stock'] ?>"
                          data-search="<?= e(mb_strtolower($p['code'] . ' ' . ($p['ean'] ?? '') . ' ' . $p['name'])) ?>">
                        <td><?= e($p['ean'] ?? '—') ?><small><?= e($p['code']) ?></small></td>
                        <td><div class="order-product-cell"><?php if ($p['image']): ?><button type="button" class="zoom-photo" data-zoom="<?= e($p['image']) ?>" aria-label="Ampliar foto de <?= e($p['name']) ?>"><img src="<?= e($p['image']) ?>" alt="" loading="lazy"></button><?php endif ?><span><?= e($p['name']) ?></span></div></td>
                        <td><span class="type-badge type-<?= $p['tester'] ? 't' : 'p' ?>" title="<?= $p['tester'] ? 'Tester' : 'Producto' ?>"><?= $p['tester'] ? 'T' : 'P' ?></span></td>
                        <td><?= euro($p['pvp']) ?></td>
                        <?php if ($p['tester']): ?>
                        <td>—</td><td>—</td>
                        <?php else: ?>
                        <td><?= euro($p['base']) ?></td>
                        <td><?= e(percent((float) ($selected['discount'] ?? 0))) ?></td>
                        <?php endif ?>
                        <td><strong><?= euro($p['net']) ?></strong></td>
                        <?php if ($showStock): ?><td><?= $p['stock'] > 0 ? $p['stock'] : '<span class="muted">—</span>' ?></td><?php endif ?>
                        <td><input type="number" min="0" max="100000" step="1" inputmode="numeric" value="0" aria-label="Unidades de <?= e($p['name']) ?>"></td>
                        <td class="line-total">0,00 €</td>
                      </tr>
                    <?php endforeach ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
            <?php endforeach ?>
            <p class="order-no-results" hidden>No hay productos que coincidan con la búsqueda.</p>
          </div>
          <div class="order-footer">
            <label for="order-notes">Observaciones para el pedido</label>
            <textarea id="order-notes" maxlength="1000" placeholder="Entrega, referencia interna u otras indicaciones..."></textarea>
            <dl class="order-breakdown">
              <div><dt>Productos (P)</dt><dd data-sum="products">0,00 €</dd></div>
              <div><dt>Testers (T) a PVP</dt><dd data-sum="testers">0,00 €</dd></div>
              <div><dt>Testers gratis (hasta el 10% de los productos)</dt><dd data-sum="free">0,00 €</dd></div>
              <?php if ($extra['dto1'] > 0): ?><div><dt>Descuento 1 (<?= e(percent($extra['dto1'])) ?>)</dt><dd data-sum="dto1">0,00 €</dd></div><?php endif ?>
              <?php if ($extra['dto2'] > 0): ?><div><dt>Descuento 2 (<?= e(percent($extra['dto2'])) ?>)</dt><dd data-sum="dto2">0,00 €</dd></div><?php endif ?>
            </dl>
            <div class="order-actions">
              <div><small class="order-units">0 unidades seleccionadas</small><strong class="order-total">Total 0,00 €</strong></div>
              <button type="button" class="btn btn-outline" data-action="clear" disabled>Vaciar</button>
              <button type="button" class="btn btn-outline" data-action="copy" disabled>Copiar pedido</button>
              <button type="button" class="btn" data-action="checkout" disabled>Finalizar compra</button>
            </div>
          </div>
        </section>
      </div>

      <div class="tab-panel" data-panel="fotos" hidden>
        <section class="brand-photo-page">
          <?php $photos = array_filter($catalog['products'], fn ($p) => $p['image']); ?>
          <p class="photo-count"><?= count($photos) ?> fotos disponibles</p>
          <?php if (!$photos): ?>
            <div class="empty">Todavía no hay fotografías de productos para esta familia.</div>
          <?php else: ?>
          <div class="photo-gallery">
            <?php foreach ($photos as $p): ?>
              <article class="photo-tile" data-search="<?= e(mb_strtolower($p['code'] . ' ' . ($p['ean'] ?? '') . ' ' . $p['name'])) ?>"><button type="button" class="photo-tile-zoom" data-zoom="<?= e($p['image']) ?>" aria-label="Ampliar foto de <?= e($p['name']) ?>"><img src="<?= e($p['image']) ?>" alt="" loading="lazy"></button>
                <div class="photo-tile-info"><strong><?= e($p['name']) ?></strong>
                  <dl class="photo-facts">
                    <div><dt>Ref.</dt><dd><?= e($p['code']) ?></dd></div>
                    <div><dt>EAN</dt><dd><?= e($p['ean'] ?? '—') ?></dd></div>
                    <div><dt>PVP</dt><dd><?= euro($p['pvp']) ?></dd></div>
                    <div><dt>Precio base<?= $p['tester'] ? ' (tester)' : '' ?></dt><dd><?= euro($p['net']) ?></dd></div>
                  </dl></div></article>
            <?php endforeach ?>
          </div>
          <?php endif ?>
        </section>
      </div>
    </section>
  </div>
  <p class="portal-note">El pedido se prepara en la página y se envía por correo a Danbel. IVA y transporte según las condiciones comerciales acordadas.</p>
  <?php endif ?>
</main>
<div class="checkout-overlay" id="checkout" hidden>
  <div class="checkout-head">
    <button type="button" class="btn btn-outline btn-sm" id="checkout-back">&#8592; Volver</button>
    <div>
      <h2>Resumen del pedido completo</h2>
      <p class="checkout-meta-text" id="checkout-meta"></p>
    </div>
  </div>
  <div class="checkout-body">
    <div class="checkout-table-wrap">
      <table class="order-table">
        <thead><tr><th>EAN / Ref.</th><th>Descripción</th><th>Tipo</th><th>Precio neto</th><th>Uds.</th><th>Total</th></tr></thead>
        <tbody id="checkout-lines"></tbody>
      </table>
    </div>
    <div class="checkout-breakdown">
      <dl class="checkout-dl" id="checkout-sums"></dl>
      <div id="checkout-notes-section"></div>
      <p class="mail-note">IVA y transporte según las condiciones comerciales acordadas.</p>
    </div>
    <div class="checkout-actions">
      <button type="button" class="btn btn-outline" id="checkout-back2">&#8592; Volver al catálogo</button>
      <button type="button" class="btn" id="checkout-send">Enviar pedido por correo</button>
    </div>
  </div>
</div>
<div class="lightbox" id="lightbox" hidden><button type="button" class="lightbox-close" aria-label="Cerrar">×</button><img alt=""></div>
<script src="assets/portal.js?v=6"></script>
<?php
page_end();
