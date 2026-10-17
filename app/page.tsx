import { catalogFor } from "@/lib/catalog";
import { resolveAccess } from "@/lib/access";
import { requireUser } from "@/lib/session";
import Storefront from "./storefront";

export const dynamic = "force-dynamic";

export default async function Home({ searchParams }: { searchParams: Promise<{ cliente?: string }> }) {
  const user = await requireUser("/");
  const { admin, previewCode, customerCode } = resolveAccess(user, (await searchParams).cliente);
  const brands = !admin && !customerCode ? [] : await catalogFor(customerCode);
  return <Storefront brands={brands} admin={admin} previewCode={previewCode} customerCode={customerCode ?? undefined}/>;
}
