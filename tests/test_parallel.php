<?php
/**
 * Prüft, ob das Rate-Limit auch bei echter Parallelität hält.
 *
 * Der eingebaute PHP-Server serialisiert Anfragen und würde eine Race
 * Condition verdecken — deshalb laufen hier echte Prozesse gleichzeitig
 * gegen dieselbe Zählerdatei.
 *
 * Aufruf: php tests/test_parallel.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/pruefe.php';

const PARALLEL = 30;
const GRENZE   = 5;

/** Übernimmt die Zähllogik aus anfrage.php, ohne den Endpunkt auszuführen. */
$kindSkript = <<<'PHP'
<?php
declare(strict_types=1);
$quelle = file_get_contents($argv[1]);
$teile = [];
foreach ([
    '/const FENSTER_SEKUNDEN = \d+;/',
    '/const MAX_PRO_FENSTER\s+= \d+;/',
    '/const TAGESFENSTER\s+= \d+;/',
    '/function zaehlerOrdner.*?\n}/s',
    '/function zaehlerGeheimnis.*?\n}/s',
    '/function grenzeUeberschritten.*?\n}\n\n(?=\/\*\* Entfernt)/s',
    '/function raeumeAlteZaehlerAuf.*?\n}/s',
] as $muster) {
    if (!preg_match($muster, $quelle, $t)) {
        fwrite(STDERR, "Muster nicht gefunden: $muster\n");
        exit(2);
    }
    $teile[] = $t[0];
}
eval(implode("\n", $teile));
echo grenzeUeberschritten($argv[2], MAX_PRO_FENSTER, FENSTER_SEKUNDEN) ? 'BLOCK' : 'DURCH';
PHP;

$kindDatei = sys_get_temp_dir() . '/fz_parallel_kind.php';
file_put_contents($kindDatei, $kindSkript);
$endpunkt = __DIR__ . '/../anfrage.php';
$testIp = 'ip:203.0.113.' . random_int(1, 254);

function leereZaehler(): void
{
    foreach (glob(sys_get_temp_dir() . '/fz_anfragen/z_*.txt') ?: [] as $f) {
        @unlink($f);
    }
}

leereZaehler();

echo "\nRate-Limit unter Parallellast\n";
echo "  " . PARALLEL . " gleichzeitige Prozesse, Grenze " . GRENZE . "\n";

$prozesse = [];
$rohre = [];
for ($i = 0; $i < PARALLEL; $i++) {
    // Alle starten im selben Moment, damit sie wirklich kollidieren.
    $prozesse[] = proc_open(
        sprintf('php %s %s %s', escapeshellarg($kindDatei),
            escapeshellarg($endpunkt), escapeshellarg($testIp)),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rohre[$i]);
}

$durchgelassen = 0;
$fehler = '';
foreach ($prozesse as $i => $p) {
    $ausgabe = trim((string) stream_get_contents($rohre[$i][1]));
    $meldung = trim((string) stream_get_contents($rohre[$i][2]));
    fclose($rohre[$i][1]);
    fclose($rohre[$i][2]);
    proc_close($p);
    if ($ausgabe === 'DURCH') {
        $durchgelassen++;
    } elseif ($ausgabe !== 'BLOCK' && $meldung !== '') {
        $fehler = $meldung;
    }
}

if ($fehler !== '') {
    echo "  Kindprozess meldet: $fehler\n";
}
echo "  durchgelassen: $durchgelassen\n";

pruefe('kein Kindprozess ist gescheitert', '', $fehler);
pruefe('höchstens ' . GRENZE . ' von ' . PARALLEL . ' parallelen Anfragen passieren',
    true, $durchgelassen <= GRENZE);
pruefe('genau ' . GRENZE . ' Anfragen kommen durch', GRENZE, $durchgelassen);

@unlink($kindDatei);
leereZaehler();

berichte();
