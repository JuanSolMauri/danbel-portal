<?php
// Copia este fichero como config.php y rellena los datos de la base de datos de Arsys.
// config.php no se sube a GitHub.
return [
    'db' => [
        'host' => 'localhost',
        'name' => 'nombre_de_la_base_de_datos',
        'user' => 'usuario',
        'pass' => 'contraseña',
    ],
    // Texto secreto para crear el primer administrador en instalar.php.
    // Déjalo vacío ('') después de instalar para desactivar esa página.
    'install_token' => 'cambia-esto-por-un-texto-largo-y-aleatorio',
    // Dirección a la que los clientes envían los pedidos (se rellena en el correo).
    'order_email' => '',
    // Códigos de cliente que pueden ver la columna Stock (además del administrador).
    'stock_visible_clients' => ['5825'],
    // Conexión a la BD del ERP para importar artículos directamente (sin subir DBF).
    // Descomenta y rellena cuando el ERP sincronice sus tablas en MySQL.
    // 'erp_db' => [
    //     'host' => 'qarx575.danbelgroup.com',
    //     'name' => 'qarx575',
    //     'user' => 'qarx575',
    //     'pass' => 'contraseña_erp',
    // ],

    // Descarga automática de los DBF del ERP vía FTPS o FTP.
    // Rellena con los datos que te dé el proveedor del ERP y descomenta.
    // type: 'ftps' = FTP sobre TLS (recomendado, puerto 21)
    //        'ftp' = FTP sin cifrado (solo si el proveedor no ofrece TLS)
    // 'erp_ftp' => [
    //     'host'       => 'ftp.proveedor-erp.com',
    //     'port'       => 21,
    //     'user'       => 'usuario_readonly',
    //     'pass'       => 'contraseña',
    //     'type'       => 'ftps',
    //     'remote_dir' => '/ruta/a/los/dbf/',
    // ],
];
