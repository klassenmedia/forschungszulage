# Kontaktformular mit Mailversand

Stand: 12.10.2026 · Branch `feature/kontaktformular`

## Ziel

Das Anfrageformular auf der Startseite nimmt Anfragen entgegen und schickt sie an
Grantonomy (marvin@grantonomy.de, Kopie kontakt@robin-gut.org). Der Betreff folgt der
CRM-Vorgabe: `Robingut-Anfrage Forschungszulage – <Firma>`. Der Interessent bekommt eine
Bestätigung mit dem Terminbuchungslink von Grantonomy.

## Threat Model

### (a) Angreifer-Szenarien

1. Spam-Bot nutzt den Endpunkt als Massenversender.
2. Angreifer schleust über Formularfelder zusätzliche Empfänger in die Mail-Header.
3. **Angreifer missbraucht die Bestätigungsmail als Phishing-Träger**: Er trägt eine
   fremde Adresse ein und platziert Werbe- oder Phishingtext in Name und Beschreibung.
   Das Opfer erhält eine SPF/DKIM-signierte Mail von der Stiftungsdomain.
4. Angreifer flutet den Endpunkt und erschöpft das Mailkontingent des Hosters.
5. Fremde Website löst im Browser ihrer Besucher Anfragen aus (CSRF), verteilt über
   tausende echte Client-IPs.
6. Angreifer umgeht Honeypot, Zeitsperre oder Rate-Limit einzeln.

### (b) Untrusted Inputs

Alle sieben Formularfelder (name, firma, branche, ausgaben, email, telefon,
beschreibung), das Honeypot-Feld `website`, der Zeitstempel, `REMOTE_ADDR`,
`CONTENT_LENGTH`, `HTTP_ORIGIN`, der Request-Typ aller POST-Werte (String oder Array).

### (c) Worst Case

Die Domain robingut-forschungszulage.de und die Mailreputation von robin-gut.org landen
auf Blocklisten. Das trifft nicht nur diese Seite, sondern den gesamten Mailverkehr der
Stiftung — und ist nicht zurückholbar.

### (d) Gegenmaßnahme je Input

| Input | Maßnahme | Test |
|---|---|---|
| Alle Kopfzeilen-Felder | `\r\n`, Tabs, Nullbytes entfernt vor Header und Betreff | `test_anfrage.php` |
| Alle Felder | `is_string`-Guard, Zeichen- **und** Byte-Grenze | `test_endpunkt.php` |
| email | `FILTER_VALIDATE_EMAIL`, sonst Abbruch | beide Suiten |
| name in der Bestätigung | Keine Verwendung in der Anrede — neutrale Anrede | `test_endpunkt.php` |
| beschreibung in der Bestätigung | Entfällt; der Interessent weiß, was er geschickt hat | `test_endpunkt.php` |
| Empfänger der Bestätigung | Nur die validierte Adresse; die Mail enthält **keinen einzigen Feldwert** | `test_endpunkt.php` (generisch über alle Nutzlasten) |
| zeitstempel | Fehlend, zukünftig oder zu alt gilt als Bot | `test_endpunkt.php` |
| website (Honeypot) | Rohwert geprüft, nicht der gesäuberte | `test_endpunkt.php` |
| REMOTE_ADDR | Nur `REMOTE_ADDR`, nie Proxy-Header; HMAC statt sha1 | `test_endpunkt.php` |
| Rate-Limit | `flock` über den Lese-Schreib-Zyklus; zählt vor dem Botschutz; drei Kontingente (IP, Adresse, Tag); fail-closed | `test_parallel.php` |
| HTTP_ORIGIN | Strikte Gleichheit; fehlender Origin gilt als fremd | `test_endpunkt.php` |
| CONTENT_LENGTH | Obergrenze 32 KB | `test_endpunkt.php` |
| Logausgabe | E-Mail pseudonymisiert statt im Klartext | Code-Review |

## Stand

- [x] Formular aus der Historie zurückgeholt, Versandweg über `anfrage.php`
- [x] Security-Review Runde 1: BLOCKIERT (1 kritisch, 4 hoch, 3 mittel, 3 niedrig)
- [x] Security-Review Runde 2: BLOCKIERT (2 hoch, 3 mittel, 4 niedrig) — Phishing-Vektor war von `name` auf `firma` umgezogen, nicht geschlossen
- [x] Fixes Runde 2 und 71 Tests (28 Unit, 40 Endpunkt, 3 Parallelität)
- [x] Security-Review Runde 3: FREIGABE — alle neun Funde aus Runde 2 geschlossen
- [x] Nachgezogen: Tageskontingent als Abschalthebel entschärft (getrennte Zähler für
      abgewiesene und versandte Anfragen), Schlüssel atomar angelegt, Log-Kennung mit
      Geheimnis statt unsalted sha1, Formular ohne JavaScript meldet keinen falschen Erfolg
- [ ] Upload auf Mittwald (manuell durch Andreas)

## Offen nach dem Deploy

- Postfach `noreply@robingut-forschungszulage.de` muss existieren und versandberechtigt sein.
- `tests/` und `docs/` dürfen nicht auf den Webserver.
- Marvin über den eingebundenen Terminlink informieren.
