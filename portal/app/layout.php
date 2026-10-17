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
<link rel="stylesheet" href="assets/portal.css?v=1">
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
    <form action="logout.php" method="post"><?= csrf_field() ?><button type="submit" class="btn btn-outline btn-sm">Salir</button></form>
  </div>
</header>
<?php
}
