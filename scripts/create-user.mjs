// Crea o actualiza una cuenta de acceso al portal.
//   npm run create-user -- --login 1001 --customer 1001 --password 'Contraseña'
//   npm run create-user -- --login admin@danbel.es --admin --password 'Contraseña'
// Las cuentas se guardan en data/users.json (excluido de git) con la contraseña cifrada (scrypt).
import { randomBytes, randomUUID, scrypt } from "node:crypto";
import { readFile, writeFile } from "node:fs/promises";
import path from "node:path";
import { parseArgs, promisify } from "node:util";

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

const file = path.join(process.cwd(), "data", "users.json");
const users = JSON.parse(await readFile(file, "utf8").catch(() => "[]"));
const salt = randomBytes(16);
const hash = await promisify(scrypt)(values.password, salt, 64);
const login = values.login.trim().toLowerCase();
const user = {
  id: users.find(u => u.login === login)?.id ?? randomUUID(),
  login,
  role: values.admin ? "admin" : "customer",
  customerCode: values.admin ? null : values.customer,
  passwordHash: `scrypt$${salt.toString("base64")}$${hash.toString("base64")}`,
};
await writeFile(file, JSON.stringify([...users.filter(u => u.login !== login), user], null, 2) + "\n", { mode: 0o600 });
console.log(`Cuenta ${login} guardada (${user.role}${user.customerCode ? `, cliente ${user.customerCode}` : ""}).`);
