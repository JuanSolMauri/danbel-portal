import "server-only";
import postgres from "postgres";

const globalForDb = globalThis as unknown as { sql?: ReturnType<typeof postgres> };

/** Conexión a Postgres (DATABASE_URL). Se reutiliza entre peticiones del mismo proceso. */
export function db() {
  if (!globalForDb.sql) {
    const url = process.env.DATABASE_URL;
    if (!url) throw new Error("DATABASE_URL no está configurado");
    globalForDb.sql = postgres(url, { max: 3, prepare: false });
  }
  return globalForDb.sql;
}
