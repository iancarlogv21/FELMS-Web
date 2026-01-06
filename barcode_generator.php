<?php
// barcode_generator.php
// This script dynamically generates and outputs a barcode image.

require_once __DIR__ . '/vendor/autoload.php';

use Picqer\Barcode\BarcodeGeneratorPNG;

$text = $_GET['text'] ?? '';
// $size controls the height. Default to a good height if not specified.
$size = intval($_GET['size'] ?? 70); 

if (empty($text)) {
    header('Content-Type: image/png');
    // Simple 1x1 transparent image as fallback
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
    exit;
}

try {
    // Using Code 128 as it is modern and efficient
    $generator = new BarcodeGeneratorPNG();
    
    // Generate the barcode image data
    // Arguments: Code, Type, Scale (bar width), Height
    $barcodeImage = $generator->getBarcode(
        $text, 
        $generator::TYPE_CODE_128, 
        2, // Scale: 2 pixels wide per bar unit (good balance)
        $size // Height
    );

    // Output the image
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=3600'); // Cache for performance
    echo $barcodeImage;
    
} catch (\Exception $e) {
    error_log("Barcode generation failed for text '{$text}': " . $e->getMessage());
    // Fallback error image
    header('Content-Type: image/png');
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
    exit;
}