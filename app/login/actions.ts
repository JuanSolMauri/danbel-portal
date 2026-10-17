"use server";

import { redirect } from "next/navigation";
import { startSession } from "@/lib/session";
import { findUserByLogin, verifyPassword } from "@/lib/users";

export type LoginState = { error?: string; login?: string };

function safeNext(value: FormDataEntryValue | null) {
  const next = typeof value === "string" ? value : "/";
  return next.startsWith("/") && !next.startsWith("//") ? next : "/";
}

export async function login(_prev: LoginState, form: FormData): Promise<LoginState> {
  const loginName = String(form.get("login") ?? "");
  const password = String(form.get("password") ?? "");
  const user = loginName && password ? await findUserByLogin(loginName) : null;
  if (!user || !(await verifyPassword(password, user.passwordHash))) {
    return { error: "Usuario o contraseña incorrectos.", login: loginName };
  }
  await startSession(user);
  redirect(safeNext(form.get("next")));
}
