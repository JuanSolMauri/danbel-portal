import "server-only";
import { scrypt as scryptCb, timingSafeEqual } from "node:crypto";
import { promisify } from "node:util";
import { db } from "./db";

const scrypt = promisify(scryptCb) as (password: string, salt: Buffer, keylen: number) => Promise<Buffer>;

export type User = {
  id: string;
  /** Email o código de cliente con el que se identifica, en minúsculas. */
  login: string;
  role: "admin" | "customer";
  customerCode: string | null;
  passwordHash: string;
};

type Row = { id: string; login: string; role: User["role"]; customer_code: string | null; password_hash: string };
const toUser = (row: Row): User => ({ id: row.id, login: row.login, role: row.role, customerCode: row.customer_code, passwordHash: row.password_hash });

export async function verifyPassword(password: string, stored: string) {
  const [scheme, salt, hash] = stored.split("$");
  if (scheme !== "scrypt" || !salt || !hash) return false;
  const expected = Buffer.from(hash, "base64");
  const actual = await scrypt(password, Buffer.from(salt, "base64"), expected.length);
  return timingSafeEqual(actual, expected);
}

export async function findUserByLogin(login: string) {
  const [row] = await db()<Row[]>`select id, login, role, customer_code, password_hash from users where login = ${login.trim().toLowerCase()}`;
  return row ? toUser(row) : null;
}

export async function findUserById(id: string) {
  if (!/^[0-9a-f-]{36}$/i.test(id)) return null;
  const [row] = await db()<Row[]>`select id, login, role, customer_code, password_hash from users where id = ${id}`;
  return row ? toUser(row) : null;
}
