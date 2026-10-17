"""Convierte las tablas DBF del ERP en los JSON que usa el portal.

Uso:
    python3 scripts/import_erp.py --dir /ruta/a/los/dbf [--out data/erp]

Necesita los ficheros TFAMILIES.DBF, tmatpr.DBF y ttarifcli.DBF (mayúsculas o
minúsculas indiferentes). La salida contiene datos confidenciales (descuentos
por cliente) y está excluida de git.
"""

import argparse
import json
import os
import sys
from pathlib import Path

try:
    from dbfread import DBF
except ImportError:
    sys.exit("Falta la librería dbfread: python3 -m pip install dbfread")


def find_table(folder: Path, name: str) -> Path:
    for entry in folder.iterdir():
        if entry.name.lower() == name.lower():
            return entry
    sys.exit(f"No se encuentra {name} en {folder}")


def read(path: Path):
    return DBF(str(path), encoding="cp1252", ignore_missing_memofile=True, char_decode_errors="replace")


def clean(value) -> str:
    return (value or "").strip()


def money(value):
    return round(float(value), 2) if value else None


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--dir", required=True, type=Path, help="Carpeta con los DBF del ERP")
    parser.add_argument("--out", default=Path(__file__).resolve().parent.parent / "data" / "erp", type=Path)
    parser.add_argument("--public", default=Path(__file__).resolve().parent.parent / "public", type=Path)
    args = parser.parse_args()
    args.out.mkdir(parents=True, exist_ok=True)

    families = sorted(
        ({"code": clean(r["CODI"]), "name": clean(r["DESCRICPIO"])} for r in read(find_table(args.dir, "TFAMILIES.DBF")) if clean(r["CODI"])),
        key=lambda f: f["name"],
    )
    family_codes = {f["code"] for f in families}

    photos = {p.stem: f"/products/{p.name}" for p in (args.public / "products").glob("*") if p.is_file()}
    products = []
    for r in read(find_table(args.dir, "tmatpr.DBF")):
        # Artículo publicable: activo, no obsoleto, en uso, visible y con precio de tarifa.
        if not r["ACT"] or r["OBSOLET"] or r["NOUSAR"] or r["NOVIS"] or not r["PREUTARIFA"]:
            continue
        family = clean(r["FAMILIA"])
        if family not in family_codes:
            continue
        ean = clean(r["BARRES"]) or None
        products.append({
            "code": clean(r["CODI"]),
            "family": family,
            "line": clean(r["SFAMILIA"]) or None,
            "lineName": clean(r["NOMSFAM"]) or None,
            "name": clean(r["DESCWEB"]) or clean(r["DESCRIPCIO"]),
            "ean": ean,
            "pvp": money(r["PVP"]),
            "basePrice": money(r["PREUTARIFA"]),
            "image": photos.get(ean) if ean else None,
        })
    products.sort(key=lambda p: (p["family"], p["lineName"] or "", p["name"]))

    discounts: dict[str, dict[str, float]] = {}
    for r in sorted(read(find_table(args.dir, "ttarifcli.DBF")), key=lambda r: r["NUMERO"] or 0):
        family = clean(r["FAMILIA"])
        # Las filas con MATERIAL son descuentos por artículo; de momento solo se usan los de familia.
        if not family or clean(r["MATERIAL"]) or r["CLIENT"] is None:
            continue
        customer = discounts.setdefault(str(r["CLIENT"]), {})
        dto = float(r["DTO"] or 0)
        if dto > 0:
            customer[family] = dto
        else:
            customer.pop(family, None)
    discounts = {code: fams for code, fams in discounts.items() if fams}

    for name, data in (("families", families), ("products", products), ("discounts", discounts)):
        with open(args.out / f"{name}.json", "w", encoding="utf-8") as fh:
            json.dump(data, fh, ensure_ascii=False, separators=(",", ":"))

    print(f"{len(families)} familias, {len(products)} artículos, {len(discounts)} clientes con descuento -> {args.out}")


if __name__ == "__main__":
    main()
