# Forschungszulage – Robin Gut Stiftung gUG × Grantonomy

Landingpage für die steuerliche Forschungszulage nach FZulG.
Statische Seite, live unter https://klassenmedia.github.io/forschungszulage/

## Aufbau

Die `index.html` ist eigenständig: Schriften, Logos und Favicon sind
eingebettet, es werden keine externen Hosts angefragt (DSGVO).
Daneben liegen nur das Hero-Video und dessen Standbild.

| Datei | Zweck |
|---|---|
| `index.html` | die komplette Seite |
| `hero-video-web.mp4` / `.webm` | Hintergrundvideo im Hero |
| `hero-poster.jpg` | Standbild, bis das Video lädt |
| `.htaccess` | Security-Header (greift nur bei Apache-Hosting) |
| `robots.txt`, `sitemap.xml` | Suchmaschinen |

## Bearbeiten

Die Seite wird aus Bausteinen in `build/` zusammengesetzt. Der Zusammenbau
bettet Schriften, Logos, die Kreislauf-Grafik und das FAQ-Schema ein:

```
python3 build.py
```

Änderungen gehören in die Bausteine unter `build/`, nicht in die generierte
`index.html` — die wird bei jedem Lauf überschrieben.

Die Schriftdateien (`fonts/`) und die Base64-Zwischendateien liegen nicht im
Repository. Fehlen sie, liefert `build.py` einen Fehler; sie lassen sich aus
den Google-Fonts-Quellen von Inter (400, 600, 800, Subset latin und latin-ext)
neu erzeugen.

## Offene Punkte vor dem Go-Live

- Impressum und Datenschutz verlinken (stehen aktuell auf `#`)
- Kundenstimmen gegen echte Referenzen von Grantonomy tauschen
- Formularversand anbinden (zeigt bislang nur die Bestätigung)
- Rechtsstand der Angaben: August 2026
