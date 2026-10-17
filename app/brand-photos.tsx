type Product = {code:string;name:string;ean?:string|null;pvp:number|null;orderPrice?:number;image?:string|null};
type Section = {name:string;images:string[];codes:string[]};

const euro = (value:number) => new Intl.NumberFormat("es-ES",{style:"currency",currency:"EUR"}).format(value);

export default function BrandPhotos({products,sections,query}:{products:Product[];sections:Section[];query:string}) {
  const byCode = new Map(products.map(product=>[product.code,product]));
  const search=query.toLocaleLowerCase("es");
  const visible=products.filter(product=>`${product.code} ${product.ean??""} ${product.name}`.toLocaleLowerCase("es").includes(search));
  const allowed=new Set(visible.map(product=>product.code));
  const groups=sections.map(section=>{
    const items=section.codes.filter(code=>allowed.has(code)).map(code=>byCode.get(code)).filter((product):product is Product=>!!product);
    const photos=section.images.filter(src=>!items.some(product=>product.image===src) && section.codes.length>0 && (!search || items.length>0));
    return {name:section.name,items:items.filter(product=>product.image),photos};
  }).filter(group=>group.items.length||group.photos.length);

  return <section className="brand-photo-page" aria-label="Fotos de productos">
    <p className="photo-count">{groups.reduce((sum,group)=>sum+group.items.length+group.photos.length,0)} fotos disponibles</p>
    {groups.length?groups.map(group=><div className="photo-group" key={group.name}>
      <h3>{group.name}</h3>
      <div className="photo-gallery">
        {group.photos.map(src=><article className="photo-tile" key={src}><a href={src} target="_blank" rel="noopener noreferrer"><img src={src} alt={`Colección ${group.name}`} loading="lazy"/></a><div className="photo-tile-info"><strong>{group.name}</strong><small>Foto de la colección</small></div></article>)}
        {group.items.map(product=><article className="photo-tile" key={product.code}><a href={product.image!} target="_blank" rel="noopener noreferrer"><img src={product.image!} alt={product.name} loading="lazy"/></a><div className="photo-tile-info"><strong>{product.name}</strong><small>Ref. {product.code}{product.ean?` · EAN ${product.ean}`:""}</small>{product.orderPrice!==undefined?<span>Tu precio {euro(product.orderPrice)}</span>:null}</div></article>)}
      </div>
    </div>):<div className="empty">{query?"No hay fotos que coincidan con la búsqueda.":"Todavía no hay fotografías de productos disponibles para esta familia."}</div>}
  </section>;
}
