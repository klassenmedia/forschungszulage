<?php
/**
 * Negativ-Tests gegen den laufenden Endpunkt anfrage.php.
 *
 * Startet einen eigenen PHP-Server, biegt den Mailversand auf eine
 * Protokolldatei um und prüft echte HTTP-Antworten.
 *
 * Aufruf: php tests/test_endpunkt.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/pruefe.php';

const PORT      = 8499;
const BASIS     = __DIR__ . '/../';
const ARBEIT    = '/tmp/fz_endpunkt_test';
const MAILLOG   = ARBEIT . '/mails.log';

/** Baut eine Testumgebung, in der mail() in eine Datei schreibt. */
function bereiteVor(): void
{
    if (is_dir(ARBEIT)) {
        foreach (glob(ARBEIT . '/*') ?: [] as $f) { @unlink($f); }
    } else {
        mkdir(ARBEIT, 0700, true);
    }
    if (!is_dir(ARBEIT . '/lib')) { mkdir(ARBEIT . '/lib', 0700, true); }
    copy(BASIS . 'lib/felder.php', ARBEIT . '/lib/felder.php');

    // Im Namespace greift die lokale mail()-Attrappe statt der echten Funktion.
    $quelle = file_get_contents(BASIS . 'anfrage.php');
    $attrappe = "declare(strict_types=1);\nnamespace Test;\n"
        . "function mail(\$an, \$betreff, \$text, \$kopf = '', \$param = '') {\n"
        . "    file_put_contents('" . MAILLOG . "',\n"
        . "        \"=== AN: \$an\\nBETREFF: \" . mb_decode_mimeheader(\$betreff) . \"\\nKOPF: \$kopf\\nTEXT:\\n\$text\\n\\n\", FILE_APPEND);\n"
        . "    return true;\n}\n";
    file_put_contents(ARBEIT . '/anfrage.php',
        str_replace('declare(strict_types=1);', $attrappe, $quelle));
}

/** Schickt eine Anfrage und liefert Statuscode plus dekodierte Antwort. */
function sende(array $daten, array $kopfzeilen = [], ?string $roh = null): array
{
    $hatOrigin = false;
    foreach ($kopfzeilen as $k) {
        if (stripos($k, 'Origin:') === 0) { $hatOrigin = true; }
    }
    $ohneOrigin = in_array('X-Ohne-Origin: 1', $kopfzeilen, true);
    if (!$hatOrigin && !$ohneOrigin) { $kopfzeilen[] = 'Origin: https://robingut-forschungszulage.de'; }
    $ch = curl_init('http://127.0.0.1:' . PORT . '/anfrage.php');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $roh ?? http_build_query($daten),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $kopfzeilen,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $antwort = curl_exec($ch);
    $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    return [$status, $antwort, json_decode((string) $antwort, true)];
}

function mailsImLog(): int
{
    return is_file(MAILLOG) ? substr_count((string) file_get_contents(MAILLOG), '=== AN: ') : 0;
}

function leereZaehler(): void
{
    foreach (glob(sys_get_temp_dir() . chr(47) . 'fz_anfragen/z_*.txt') ?: [] as $f) { @unlink($f); }
}

function leereLog(): void
{
    @unlink(MAILLOG);
}

function gueltig(array $zusatz = []): array
{
    return array_merge([
        'name'        => 'Max Muster',
        'firma'       => 'Muster GmbH',
        'email'       => 'max@muster.de',
        'zeitstempel' => (string) (time() - 30),
    ], $zusatz);
}

bereiteVor();
// Zähler aus einem vorherigen Lauf würden das Rate-Limit sofort auslösen.
leereZaehler();
$server = proc_open(
    sprintf('php -S 127.0.0.1:%d -t %s', PORT, escapeshellarg(ARBEIT)),
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rohre);
usleep(900000);

