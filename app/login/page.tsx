import { redirect } from "next/navigation";
import { currentUser } from "@/lib/session";
import LoginForm from "./login-form";

export const metadata = { title: "Acceso de clientes · Danbel" };

export default async function LoginPage({ searchParams }: { searchParams: Promise<{ next?: string }> }) {
  const { next } = await searchParams;
  if (await currentUser()) redirect("/");
  return <main className="access-state">
    <div className="logo">DANBEL<span>DISTRIBUCIONES EXCLUSIVAS</span></div>
    <h1>Acceso de clientes</h1>
    <p>Identifícate para consultar tu catálogo y tus precios.</p>
    <LoginForm next={next ?? "/"}/>
    <p className="login-help">¿No tienes acceso o has olvidado la contraseña? Contacta con Danbel.</p>
  </main>;
}
