<?php
/**
 * Eingabebehandlung für das Anfrageformular.
 *
 * Liegt getrennt vom Endpunkt, damit die Tests dieselben Funktionen einbinden
 * können, die auch im Betrieb laufen.
 */

declare(strict_types=1);

const FELD_LAENGEN = [
    'name'         => 100,
    'firma'        => 120,
    'branche'      => 60,
    'ausgaben'     => 40,
    'email'        => 254,
    'telefon'      => 32,
    'beschreibung' => 5000,
];
const PFLICHTFELDER = ['name', 'firma', 'email'];

/**
 * Entfernt Steuerzeichen und Zeilenumbrüche, die sonst in Mail-Header
 * geraten und dort fremde Empfänger einschleusen könnten.
 */
function saeubereKopfzeile(string $wert): string
{
    return trim(preg_replace('/[\r\n\t\0\x0B]+/', ' ', $wert) ?? '');
}

/** Normalisiert Fließtext: CRLF vereinheitlichen, Nullbytes raus. */
function saeubereText(string $wert): string
{
    $wert = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $wert);
    return trim($wert);
}

/**
 * Liest die Formularfelder aus $quelle, prüft Typ und Länge.
 *
 * @return array{0: array<string,string>|null, 1: string|null} Werte oder Fehlermeldung
 */
function leseFelder(array $quelle): array
{
    $werte = [];
    foreach (FELD_LAENGEN as $feld => $maxLaenge) {
        $roh = $quelle[$feld] ?? '';

        // Arrays erzeugen sonst eine PHP-Warnung samt Serverpfad in der Antwort.
        if (!is_string($roh)) {
            return [null, 'Ungültige Eingabe.'];
        }
        // Kaputtes UTF-8 bricht json_encode und widerspricht dem Charset der Mail.
        if (!mb_check_encoding($roh, 'UTF-8')) {
            return [null, 'Ungültige Eingabe.'];
        }
        // Zeichen- und Byte-Grenze: 5000 Vier-Byte-Zeichen wären sonst 20 KB.
        if (mb_strlen($roh) > $maxLaenge || strlen($roh) > $maxLaenge * 2) {
            return [null, 'Eine Eingabe ist zu lang.'];
        }

        $werte[$feld] = $feld === 'beschreibung' ? saeubereText($roh) : saeubereKopfzeile($roh);
    }

    foreach (PFLICHTFELDER as $feld) {
        if ($werte[$feld] === '') {
            return [null, 'Bitte füllen Sie Name, Unternehmen und E-Mail-Adresse aus.'];
        }
    }

    if (!filter_var($werte['email'], FILTER_VALIDATE_EMAIL)) {
        return [null, 'Bitte prüfen Sie Ihre E-Mail-Adresse.'];
    }

    return [$werte, null];
}

/**
 * Prüft, ob der Zeitstempel aus dem Formular plausibel ist.
 *
 * Fehlt er oder liegt er in der Zukunft, war es kein Mensch, der das
 * Formular geöffnet hat.
 */
function zeitstempelPlausibel(mixed $roh, int $mindestSekunden, int $hoechstSekunden = 86400): bool
{
    if (!is_string($roh) && !is_int($roh)) {
        return false;
    }
    $zeitpunkt = (int) $roh;
    if ($zeitpunkt <= 0) {
        return false;
    }
    $alter = time() - $zeitpunkt;
    return $alter >= $mindestSekunden && $alter <= $hoechstSekunden;
}

/** Die eingegangenen Angaben als Textblock für die interne Mail. */
function datenblock(array $werte): string
{
    return implode("\n", [
        'Firma: '           . $werte['firma'],
        'Ansprechpartner: ' . $werte['name'],
        'E-Mail: '          . $werte['email'],
        'Telefon: '         . ($werte['telefon']  !== '' ? $werte['telefon']  : '—'),
        'Branche: '         . ($werte['branche']  !== '' ? $werte['branche']  : '—'),
        'FuE-Ausgaben pro Jahr: ' . ($werte['ausgaben'] !== '' ? $werte['ausgaben'] : '—'),
        '',
        'Projektbeschreibung:',
        $werte['beschreibung'] !== '' ? $werte['beschreibung'] : '—',
    ]);
}
