"use client";

import { useActionState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { login, type LoginState } from "./actions";

export default function LoginForm({ next }: { next: string }) {
  const [state, action, pending] = useActionState<LoginState, FormData>(login, {});
  return <form action={action} className="login-form">
    <input type="hidden" name="next" value={next}/>
    <label htmlFor="login">Email o código de cliente</label>
    <Input id="login" name="login" autoComplete="username" required autoFocus={!state.login} defaultValue={state.login} key={state.login}/>
    <label htmlFor="password">Contraseña</label>
    <Input id="password" name="password" type="password" autoComplete="current-password" required autoFocus={!!state.login}/>
    {state.error ? <p className="login-error" role="alert">{state.error}</p> : null}
    <Button type="submit" disabled={pending}>{pending ? "Entrando..." : "Entrar"}</Button>
  </form>;
}
