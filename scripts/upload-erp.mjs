// Sube a la base de datos los JSON generados por import_erp.py.
//   npm run upload-erp            (lee data/erp/)
import { readFile } from "node:fs/promises";
import path from "node:path";
import { connect } from "./db.mjs";

const dir = path.join(process.cwd(), "data", "erp");
const sql = await connect();
for (const key of ["families", "products", "discounts"]) {
  const data = JSON.parse(await readFile(path.join(dir, `${key}.json`), "utf8"));
  await sql`insert into erp_data (key, data, updated_at) values (${key}, ${sql.json(data)}, now())
            on conflict (key) do update set data = excluded.data, updated_at = now()`;
  console.log(`${key}: ${Array.isArray(data) ? data.length : Object.keys(data).length} registros subidos`);
}
await sql.end();
