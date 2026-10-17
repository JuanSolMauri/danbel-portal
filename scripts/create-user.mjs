// Crea o actualiza una cuenta de acceso al portal.
//   npm run create-user -- --login 1001 --customer 1001 --password 'Contraseña'
//   npm run create-user -- --login admin@danbel.es --admin --password 'Contraseña'
// Las cuentas se guardan en la tabla users de la base de datos con la contraseña cifrada (scrypt).
import { randomBytes, randomUUID, scrypt } from "node:crypto";
import { parseArgs, promisify } from "node:util";
import { connect } from "./db.mjs";

const { values } = parseArgs({ options: {
  login: { type: "string" }, customer: { type: "string" }, password: { type: "string" }, admin: { type: "boolean", default: false },
} });
if (!values.login || !values.password || (!values.admin && !values.customer)) {
  console.error("Uso: --login <email o código> --password <contraseña> (--customer <código> | --admin)");
  process.exit(1);
}
if (values.password.length < 8) {
  console.error("La contraseña debe tener al menos 8 caracteres.");
  process.exit(1);
}

const sql = await connect();
const salt = randomBytes(16);
const hash = await promisify(scrypt)(values.password, salt, 64);
const login = values.login.trim().toLowerCase();
const role = values.admin ? "admin" : "customer";
const customerCode = values.admin ? null : values.customer;
await sql`insert into users (id, login, role, customer_code, password_hash)
          values (${randomUUID()}, ${login}, ${role}, ${customerCode}, ${`scrypt$${salt.toString("base64")}$${hash.toString("base64")}`})
          on conflict (login) do update set role = excluded.role, customer_code = excluded.customer_code, password_hash = excluded.password_hash`;
await sql.end();
console.log(`Cuenta ${login} guardada (${role}${customerCode ? `, cliente ${customerCode}` : ""}).`);
