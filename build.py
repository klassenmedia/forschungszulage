#!/usr/bin/env python3
"""Setzt die Landingpage aus den Bausteinen in build/ zu einer index.html zusammen."""

import datetime
from pathlib import Path

ROOT = Path(__file__).parent
BUILD = ROOT / "build"

PAKETE = ("Baum", "Hain", "Wald")

TEILE = [
    "01_head.html", "02_css.html", "03_css2.html", "04_css3.html",
    "05_body1.html", "06_body2.html", "07_body3.html", "08_body4.html",
    "09_js.html",
]


def main() -> None:
    html = "\n".join((BUILD / t).read_text(encoding="utf-8") for t in TEILE)

    ersetzungen = {
        "__LOGO__": (ROOT / "logo.b64").read_text().strip(),
        "__FAVICON__": (ROOT / "fav.b64").read_text().strip(),
        "__KREISLAUF__": (BUILD / "kreislauf.svg.html").read_text(encoding="utf-8"),
        "__FONTS__": (BUILD / "fonts.css").read_text(encoding="utf-8"),
        "__FAQSCHEMA__": (BUILD / "faq-schema.json").read_text(encoding="utf-8"),
        "__DATEMODIFIED__": datetime.date.today().isoformat(),
        "__PAKET1__": PAKETE[0],
        "__PAKET2__": PAKETE[1],
        "__PAKET3__": PAKETE[2],
    }
    for marke, wert in ersetzungen.items():
        html = html.replace(marke, wert)

    if "__" in html.replace("__", "", 0):
        offen = [z for z in html.split() if z.startswith("__") and z.endswith("__")]
        if offen:
            raise SystemExit(f"Nicht ersetzte Platzhalter: {set(offen)}")

    ziel = ROOT / "index.html"
    ziel.write_text(html, encoding="utf-8")
    print(f"{ziel} geschrieben — {len(html):,} Zeichen".replace(",", "."))


if __name__ == "__main__":
    main()
