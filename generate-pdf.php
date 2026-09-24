<?php

require_once __DIR__ . '/vendor/autoload.php';

use NfsePdf\NfsePdfGenerator;

try {
    $isCli = (PHP_SAPI === 'cli');

    $xmlArg = $isCli ? ($argv[1] ?? null) : ($_GET['xml'] ?? null);
    $xmlFile = $xmlArg ?: (__DIR__ . '/fixtures/nfse.xml');
    if ($xmlFile[0] !== '/') {
        $xmlFile = __DIR__ . '/' . ltrim($xmlFile, '/');
    }

    if (!file_exists($xmlFile)) {
        throw new Exception("XML file not found: {$xmlFile}");
    }

    $generator = (new NfsePdfGenerator())->parseXml($xmlFile);
    $pdf = $generator->generate();
    $basename = pathinfo($xmlFile, PATHINFO_FILENAME) . '.pdf';

    if ($isCli) {
        $outputFile = $argv[2] ?? (__DIR__ . '/' . $basename);
        if ($outputFile[0] !== '/') {
            $outputFile = __DIR__ . '/' . $outputFile;
        }
        $pdf->Output($outputFile, 'F');
        echo "PDF generated successfully: {$outputFile}\n";
    } else {
        // Browser: stream PDF (avoids write permission issues on the project dir)
        $pdf->Output($basename, 'I');
    }
} catch (Exception $e) {
    if (PHP_SAPI === 'cli') {
        echo "Error: " . $e->getMessage() . "\n";
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Error: ' . $e->getMessage();
    }
    exit(1);
}
