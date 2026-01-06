<?php
// patch_return_history.php
// A one-time script to fix old return records that are missing identifiers.

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/helpers.php';

// Set header to plain text so the browser shows live updates
header('Content-Type: text/plain');
echo "Starting Return History Patch...\n\n";

try {
    $db = Database::getInstance();
    $returnsCollection = $db->returns();
    $borrowsCollection = $db->borrows();

    // Find all return records where 'accession_number' is missing or null
    // AND 'isbn' is also missing or null (this finds the broken records)
    $filter = [
        '$or' => [
            ['accession_number' => ['$exists' => false]],
            ['accession_number' => null]
        ]
    ];
    
    $cursor = $returnsCollection->find($filter);
    $recordsToPatch = iterator_to_array($cursor);
    $patchedCount = 0;
    $failedCount = 0;

    echo "Found " . count($recordsToPatch) . " records that need patching.\n\n";

    foreach ($recordsToPatch as $returnDoc) {
        $returnId = (string)$returnDoc['_id'];
        $borrowId = $returnDoc['borrow_id'] ?? null;

        if (empty($borrowId)) {
            echo "[SKIPPED] Record {$returnId} has no 'borrow_id'. Cannot patch.\n";
            $failedCount++;
            continue;
        }

        // Find the original borrow document using the borrow_id
        $borrowDoc = $borrowsCollection->findOne(['borrow_id' => $borrowId]);

        if (!$borrowDoc) {
            echo "[SKIPPED] Record {$returnId} has 'borrow_id' ({$borrowId}) but original borrow doc was not found.\n";
            $failedCount++;
            continue;
        }

        // Get the correct identifiers from the *original* borrow document
        $correctIsbn = $borrowDoc['isbn'] ?? null;
        $correctAccession = $borrowDoc['accession_number'] ?? null;
        $correctTitle = $borrowDoc['title'] ?? 'Unknown Title';

        // Update the return document with the correct identifiers
        $updateResult = $returnsCollection->updateOne(
            ['_id' => $returnDoc['_id']],
            ['$set' => [
                'isbn' => $correctIsbn,
                'accession_number' => $correctAccession,
                'title' => $correctTitle // Also fix the title just in case
            ]]
        );

        if ($updateResult->getModifiedCount() > 0) {
            echo "[SUCCESS] Patched Record {$returnId} (Borrow ID: {$borrowId}) with ACC: {$correctAccession}, ISBN: {$correctIsbn}\n";
            $patchedCount++;
        } else {
            echo "[INFO] Record {$returnId} already had data. No update needed.\n";
        }
    }

    echo "\n--- PATCH COMPLETE ---";
    echo "\nSuccessfully patched {$patchedCount} records.";
    echo "\nFailed or skipped {$failedCount} records.\n";

} catch (Exception $e) {
    echo "\n\nAN ERROR OCCURRED:\n" . $e->getMessage() . "\n";
}
?>