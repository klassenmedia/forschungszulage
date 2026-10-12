<?php
/**
 * Nimmt das Anfrageformular entgegen und schickt es an Grantonomy.
 *
 * Der Betreff folgt der Vorgabe fürs CRM: "Robingut-Anfrage Forschungszulage – <Firma>".
 * Antwort immer als JSON, damit das Formular ohne Seitenwechsel auskommt.
 */

declare(strict_types=1);

// Fehlertexte gehören ins Log, nicht in die Antwort: sie verraten sonst Serverpfade.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require __DIR__ . '/lib/felder.php';

const EMPFAENGER      = 'marvin@grantonomy.de';
const KOPIE           = 'kontakt@robin-gut.org';
const ABSENDER        = 'noreply@robingut-forschungszulage.de';
const ABSENDER_NAME   = 'ROBIN GUT Forschungszulage';
const ANTWORT_AN      = 'kontakt@robin-gut.org';
const BETREFF_PRAEFIX = 'Robingut-Anfrage Forschungszulage';

/** Nur von dieser Herkunft nimmt der Endpunkt Anfragen an. */
const EIGENE_HERKUNFT = 'https://robingut-forschungszulage.de';

/** Terminplaner von Grantonomy für das Erstgespräch. */
const TERMIN_LINK = 'https://calendar.google.com/calendar/u/0/appointments/schedules/AcZssZ3VfdRT14DDYyXV5iJKN09E75uNUIruROkaxOW14iZODg7oMsqSa4TwBACP_1FFWqNdwE5UZvZa';

/** Mindestzeit zwischen Seitenaufruf und Absenden; darunter war es ein Bot. */
const MIN_SEKUNDEN     = 3;
/** Höchstzahl Anfragen je IP innerhalb des Zeitfensters. */
const MAX_PRO_FENSTER  = 5;
const FENSTER_SEKUNDEN = 3600;
/** Obergrenze für den gesamten Request, bevor PHP ihn überhaupt verarbeitet. */
const MAX_REQUEST_BYTES = 32768;
/** Zweites Kontingent je Mailadresse, gegen Verteilung über viele IPs. */
const MAX_PRO_ADRESSE = 3;
/** Gesamtkontingent versandter Mails am Tag, als Obergrenze für den Worst Case. */
const MAX_PRO_TAG     = 300;
/** Eigenes, weites Kontingent für abgewiesene Bot-Anfragen. */
const MAX_BOT_PRO_TAG = 5000;
const TAGESFENSTER    = 86400;

