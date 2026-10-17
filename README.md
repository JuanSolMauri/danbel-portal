# Portal de clientes Danbel

Catálogo privado para clientes profesionales: cada cliente entra con su usuario y ve
solo las familias en las que tiene descuento, con su precio ya calculado
(`PREUTARIFA` del artículo menos el % de su familia en `ttarifcli`).

Parte del diseño hecho con ChatGPT Sites, pasado a un proyecto Next.js estándar.

## Datos del ERP

Los datos salen de las tablas DBF del ERP. Primero se convierten a JSON y después se suben a la base de datos:

```bash
python3 -m pip install dbfread
npm run import-erp -- --dir /ruta/a/los/dbf   # genera data/erp/*.json
npm run upload-erp                            # los sube a la base de datos
```

| Tabla | Uso |
| --- | --- |
| `TFAMILIES.DBF` | Familias (marcas) |
| `tmatpr.DBF` | Artículos: se publican los activos, no obsoletos, en uso, visibles y con `PREUTARIFA` |
| `ttarifcli.DBF` | Descuento % por cliente y familia (`CLIENT`, `FAMILIA`, `DTO`) |

`data/erp/` **no se sube a git** porque contiene los descuentos de los clientes. La web relee los
datos de la base de datos cada 5 minutos, así que tras `upload-erp` no hace falta volver a publicar.

Las secciones y logos preparados para algunas marcas están en `data/brands.json`. El resto de
marcas se agrupan por la subfamilia del ERP. Las fotos de producto se buscan en
`public/products/<EAN>.jpg`.

## Acceso

Cada cuenta se crea con:

```bash
npm run create-user -- --login 1001 --customer 1001 --password 'contraseña'
npm run create-user -- --login admin@danbel.es --admin --password 'contraseña'
```

Las cuentas se guardan en la tabla `users` con la contraseña cifrada (scrypt). El administrador ve
todas las familias a precio de tarifa y puede ver el catálogo como cualquier cliente con
`?cliente=CODIGO`.

## Configuración

Variables de entorno (en local, en `.env.local`; en Vercel, en Settings → Environment Variables):

| Variable | Valor |
| --- | --- |
| `DATABASE_URL` | Cadena de conexión de Postgres (Neon, Supabase o cualquier Postgres) |
| `SESSION_SECRET` | Texto aleatorio de 32 caracteres o más: `openssl rand -hex 32` |

Las tablas (`scripts/schema.sql`) se crean solas la primera vez que se ejecuta `upload-erp` o `create-user`.

## Arrancar en local

```bash
npm install
npm run build && npm start
```

## Publicar en Vercel

1. Importar este repositorio en Vercel.
2. Añadir una base de datos Postgres (Storage → Neon) y la variable `SESSION_SECRET`.
3. Con `DATABASE_URL` de esa base de datos en `.env.local`, ejecutar `upload-erp` y `create-user`.
4. Para usar `clientes.danbelgroup.com`, añadir el dominio en Vercel y crear en el DNS de Arsys el registro CNAME que indique Vercel.

## Pendiente

- Ficha de clientes profesionales (nombre, email) para que cada cliente elija su contraseña.
- Descuentos por artículo (`ttarifcli.MATERIAL`), que ahora se ignoran.
- Fotos de producto (hoy solo hay 51).
