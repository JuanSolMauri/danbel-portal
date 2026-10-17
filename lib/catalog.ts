import "server-only";
import brands from "../data/brands.json";
import { db } from "./db";

export type Family = { code: string; name: string };
export type ErpProduct = {
  code: string; family: string; line: string | null; lineName: string | null;
  name: string; ean: string | null; pvp: number | null; basePrice: number; image: string | null;
};
export type Product = Omit<ErpProduct, "line"> & { orderPrice: number };
export type Section = { name: string; images: string[]; codes: string[] };
export type BrandView = { code: string; name: string; logo: string | null; discount: number | null; sections: Section[]; products: Product[] };

type Brand = { logo: string | null; sections: Section[] };
const brandInfo = brands as Record<string, Brand>;

type ErpData = { families: Family[]; products: ErpProduct[]; discounts: Record<string, Record<string, number>> };
let cache: { data: ErpData; loadedAt: number } | null = null;
const CACHE_MS = 5 * 60 * 1000;

/** Datos del ERP subidos con scripts/upload-erp.mjs. Se releen como mucho cada 5 minutos. */
async function erp(): Promise<ErpData> {
  if (cache && Date.now() - cache.loadedAt < CACHE_MS) return cache.data;
  const rows = await db()<{ key: keyof ErpData; data: unknown }[]>`select key, data from erp_data`;
  const byKey = Object.fromEntries(rows.map(r => [r.key, r.data])) as Partial<ErpData>;
  if (!byKey.families || !byKey.products || !byKey.discounts) {
    throw new Error("Faltan los datos del ERP en la base de datos. Ejecuta import-erp y upload-erp.");
  }
  cache = { data: byKey as ErpData, loadedAt: Date.now() };
  return cache.data;
}

export async function customerDiscounts(code: string): Promise<Record<string, number>> {
  return (await erp()).discounts[code] ?? {};
}

export async function familyNames(): Promise<Map<string, string>> {
  return new Map((await erp()).families.map(f => [f.code, f.name]));
}

const round2 = (value: number) => Math.round((value + Number.EPSILON) * 100) / 100;

function sectionsFor(code: string, products: Product[], lines: Map<string, string | null>): Section[] {
  const available = new Set(products.map(p => p.code));
  const curated = (brandInfo[code]?.sections ?? [])
    .map(s => ({ ...s, codes: s.codes.filter(c => available.has(c)) }))
    .filter(s => s.codes.length);
  const placed = new Set(curated.flatMap(s => s.codes));
  // Lo que no está en las secciones preparadas se agrupa por la subfamilia del ERP.
  const byLine = new Map<string, string[]>();
  for (const p of products) {
    if (placed.has(p.code)) continue;
    const key = lines.get(p.code) ?? (curated.length ? "Otras referencias" : "Referencias");
    byLine.set(key, [...(byLine.get(key) ?? []), p.code]);
  }
  return [...curated, ...[...byLine].map(([name, codes]) => ({ name, images: [], codes }))];
}

/**
 * Catálogo visible para un cliente: solo las familias en las que tiene descuento,
 * con el precio de pedido = PREUTARIFA menos su % de la familia.
 * Sin cliente (administrador) se muestran todas las familias a precio de tarifa.
 */
export async function catalogFor(customerCode: string | null): Promise<BrandView[]> {
  const data = await erp();
  const discounts = customerCode ? data.discounts[customerCode] ?? {} : null;
  const byFamily = new Map<string, ErpProduct[]>();
  for (const p of data.products) byFamily.set(p.family, [...(byFamily.get(p.family) ?? []), p]);

  return data.families
    .filter(f => byFamily.has(f.code) && (!discounts || (discounts[f.code] ?? 0) > 0))
    .map(f => {
      const discount = discounts ? discounts[f.code] : null;
      const source = byFamily.get(f.code)!;
      const lines = new Map(source.map(p => [p.code, p.lineName]));
      const products = source.map(({ line: _line, ...p }) => ({
        ...p,
        orderPrice: round2(p.basePrice * (1 - (discount ?? 0) / 100)),
      }));
      return {
        code: f.code,
        name: f.name,
        logo: brandInfo[f.code]?.logo ?? null,
        discount,
        sections: sectionsFor(f.code, products, lines),
        products,
      };
    });
}