try {
    echo "\nMethode und Herkunft\n";
    $ch = curl_init('http://127.0.0.1:' . PORT . '/anfrage.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    curl_exec($ch);
    pruefe('GET wird abgelehnt', 405, (int) curl_getinfo($ch, CURLINFO_HTTP_CODE));


    leereLog();
    [$status, , ] = sende(gueltig(), ['Origin: https://evil.example']);
    pruefe('fremde Herkunft wird abgewiesen', 403, $status);
    pruefe('fremde Herkunft verschickt keine Mail', 0, mailsImLog());

    leereLog();
    [$status, , ] = sende(gueltig(), ['X-Ohne-Origin: 1']);
    pruefe('fehlender Origin wird abgewiesen', 403, $status);
    pruefe('fehlender Origin verschickt keine Mail', 0, mailsImLog());

    leereLog();
    [$status, , ] = sende(gueltig(), ['Origin: null']);
    pruefe('Origin "null" wird abgewiesen', 403, $status);

    leereLog();
    [$status, , ] = sende(gueltig(), ['Origin: https://robingut-forschungszulage.de.evil.example']);
    pruefe('Suffix-Trick wird abgewiesen', 403, $status);

    leereLog();
    [$status, , ] = sende(gueltig(), ['Origin: https://robingut-forschungszulage.de']);
    pruefe('eigene Herkunft wird angenommen', 200, $status);

    echo "\nBotschutz\n";
    leereZaehler();
    leereLog();
    [$status, , $d] = sende(gueltig(['website' => 'http://spam.example']));
    pruefe('Honeypot: Antwort bleibt unauffällig', 200, $status);
    pruefe('Honeypot: keine Mail verschickt', 0, mailsImLog());

    leereLog();
    sende(gueltig(['website' => "  \t "]));
    pruefe('Honeypot greift auch bei Whitespace', 0, mailsImLog());

    leereLog();
    $ohneZeit = gueltig(); unset($ohneZeit['zeitstempel']);
    [$status, , ] = sende($ohneZeit);
    pruefe('fehlender Zeitstempel: Antwort bleibt unauffällig', 200, $status);
    pruefe('fehlender Zeitstempel verschickt keine Mail', 0, mailsImLog());

    leereLog();
    sende(gueltig(['zeitstempel' => (string) (time() + 99999)]));
    pruefe('Zeitstempel aus der Zukunft verschickt keine Mail', 0, mailsImLog());

    leereLog();
    sende(gueltig(['zeitstempel' => (string) time()]));
    pruefe('zu schnell abgeschickt verschickt keine Mail', 0, mailsImLog());

    echo "\nEingabeprüfung\n";
    leereZaehler();
    leereLog();
    [$status, $roh, $d] = sende([], [], 'name[]=a&name[]=b&firma=F&email=a@b.de&zeitstempel=' . (time() - 30));
    pruefe('Array-Input wird abgelehnt', 400, $status);
    pruefe('Array-Input: kein Serverpfad in der Antwort', false, str_contains((string) $roh, '.php'));
    pruefe('Array-Input: Antwort ist gültiges JSON', true, is_array($d));

    [$status, , ] = sende(gueltig(['beschreibung' => str_repeat('𝕏', 5000)]));
    pruefe('Multibyte-Längenumgehung wird abgewiesen (Größengrenze)', 413, $status);
    leereZaehler();
    [$status, , ] = sende(gueltig(['telefon' => str_repeat('𝕏', 32)]));
    pruefe('Byte-Grenze greift bei kurzem Feld', 400, $status);

    leereZaehler();
    [$status, , ] = sende(gueltig(['email' => 'keinemail']));
    pruefe('ungültige Mail wird abgelehnt', 400, $status);

    leereZaehler();
    $leer = gueltig(); $leer['firma'] = '';
    [$status, , ] = sende($leer);
    pruefe('fehlende Firma wird abgelehnt', 400, $status);

    echo "\nPhishing-Schutz der Bestätigungsmail\n";
    leereZaehler();
    leereLog();
    sende(gueltig([
        'name'         => 'GRATIS BITCOIN! Jetzt klicken: http://evil.example/gewinn',
        'firma'        => 'Gewinnbenachrichtigung sicher-bank.de',
        'branche'      => 'Konto wurde gesperrt',
        'email'        => 'opfer@fremde-firma.de',
        'beschreibung' => 'Ihr Konto wurde gesperrt: http://phish.example/login',
    ]));
    $log = (string) @file_get_contents(MAILLOG);
    $bestaetigung = substr($log, (int) strpos($log, 'AN: opfer@fremde-firma.de'));
    // Generisch: kein einziger Feldwert darf in der Mail an die frei
    // wählbare Adresse auftauchen — sonst ist sie ein Phishing-Träger.
    $nutzlasten = [
        'GRATIS BITCOIN', 'evil.example', 'phish.example',
        'Gewinnbenachrichtigung', 'sicher-bank.de', 'Konto wurde gesperrt',
    ];
    foreach ($nutzlasten as $nutzlast) {
        pruefe('Bestätigung ohne "' . $nutzlast . '"',
            false, str_contains($bestaetigung, $nutzlast));
    }
    pruefe('Bestätigung nennt neutrale Anrede',
        true, str_contains($bestaetigung, 'Guten Tag,'));
    pruefe('interne Mail erreicht trotzdem Grantonomy',
        true, str_contains($log, 'AN: marvin@grantonomy.de'));

    echo "\nHeader-Injection über den Endpunkt\n";
    leereZaehler();
    leereLog();
    sende(gueltig(['firma' => "Evil GmbH\r\nBcc: opfer@example.com"]));
    $log = (string) @file_get_contents(MAILLOG);
    pruefe('kein Bcc in irgendeiner Kopfzeile', false,
        (bool) preg_match('/^(To|Cc|Bcc|Reply-To):.*opfer@example\.com/mi', $log));
    pruefe('nur die vorgesehenen Empfänger', 1, substr_count($log, 'AN: marvin@grantonomy.de'));
    pruefe('Betreff trägt den Firmennamen einzeilig',
        true, str_contains($log, 'BETREFF: Robingut-Anfrage Forschungszulage – Evil GmbH Bcc: opfer@example.com'));
    pruefe('kein Zeilenumbruch im Betreff', false, (bool) preg_match('/BETREFF: [^\n]*\r/', $log));

    echo "\nWeitere Eingabewege\n";
    leereZaehler();
    leereLog();
    [$status, , ] = sende([], ['Content-Type: application/json'],
        (string) json_encode(gueltig()));
    pruefe('JSON-Body wird nicht als Formular gelesen', 200, $status);
    pruefe('JSON-Body verschickt keine Mail', 0, mailsImLog());

    leereLog();
    [$status, , ] = sende(gueltig(['firma' => "Evil\xFF\xFEGmbH"]));
    pruefe('kaputtes UTF-8 wird abgelehnt', 400, $status);
    pruefe('kaputtes UTF-8 verschickt keine Mail', 0, mailsImLog());

    echo "\nBotschutz zählt gegen das Kontingent\n";
    leereZaehler();
    leereLog();
    for ($i = 0; $i < 5; $i++) {
        sende(gueltig(['website' => 'http://spam.example']));
    }
    [$status, , ] = sende(gueltig());
    pruefe('Honeypot-Treffer zählen gegen das Rate-Limit', 429, $status);

    echo "\nRate-Limit\n";
    leereZaehler();
    leereLog();
    $durchgelassen = 0;
    for ($i = 0; $i < 8; $i++) {
        [$status, , ] = sende(gueltig(['email' => "t$i@muster.de"]));
        if ($status === 200) { $durchgelassen++; }
    }
    pruefe('höchstens 5 Anfragen je IP und Stunde', 5, $durchgelassen);
} finally {
    if (isset($server) && is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    leereZaehler();
}

berichte();
