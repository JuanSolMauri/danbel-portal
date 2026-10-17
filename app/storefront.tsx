"use client";

import { useMemo, useState } from "react";
import { Search } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
import type { BrandView } from "@/lib/catalog";
import BrandOrder from "./brand-order";
import BrandPhotos from "./brand-photos";
import Header from "./header";

type Props = { brands: BrandView[]; admin: boolean; previewCode?: string; customerCode?: string };

export default function Storefront({ brands, admin, previewCode, customerCode }: Props) {
  const [familyCode, setFamilyCode] = useState(brands[0]?.code ?? "");
  const [familyQuery, setFamilyQuery] = useState("");
  const [query, setQuery] = useState("");
  const [view, setView] = useState("pedido");
  // Unidades y observaciones por familia, para no perder el pedido al cambiar de marca.
  const [quantities, setQuantities] = useState<Record<string, Record<string, number>>>({});
  const [notes, setNotes] = useState<Record<string, string>>({});

  const selected = brands.find(b => b.code === familyCode);
  const totalProducts = useMemo(() => brands.reduce((sum, b) => sum + b.products.length, 0), [brands]);
  const filteredBrands = brands.filter(b => `${b.name} ${b.code}`.toLocaleLowerCase("es").includes(familyQuery.toLocaleLowerCase("es")));
  const choose = (code: string) => { setFamilyCode(code); setQuery(""); setView("pedido"); };
  const adminOverview = admin && !previewCode;

  return <div className="site">
    <Header active="catalogo" admin={admin} customerCode={customerCode} previewCode={previewCode}/>
    <main className="main" id="catalogo">
      <div className="breadcrumb">Inicio <span>/</span> Catálogo</div>
      <div className="intro">
        <div>
          <p className="eyebrow">PORTAL PROFESIONAL · DANBEL</p>
          <h1>Catálogo de productos</h1>
          <p>{adminOverview ? "Vista de administración: todas las familias a precio de tarifa, sin descuento." : "Solo aparecen las familias incluidas en tu tarifa, con tu precio ya calculado."}</p>
        </div>
        <span className="catalog-count">{brands.length} familias · {totalProducts} referencias</span>
      </div>

      {admin ? <form className="preview-form" action="/" method="get">
        <label htmlFor="cliente-preview">Ver el catálogo como un cliente</label>
        <Input id="cliente-preview" name="cliente" inputMode="numeric" pattern="[0-9]{1,6}" placeholder="Código de cliente" defaultValue={previewCode ?? ""}/>
        <Button type="submit">Ver catálogo</Button>
        {previewCode ? <a href="/">Salir de esta vista</a> : null}
      </form> : null}

      {!admin && !customerCode ? <div className="empty access-empty">Tu cuenta no está asociada a un código de cliente. Contacta con Danbel para activar tu acceso.</div> : null}
      {customerCode && brands.length === 0 ? <div className="empty access-empty">No hay ninguna familia con descuento asignado para el cliente {customerCode}.</div> : null}

      {selected ? <div className="catalog" id="marcas">
        <aside className="filters">
          <div className="filter-title">{adminOverview ? "FAMILIAS" : "FAMILIAS DE TU TARIFA"}</div>
          <div className="family-search"><Search size={16}/><Input value={familyQuery} onChange={e => setFamilyQuery(e.target.value)} placeholder="Buscar familia..." aria-label="Buscar familia"/></div>
          <div className="family-list">{filteredBrands.map(b => <button key={b.code} className={`brand ${familyCode === b.code ? "selected" : ""}`} onClick={() => choose(b.code)}>
            <span className="brand-name">{b.name}{b.discount !== null ? <small>DESCUENTO {b.discount}%</small> : null}</span>
            <span>{b.products.length}</span>
          </button>)}</div>
        </aside>
        <section className="results">
          <div className="results-head">
            <div><h2>{selected.name}</h2><p className="result-count">{selected.products.length} referencias{selected.discount !== null ? ` · Descuento ${selected.discount}%` : ""}</p></div>
            <div className="search"><Search size={18}/><Input value={query} onChange={e => setQuery(e.target.value)} placeholder="Buscar producto, referencia o EAN..." aria-label="Buscar producto"/></div>
          </div>
          <Tabs value={view} onValueChange={setView} className="brand-tabs">
            <TabsList aria-label={`Vistas de ${selected.name}`}><TabsTrigger value="pedido">Pedido</TabsTrigger><TabsTrigger value="fotos">Fotos</TabsTrigger></TabsList>
            <TabsContent value="pedido">
              <BrandOrder key={selected.code} brandName={selected.name} priceListLabel="TARIFA DANBEL" versionLabel={adminOverview ? "Precios de tarifa sin descuento" : "Precios con tu descuento aplicado"}
                logoSrc={selected.logo ?? undefined} products={selected.products} sections={selected.sections} discount={selected.discount ?? 0} customerCode={customerCode} query={query}
                quantities={quantities[selected.code] ?? {}}
                setQuantities={update => setQuantities(current => ({ ...current, [selected.code]: typeof update === "function" ? update(current[selected.code] ?? {}) : update }))}
                notes={notes[selected.code] ?? ""}
                setNotes={update => setNotes(current => ({ ...current, [selected.code]: typeof update === "function" ? update(current[selected.code] ?? "") : update }))}/>
            </TabsContent>
            <TabsContent value="fotos"><BrandPhotos products={selected.products} sections={selected.sections} query={query}/></TabsContent>
          </Tabs>
        </section>
      </div> : null}
      {brands.length > 0 ? <p className="portal-note">El pedido se prepara en la página y se envía por correo a Danbel; IVA y transporte según las condiciones comerciales acordadas.</p> : null}
    </main>
  </div>;
}