/** Beendet die Anfrage mit JSON-Antwort. */
function antworte(int $status, string $meldung): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(['ok' => $status === 200, 'meldung' => $meldung], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Verzeichnis für die Zählerdateien, nur für den eigenen Prozess lesbar.
 * Auf Shared Hosting liegt sys_get_temp_dir() sonst offen für Mitbenutzer.
 */
function zaehlerOrdner(): string
{
    $ordner = sys_get_temp_dir() . '/fz_anfragen';
    if (!is_dir($ordner)) {
        @mkdir($ordner, 0700, true);
    }
    return $ordner;
}

/**
 * Serverseitiges Geheimnis für die Pseudonymisierung der Zählerdateien.
 * Liegt außerhalb des Repos, damit aus einem Dateinamen keine IP
 * zurückgerechnet werden kann.
 */
function zaehlerGeheimnis(): string
{
    $datei = zaehlerOrdner() . '/.schluessel';
    $vorhanden = @file_get_contents($datei);
    if (is_string($vorhanden) && strlen($vorhanden) >= 32) {
        return $vorhanden;
    }
    // 'x' schlägt fehl, wenn die Datei schon da ist — dann gilt der fremde Schlüssel.
    $griff = @fopen($datei, 'x');
    if ($griff === false) {
        $fremd = @file_get_contents($datei);
        return is_string($fremd) && strlen($fremd) >= 32 ? $fremd : 'notfall-' . __FILE__;
    }
    $neu = bin2hex(random_bytes(32));
    fwrite($griff, $neu);
    fclose($griff);
    @chmod($datei, 0600);
    return $neu;
}

/**
 * Mengenbegrenzung über eine Datei je Kennung.
 *
 * Das Lock liegt über dem gesamten Lese-Schreib-Zyklus: ohne das lesen
 * parallele Requests denselben Stand und überschreiben sich gegenseitig,
 * womit die Grenze unter Last wirkungslos wäre.
 *
 * Bei Dateifehlern wird gesperrt, nicht durchgelassen: der Worst Case
 * dieses Endpunkts ist ein Mailschwall, nicht eine abgewiesene Anfrage.
 */
function grenzeUeberschritten(string $kennung, int $hoechstzahl, int $fenster): bool
{
    $datei = zaehlerOrdner() . '/z_'
        . hash_hmac('sha256', $kennung, zaehlerGeheimnis()) . '.txt';

    $griff = @fopen($datei, 'c+');
    if ($griff === false) {
        error_log('Forschungszulage: Zählerdatei nicht beschreibbar');
        return true;
    }
    @chmod($datei, 0600);

    try {
        if (!flock($griff, LOCK_EX)) {
            error_log('Forschungszulage: Sperre auf der Zählerdatei fehlgeschlagen');
            return true;
        }

        $jetzt = time();
        $zeitpunkte = [];
        foreach (explode("\n", trim((string) stream_get_contents($griff))) as $zeile) {
            $zeitpunkt = (int) $zeile;
            if ($zeitpunkt > $jetzt - $fenster) {
                $zeitpunkte[] = $zeitpunkt;
            }
        }

        if (count($zeitpunkte) >= $hoechstzahl) {
            return true;
        }

        $zeitpunkte[] = $jetzt;
        ftruncate($griff, 0);
        rewind($griff);
        fwrite($griff, implode("\n", $zeitpunkte));
        fflush($griff);
        return false;
    } finally {
        flock($griff, LOCK_UN);
        fclose($griff);
        raeumeAlteZaehlerAuf();
    }
}

/** Entfernt abgelaufene Zählerdateien, damit sie sich nicht endlos ansammeln. */
function raeumeAlteZaehlerAuf(): void
{
    if (random_int(1, 50) !== 1) {
        return;
    }
    $grenze = time() - TAGESFENSTER;
    foreach (glob(zaehlerOrdner() . '/z_*.txt') ?: [] as $datei) {
        if (@filemtime($datei) < $grenze) {
            @unlink($datei);
        }
    }
}

/** Verschickt eine Textmail über den Mailserver des Hosters. */
function verschicke(string $an, string $betreff, string $text, string $antwortAn, string $kopie = ''): bool
{
    $kopfzeilen = [
        'From: ' . sprintf('=?UTF-8?B?%s?=', base64_encode(ABSENDER_NAME)) . ' <' . ABSENDER . '>',
        'Reply-To: ' . $antwortAn,
    ];
    if ($kopie !== '') {
        $kopfzeilen[] = 'Cc: ' . $kopie;
    }
    $kopfzeilen[] = 'Content-Type: text/plain; charset=UTF-8';
    $kopfzeilen[] = 'Content-Transfer-Encoding: 8bit';
    $kopfzeilen[] = 'MIME-Version: 1.0';
    $kopfzeilen[] = 'Auto-Submitted: auto-generated';

    return mail(
        $an,
        mb_encode_mimeheader($betreff, 'UTF-8', 'B'),
        $text,
        implode("\r\n", $kopfzeilen),
        '-f' . ABSENDER
    );
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    antworte(405, 'Nur POST erlaubt.');
}

// Fremde Seiten dürfen den Endpunkt nicht im Browser unserer Besucher auslösen.
// Ein fehlender Origin zählt nicht als eigener: das fetch() der Seite schickt
// ihn immer mit, ein Skript oder Bot dagegen oft nicht.
if (($_SERVER['HTTP_ORIGIN'] ?? '') !== EIGENE_HERKUNFT) {
    antworte(403, 'Ungültige Herkunft.');
}

if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > MAX_REQUEST_BYTES) {
    antworte(413, 'Anfrage zu groß.');
}

// Vor allen anderen Prüfungen zählen, damit auch abgewiesene Bot-Anfragen
// gegen das Kontingent laufen und eine Flut den Server nicht beschäftigt.
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unbekannt');
if (grenzeUeberschritten('ip:' . $ip, MAX_PRO_FENSTER, FENSTER_SEKUNDEN)) {
    antworte(429, 'Zu viele Anfragen. Bitte versuchen Sie es später noch einmal.');
}
// Ein IPv6-Präfix liefert praktisch unbegrenzt Adressen. Das weite Bot-Kontingent
// deckelt die Last, ohne dass ein Angreifer damit echte Kunden aussperren kann:
// das knappe Tageslimit für Mails zählt erst beim tatsächlichen Versand.
if (grenzeUeberschritten('tag_abgewiesen', MAX_BOT_PRO_TAG, TAGESFENSTER)) {
    error_log('Forschungszulage: Bot-Tageskontingent erreicht');
    antworte(429, 'Zu viele Anfragen. Bitte versuchen Sie es später noch einmal.');
}

