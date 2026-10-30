# Portal de clientes Danbel

Catálogo privado para clientes profesionales. Cada cliente entra con su usuario y ve solo las
familias en las que tiene descuento, con su precio neto ya calculado:

**precio neto = PREUTARIFA del artículo − % de descuento del cliente en esa familia** (`ttarifcli.DTO`).

Está hecho en PHP + MySQL para funcionar en un hosting compartido como el de Arsys, sin nada que
instalar en el servidor. Todo lo que hay que subir está en la carpeta [`portal/`](portal).

## Requisitos

- PHP 8.0 o superior con las extensiones PDO MySQL, mbstring y Zip (habituales en los hostings con PHP).
- Una base de datos MySQL o MariaDB.

## Instalación en Arsys

1. **Base de datos.** En el panel de Arsys crea una base de datos MySQL y apunta el servidor,
   nombre, usuario y contraseña.
2. **Dónde publicarlo.** Lo recomendable es un subdominio, por ejemplo `clientes.danbelgroup.com`,
   apuntando a su propia carpeta. Así la web actual no se toca. Activa el certificado SSL (https) del subdominio.
3. **Configuración.** Copia `portal/app/config.sample.php` como `portal/app/config.php` y rellena
   los datos de la base de datos, un `install_token` (texto largo inventado) y el correo donde
   queréis recibir los pedidos (`order_email`).
4. **Subir los ficheros.** Sube el contenido de la carpeta `portal/` (incluido `app/config.php`)
   a la carpeta del subdominio por FTP o con el gestor de archivos de Arsys.
5. **Instalar.** Abre `https://clientes.danbelgroup.com/instalar.php`, escribe el `install_token`
   y crea la cuenta de administrador. Después deja `install_token` vacío (`''`) en `config.php`.
6. **Cargar los datos.** Entra como administrador, ve a *Administración* y sube
   `TFAMILIES.DBF`, `tmatpr.DBF` y `ttarifcli.DBF` (mejor juntos en un ZIP, ocupa mucho menos).
7. **Crear las cuentas de los clientes** en *Administración → Cuentas de acceso*.

## Actualizar precios y descuentos

Cada vez que cambien en el ERP, vuelve a subir los tres DBF en *Administración*. Se sustituyen
familias, artículos y descuentos de una vez; si algo falla, se mantienen los datos anteriores.
Las cuentas de acceso no se tocan.

Qué se publica:

| Tabla | Uso |
| --- | --- |
| `TFAMILIES.DBF` | Familias (marcas) |
| `tmatpr.DBF` | Artículos activos, no obsoletos, en uso, visibles y con `PREUTARIFA` |
| `ttarifcli.DBF` | Descuento % por cliente y familia. Las filas por artículo (`MATERIAL`) de momento no se usan |

## Fotos y marcas

- Fotos de producto: `portal/img/products/<EAN>.jpg` (o .png). Al volver a cargar los datos se enlazan solas.
- Logos y secciones preparadas de algunas marcas: `portal/app/brands.json`. El resto de marcas se
  agrupan por la subfamilia del ERP.

## Seguridad

- Las contraseñas se guardan cifradas (`password_hash`). Tras 10 intentos fallidos en 15 minutos
  desde la misma conexión se bloquea el acceso un rato.
- `portal/app/` no es accesible desde el navegador (`.htaccess`) y `config.php` no se sube a GitHub.
- Los DBF subidos se borran del servidor nada más cargarlos.

## Probar en local

```bash
cp portal/app/config.sample.php portal/app/config.php   # y rellenar la base de datos local
php -S 127.0.0.1:8000 -t portal
# abrir http://127.0.0.1:8000/instalar.php
# o cargar los datos por línea de comandos: php portal/app/import-cli.php /carpeta/con/los/dbf
```

## Pendiente

- Que cada cliente elija su contraseña (necesita la ficha de clientes profesionales con email).
- Descuentos por artículo (`ttarifcli.MATERIAL`).
- Más fotos de producto (hoy hay 51).
