// Conexión y esquema compartidos por los scripts de administración.
import { readFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";
import postgres from "postgres";

export async function connect() {
  if (!process.env.DATABASE_URL) {
    console.error("Falta DATABASE_URL (la cadena de conexión de la base de datos).");
    process.exit(1);
  }
  const sql = postgres(process.env.DATABASE_URL, { max: 1, prepare: false, onnotice: () => {} });
  const schema = await readFile(path.join(path.dirname(fileURLToPath(import.meta.url)), "schema.sql"), "utf8");
  await sql.unsafe(schema);
  return sql;
}