// Honeypot: ein für Menschen unsichtbares Feld, das Bots gern ausfüllen.
// Auf den Rohwert prüfen — gesäuberter Whitespace würde die Falle entschärfen.
if (($_POST['website'] ?? '') !== '') {
    antworte(200, 'Danke, Ihre Anfrage ist eingegangen.');
}

// Fehlender oder unplausibler Zeitstempel gilt als Bot, nicht als Freifahrtschein.
if (!zeitstempelPlausibel($_POST['zeitstempel'] ?? null, MIN_SEKUNDEN)) {
    antworte(200, 'Danke, Ihre Anfrage ist eingegangen.');
}

[$werte, $fehler] = leseFelder($_POST);
if ($werte === null) {
    antworte(400, (string) $fehler);
}

// Dieselbe Adresse soll nicht über wechselnde IPs geflutet werden können.
if (grenzeUeberschritten('mail:' . mb_strtolower($werte['email']), MAX_PRO_ADRESSE, FENSTER_SEKUNDEN)) {
    antworte(429, 'Zu viele Anfragen. Bitte versuchen Sie es später noch einmal.');
}

// Harte Obergrenze für tatsächlich versandte Mails. Sie zählt bewusst erst hier,
// damit abgewiesene Bot-Anfragen sie nicht aufbrauchen können.
if (grenzeUeberschritten('tag_versandt', MAX_PRO_TAG, TAGESFENSTER)) {
    error_log('Forschungszulage: Tageskontingent für Mails erreicht');
    antworte(429, 'Zu viele Anfragen. Bitte versuchen Sie es später noch einmal.');
}

$firmaFuerBetreff = $werte['firma'] !== '' ? $werte['firma'] : 'Anfrage';

$anGrantonomy = implode("\n", [
    datenblock($werte),
    '',
    '---',
    'Eingegangen: ' . date('d.m.Y H:i') . ' Uhr',
    'Quelle: robingut-forschungszulage.de',
]);

$versandOk = verschicke(
    EMPFAENGER,
    BETREFF_PRAEFIX . ' – ' . $firmaFuerBetreff,
    $anGrantonomy,
    $werte['email'],
    KOPIE
);

if (!$versandOk) {
    error_log('Forschungszulage: Mailversand fehlgeschlagen (' . substr(hash_hmac('sha256', $werte['email'], zaehlerGeheimnis()), 0, 12) . ')');
    antworte(500, 'Der Versand hat nicht geklappt. Bitte schreiben Sie uns an kontakt@robin-gut.org.');
}

// Die Bestätigung geht an eine frei wählbare Adresse. Sie trägt deshalb
// weder den eingegebenen Namen noch den Freitext, damit sie sich nicht als
// signierter Phishing-Träger unter der Stiftungsdomain missbrauchen lässt.
$bestaetigung = implode("\n", [
    'Guten Tag,',
    '',
    'danke für Ihre Anfrage zur Forschungszulage. Sie ist bei uns eingegangen',
    'und liegt jetzt bei Grantonomy, unserem Partner für die Forschungszulage.',
    '',
    'Wenn Sie es beschleunigen möchten: Suchen Sie sich direkt einen Termin',
    'für das Erstgespräch aus.',
    '',
    'Termin aussuchen: ' . TERMIN_LINK,
    '',
    'Das Gespräch dauert etwa 15 Minuten. Danach wissen Sie, ob Ihre Vorhaben',
    'förderfähig sind und um welche Größenordnung es geht. Andernfalls meldet',
    'sich Grantonomy innerhalb von zwei Werktagen bei Ihnen.',
    '',
    'Haben Sie diese Anfrage nicht gestellt? Dann antworten Sie einfach',
    'auf diese Mail, wir gehen der Sache nach.',
    '',
    'Viele Grüße',
    'ROBIN GUT Stiftung gUG',
    '',
    '---',
    'Ein Prozent jeder ausgezahlten Fördersumme fließt in unsere Pflanzflächen',
    'in Deutschland.',
    '',
    'ROBIN GUT Stiftung gUG (haftungsbeschränkt)',
    'Luxemburger Straße 83, 50674 Köln',
    'robingut-forschungszulage.de',
]);

// Die Bestätigung ist Service, kein Muss: ihr Scheitern darf die Anfrage nicht entwerten.
if (!verschicke($werte['email'], 'Ihre Anfrage ist angekommen – so geht es weiter', $bestaetigung, ANTWORT_AN)) {
    error_log('Forschungszulage: Bestätigungsmail fehlgeschlagen (' . substr(hash_hmac('sha256', $werte['email'], zaehlerGeheimnis()), 0, 12) . ')');
}

antworte(200, 'Danke, Ihre Anfrage ist eingegangen.');
