# Portal de clientes Danbel

Catálogo privado para clientes profesionales: cada cliente entra con su usuario y ve
solo las familias en las que tiene descuento, con su precio ya calculado
(`PREUTARIFA` del artículo menos el % de su familia en `ttarifcli`).

Parte del diseño hecho con ChatGPT Sites, pasado a un proyecto Next.js estándar.

## Datos del ERP

Los datos salen de las tablas DBF del ERP y se convierten con:

```bash
python3 -m pip install dbfread
npm run import-erp -- --dir /ruta/a/los/dbf
```

| Tabla | Uso |
| --- | --- |
| `TFAMILIES.DBF` | Familias (marcas) |
| `tmatpr.DBF` | Artículos: se publican los activos, no obsoletos, en uso, visibles y con `PREUTARIFA` |
| `ttarifcli.DBF` | Descuento % por cliente y familia (`CLIENT`, `FAMILIA`, `DTO`) |

La salida va a `data/erp/` y **no se sube a git** porque contiene los descuentos de los clientes.
Para actualizar precios o descuentos se vuelve a ejecutar el import y se reinicia el servidor.

Las secciones y logos preparados para algunas marcas están en `data/brands.json`. El resto de
marcas se agrupan por la subfamilia del ERP. Las fotos de producto se buscan en
`public/products/<EAN>.jpg`.

## Acceso

Cada cuenta se crea con:

```bash
npm run create-user -- --login 1001 --customer 1001 --password 'contraseña'
npm run create-user -- --login admin@danbel.es --admin --password 'contraseña'
```

Las cuentas se guardan en `data/users.json` (fuera de git) con la contraseña cifrada (scrypt).
El administrador ve todas las familias a precio de tarifa y puede ver el catálogo como cualquier
cliente con `?cliente=CODIGO`.

## Arrancar en local

```bash
npm install
echo "SESSION_SECRET=$(openssl rand -hex 32)" > .env.local
npm run build && npm start
```

## Pendiente

- Ficha de clientes profesionales (nombre, email) para que cada cliente elija su contraseña.
- Elegir alojamiento y pasar cuentas y datos del ERP a una base de datos.
- Descuentos por artículo (`ttarifcli.MATERIAL`), que ahora se ignoran.
- Fotos de producto (hoy solo hay 51).
