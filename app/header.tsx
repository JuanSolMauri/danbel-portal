import { LogOut, UserRound } from "lucide-react";
import { Button } from "@/components/ui/button";

export default function Header({ active, admin, customerCode, previewCode }: { active: "catalogo" | "cliente"; admin: boolean; customerCode?: string; previewCode?: string }) {
  const query = previewCode ? `?cliente=${encodeURIComponent(previewCode)}` : "";
  return <header className="header">
    <div className="logo">DANBEL<span>DISTRIBUCIONES EXCLUSIVAS</span></div>
    <nav className="nav">
      <a className={active === "catalogo" ? "active" : ""} href={`/${query}`}>Catálogo</a>
      <a className={active === "cliente" ? "active" : ""} href={`/cliente${query}`}>Mi tarifa</a>
    </nav>
    <div className="header-right">
      <span className="sample-tag">Catálogo privado</span>
      <span className="account"><UserRound size={17}/>{admin ? previewCode ? `Administrador · viendo cliente ${previewCode}` : "Administrador" : `Cliente ${customerCode ?? ""}`}</span>
      <form action="/logout" method="post"><Button type="submit" variant="outline" size="sm"><LogOut size={15}/>Salir</Button></form>
    </div>
  </header>;
}
