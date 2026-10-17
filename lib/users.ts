import "server-only";
import { scrypt as scryptCb, timingSafeEqual } from "node:crypto";
import { readFile } from "node:fs/promises";
import path from "node:path";
import { promisify } from "node:util";

const scrypt = promisify(scryptCb) as (password: string, salt: Buffer, keylen: number) => Promise<Buffer>;
const USERS_FILE = path.join(process.cwd(), "data", "users.json");

export type User = {
  id: string;
  /** Email o código de cliente con el que se identifica, en minúsculas. */
  login: string;
  role: "admin" | "customer";
  customerCode: string | null;
  passwordHash: string;
};

export async function readUsers(): Promise<User[]> {
  try {
    return JSON.parse(await readFile(USERS_FILE, "utf8")) as User[];
  } catch (error) {
    if ((error as NodeJS.ErrnoException).code === "ENOENT") return [];
    throw error;
  }
}

export async function verifyPassword(password: string, stored: string) {
  const [scheme, salt, hash] = stored.split("$");
  if (scheme !== "scrypt" || !salt || !hash) return false;
  const expected = Buffer.from(hash, "base64");
  const actual = await scrypt(password, Buffer.from(salt, "base64"), expected.length);
  return timingSafeEqual(actual, expected);
}

export async function findUserByLogin(login: string) {
  const normalized = login.trim().toLowerCase();
  return (await readUsers()).find(user => user.login === normalized) ?? null;
}

export async function findUserById(id: string) {
  return (await readUsers()).find(user => user.id === id) ?? null;
}
