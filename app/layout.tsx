import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "Danbel | Portal de clientes",
  description: "Catálogo de perfumería para clientes profesionales de Danbel.",
  icons: {
    icon: "/favicon.svg",
    shortcut: "/favicon.svg",
  },
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="es">
      <body className="antialiased">{children}</body>
    </html>
  );
}
