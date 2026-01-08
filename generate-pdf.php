<?php

require_once __DIR__ . '/vendor/autoload.php';

use NfsePdf\NfsePdfGenerator;

try {
    $xmlFile = __DIR__ . '/nfe-15.xml';
    $outputFile = __DIR__ . '/nfe-15.pdf';

    if (!file_exists($xmlFile)) {
        throw new Exception("XML file not found: {$xmlFile}");
    }

    $generator = new NfsePdfGenerator();
    $generator->parseXml($xmlFile);
    $pdf = $generator->generate();

    $pdf->Output($outputFile, 'F');
    $pdf->Output($outputFile, 'I');

    echo "PDF generated successfully: {$outputFile}\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

