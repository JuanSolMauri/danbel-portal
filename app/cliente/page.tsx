import { customerDiscounts, familyNames } from "@/lib/catalog";
import { resolveAccess } from "@/lib/access";
import { requireUser } from "@/lib/session";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import Header from "../header";

export const dynamic = "force-dynamic";
export const metadata = { title: "Mi tarifa · Danbel" };

export default async function TariffPage({ searchParams }: { searchParams: Promise<{ cliente?: string }> }) {
  const user = await requireUser("/cliente");
  const { admin, previewCode, customerCode } = resolveAccess(user, (await searchParams).cliente);
  const names = await familyNames();
  const tariff = customerCode
    ? Object.entries(await customerDiscounts(customerCode)).map(([code, discount]) => ({ code, name: names.get(code) ?? code, discount })).sort((a, b) => a.name.localeCompare(b.name, "es"))
    : [];
  const catalogHref = previewCode ? `/?cliente=${encodeURIComponent(previewCode)}` : "/";
  const percent = new Intl.NumberFormat("es-ES", { maximumFractionDigits: 2 });

  return <div className="site">
    <Header active="cliente" admin={admin} customerCode={customerCode ?? undefined} previewCode={previewCode}/>
    <main className="main customer-page">
      <div className="breadcrumb"><a href={catalogHref}>Catálogo</a><span>/</span>Mi tarifa</div>
      <div className="intro">
        <div><p className="eyebrow">PORTAL PROFESIONAL · DANBEL</p><h1>{customerCode ? `Tarifa del cliente ${customerCode}` : "Tarifas de clientes"}</h1><p>Descuentos por familia tal como constan en el ERP.</p></div>
        <Button asChild variant="outline"><a href={catalogHref}>Volver al catálogo</a></Button>
      </div>
      {admin ? <form action="/cliente" method="get" className="customer-picker">
        <label htmlFor="customer-code">Consultar cliente</label>
        <div><Input id="customer-code" name="cliente" placeholder="Código de cliente" defaultValue={previewCode ?? ""} required pattern="[0-9]{1,6}" inputMode="numeric"/><Button type="submit">Ver tarifa</Button></div>
      </form> : null}
      {customerCode ? <section className="customer-panel customer-tariff">
        <h2>Familias y descuentos asignados</h2>
        {tariff.length ? <div className="customer-tariff-table"><table>
          <thead><tr><th scope="col">Familia</th><th scope="col">Código</th><th scope="col">Descuento</th></tr></thead>
          <tbody>{tariff.map(f => <tr key={f.code}><td>{f.name}</td><td>{f.code}</td><td>{percent.format(f.discount)}%</td></tr>)}</tbody>
        </table></div> : <p>Este cliente no tiene familias con descuento asignado.</p>}
      </section> : <div className="empty">{admin ? "Introduce un código de cliente para consultar su tarifa." : "Tu cuenta no está asociada a un código de cliente. Contacta con Danbel."}</div>}
    </main>
  </div>;
}
