<?php
/**
 * Prüft die Eingabebehandlung aus lib/felder.php.
 *
 * Aufruf: php tests/test_felder.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../lib/felder.php';
require __DIR__ . '/pruefe.php';

echo "\nHeader-Injection abwehren\n";
pruefe('Zeilenumbruch im Firmennamen wird entfernt',
    'Firma GmbH Bcc: opfer@example.com',
    saeubereKopfzeile("Firma GmbH\r\nBcc: opfer@example.com"));
pruefe('einzelnes \n wird entfernt',
    'Max To: fremd@example.com', saeubereKopfzeile("Max\nTo: fremd@example.com"));
pruefe('Nullbyte wird entfernt', 'Test Firma', saeubereKopfzeile("Test\0Firma"));
pruefe('Tabulator wird zu Leerzeichen', 'A B', saeubereKopfzeile("A\tB"));
pruefe('normaler Name bleibt unverändert',
    'Müller & Söhne GmbH', saeubereKopfzeile('Müller & Söhne GmbH'));

echo "\nZeitstempel\n";
$jetzt = time();
pruefe('fehlender Zeitstempel gilt als Bot', false, zeitstempelPlausibel(null, 3));
pruefe('leerer Zeitstempel gilt als Bot', false, zeitstempelPlausibel('', 3));
pruefe('Null gilt als Bot', false, zeitstempelPlausibel('0', 3));
pruefe('Zukunft gilt als Bot', false, zeitstempelPlausibel((string) ($jetzt + 9999), 3));
pruefe('zu schnell gilt als Bot', false, zeitstempelPlausibel((string) $jetzt, 3));
pruefe('älter als ein Tag gilt als Bot', false, zeitstempelPlausibel((string) ($jetzt - 90000), 3));
pruefe('Array gilt als Bot', false, zeitstempelPlausibel(['x'], 3));
pruefe('normaler Zeitstempel ist gültig', true, zeitstempelPlausibel((string) ($jetzt - 30), 3));

echo "\nFelder lesen\n";
$basis = ['name' => 'Max', 'firma' => 'Muster GmbH', 'email' => 'max@muster.de'];

[$w, $f] = leseFelder($basis);
pruefe('gültige Eingabe wird angenommen', null, $f);
pruefe('Name kommt an', 'Max', $w['name']);

[$w, $f] = leseFelder(['name' => ['a', 'b']] + $basis);
pruefe('Array-Input wird abgelehnt', 'Ungültige Eingabe.', $f);

[$w, $f] = leseFelder(['name' => str_repeat('a', 101)] + $basis);
pruefe('zu langer Name wird abgelehnt', 'Eine Eingabe ist zu lang.', $f);

[$w, $f] = leseFelder(['beschreibung' => str_repeat('𝕏', 5000)] + $basis);
pruefe('5000 Vier-Byte-Zeichen werden abgelehnt (Byte-Grenze)',
    'Eine Eingabe ist zu lang.', $f);

[$w, $f] = leseFelder(['firma' => "Evil\xFF\xFEGmbH"] + $basis);
pruefe('kaputtes UTF-8 wird abgelehnt', 'Ungültige Eingabe.', $f);

[$w, $f] = leseFelder(['firma' => 'Müller & Söhne Präzisionsmaschinenbau GmbH'] + $basis);
pruefe('Umlaute bleiben erlaubt', null, $f);

[$w, $f] = leseFelder(['email' => 'keinemail'] + $basis);
pruefe('ungültige Mail wird abgelehnt', 'Bitte prüfen Sie Ihre E-Mail-Adresse.', $f);

[$w, $f] = leseFelder(['firma' => ''] + $basis);
pruefe('fehlende Firma wird abgelehnt',
    'Bitte füllen Sie Name, Unternehmen und E-Mail-Adresse aus.', $f);

foreach (['a@b.de,c@d.de', 'a@b.de>, evil@c.de', "a@b.de\r\nBcc: x@y.de"] as $boese) {
    [$w, $f] = leseFelder(['email' => $boese] + $basis);
    pruefe('mehrere Empfänger abgelehnt: ' . str_replace(["\r", "\n"], '', $boese),
        'Bitte prüfen Sie Ihre E-Mail-Adresse.', $f);
}

echo "\nDatenblock\n";
[$w, ] = leseFelder(['telefon' => '', 'beschreibung' => "Zeile A\nZeile B"] + $basis);
$block = datenblock($w);
pruefe('Firma erscheint', true, str_contains($block, 'Firma: Muster GmbH'));
pruefe('leeres Telefon wird zu Gedankenstrich', true, str_contains($block, 'Telefon: —'));
pruefe('Beschreibung behält Umbruch', true, str_contains($block, "Zeile A\nZeile B"));

berichte();
