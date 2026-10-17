"use client";

import { useMemo, useState, type Dispatch, type SetStateAction } from "react";
import { Mail, Copy, Check } from "lucide-react";
import { Button } from "@/components/ui/button";

type Product = {code:string;name:string;ean?:string|null;pvp:number|null;orderPrice?:number;image?:string|null};
type Section = {name:string;images:string[];codes:string[]};
type Props = {brandName:string;priceListLabel:string;versionLabel:string;logoSrc?:string;
  products:Product[];sections:Section[];customerCode?:string;discount:number;query:string;
  quantities:Record<string,number>;setQuantities:Dispatch<SetStateAction<Record<string,number>>>;
  notes:string;setNotes:Dispatch<SetStateAction<string>>};
const euro = (value:number) => new Intl.NumberFormat("es-ES",{style:"currency",currency:"EUR"}).format(value);

export default function BrandOrder({brandName,priceListLabel,versionLabel,logoSrc,products,sections,customerCode,discount,query,quantities,setQuantities,notes,setNotes}:Props) {
  const [copied,setCopied]=useState(false);
  const lines=useMemo(()=>products.filter(p=>(quantities[p.code]??0)>0).map(p=>({
    ...p,quantity:quantities[p.code],subtotal:Math.round((p.orderPrice??0)*quantities[p.code]*100)/100
  })),[products,quantities]);
  const total=lines.reduce((sum,line)=>sum+line.subtotal,0);
  const byCode=new Map(products.map(p=>[p.code,p]));
  const visibleProducts=products.filter(p=>`${p.code} ${p.ean??""} ${p.name}`.toLocaleLowerCase("es").includes(query.toLocaleLowerCase("es")));
  const visibleCodes=new Set(visibleProducts.map(p=>p.code));
  const detail=()=>[
    `Pedido ${brandName} · Danbel`,
    `Cliente: ${customerCode??"Pendiente de identificar"}`,
    `Fecha: ${new Date().toLocaleDateString("es-ES")}`,
    `Descuento de familia: ${discount}%`,
    "",
    ...lines.map(line=>`${line.code} | EAN ${line.ean??"sin EAN"} | ${line.name} | ${line.quantity} ud. × ${euro(line.orderPrice??0)} = ${euro(line.subtotal)}`),
    "",
    `TOTAL: ${euro(total)}`,
    "IVA y transporte según las condiciones comerciales acordadas.",
    notes.trim()?`Observaciones: ${notes.trim()}`:""
  ].filter(Boolean).join("\n");
  const copy=async()=>{
    try { await navigator.clipboard.writeText(detail());setCopied(true);setTimeout(()=>setCopied(false),2500); }
    catch { setCopied(false); }
  };
  const prepareEmail=()=>{
    const subject=`Pedido ${brandName} · Cliente ${customerCode??"sin código"}`;
    // The mail client composes the message; the visitor selects the recipient and confirms sending.
    window.location.href=`mailto:?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(detail())}`;
  };
  return <section className="inline-order" aria-label={`Pedido ${brandName}`}>
    <div className="inline-order-head"><div>{logoSrc?<img className="sergio-logo" src={logoSrc} alt={brandName}/>:null}<span className="sheet-eyebrow">{priceListLabel}</span><strong>Pedido por líneas</strong><p>{versionLabel} · Indica las unidades en cada línea. Tu descuento es del {discount}%.</p></div><span>{lines.length} productos · {euro(total)}</span></div>
    <div className="sergio-sections">{sections.map(section=>{
      const sectionProducts=section.codes.filter(code=>visibleCodes.has(code)).map(code=>byCode.get(code)).filter((p):p is Product=>!!p);
      if(query&&!sectionProducts.length)return null;
      return <div className="sergio-section" key={section.name}>
        <div className="sergio-section-heading"><h3>{section.name}</h3><span>{sectionProducts.length} referencias</span></div>
        <div className="sergio-section-content">
          {section.images.length?<div className="sergio-section-photos">{section.images.map(src=><a key={src} href={src} target="_blank" rel="noopener noreferrer" aria-label={`Ampliar fotografía de ${section.name}`}><img src={src} alt={`Productos ${brandName} de la línea ${section.name}`} loading="lazy"/></a>)}</div>:null}
          {sectionProducts.length?<div className="order-table-scroll"><table className="order-table"><thead><tr><th scope="col">EAN / Ref.</th><th scope="col">Descripción</th><th scope="col">PVP</th><th scope="col">Precio</th><th scope="col">Unidades</th><th scope="col">Total</th></tr></thead>
            <tbody>{sectionProducts.map(p=><tr key={p.code}><td>{p.ean??"—"}<small>{p.code}</small></td><td><div className="order-product-cell">{p.image?<img src={p.image} alt="" loading="lazy"/>:null}<span>{p.name}</span></div></td><td>{p.pvp===null?"—":euro(p.pvp)}</td><td>{euro(p.orderPrice??0)}</td><td><input type="number" min="0" max="100000" step="1" inputMode="numeric" value={quantities[p.code]??0} onChange={e=>{
              const value=Number(e.target.value);
              setQuantities(current=>({...current,[p.code]:Number.isFinite(value)?Math.min(100000,Math.max(0,Math.floor(value))):0}));
            }} aria-label={`Unidades de ${p.name}`}/></td><td>{euro(Math.round((p.orderPrice??0)*(quantities[p.code]??0)*100)/100)}</td></tr>)}</tbody>
          </table></div>:<p className="sergio-section-empty">Esta línea no tiene referencias activas con precio para pedir.</p>}
        </div>
      </div>;
    })}{query&&!visibleProducts.length?<p className="order-no-results">No hay productos que coincidan con la búsqueda.</p>:null}</div>
    <div className="order-footer"><label htmlFor="order-notes">Observaciones para el pedido</label><textarea id="order-notes" maxLength={1000} value={notes} onChange={e=>setNotes(e.target.value)} placeholder="Entrega, referencia interna u otras indicaciones..."/>
      <div className="order-actions"><div><small>{lines.reduce((sum,line)=>sum+line.quantity,0)} unidades seleccionadas</small><strong>Total {euro(total)}</strong></div><Button type="button" variant="outline" disabled={!lines.length} onClick={copy}>{copied?<Check size={16}/>:<Copy size={16}/>} {copied?"Copiado":"Copiar pedido"}</Button><Button type="button" disabled={!lines.length} onClick={prepareEmail}><Mail size={16}/> Preparar correo</Button></div>
      <p className="mail-note">Se abrirá tu aplicación de correo con el pedido escrito. Añade el destinatario y pulsa Enviar para remitirlo.</p>
    </div>
  </section>;
}
