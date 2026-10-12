<?php
/** Kleine Prüfhilfe für die Testdateien. */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$GLOBALS['pruef_fehler'] = 0;
$GLOBALS['pruef_anzahl'] = 0;

function pruefe(string $was, mixed $erwartet, mixed $tatsaechlich): void
{
    $GLOBALS['pruef_anzahl']++;
    if ($erwartet === $tatsaechlich) {
        echo "  ok   $was\n";
        return;
    }
    $GLOBALS['pruef_fehler']++;
    echo "  FEHL $was\n";
    echo "       erwartet:    " . var_export($erwartet, true) . "\n";
    echo "       tatsächlich: " . var_export($tatsaechlich, true) . "\n";
}

function berichte(): never
{
    $f = $GLOBALS['pruef_fehler'];
    $n = $GLOBALS['pruef_anzahl'];
    echo "\n" . ($f === 0 ? "ALLE $n PRÜFUNGEN GRÜN\n" : "$f von $n FEHLGESCHLAGEN\n");
    exit($f === 0 ? 0 : 1);
}
