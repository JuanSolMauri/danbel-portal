import "server-only";
import type { User } from "./users";

/** El administrador puede ver el portal como cualquier cliente con ?cliente=CODIGO. */
export function resolveAccess(user: User, requested?: string) {
  const admin = user.role === "admin";
  const previewCode = admin && /^\d{1,6}$/.test(requested ?? "") ? requested : undefined;
  const customerCode = admin ? previewCode ?? null : user.customerCode;
  return { admin, previewCode, customerCode };
}
