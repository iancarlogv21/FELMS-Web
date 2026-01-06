<?php
require_once __DIR__ . '/config/db.php';
echo "<h1>Fixing Book Quantities...</h1>";

try {
    $db = Database::getInstance();
    $booksCollection = $db->books();

    // Find all books where the quantity is a string
    $booksWithWrongType = $booksCollection->find(['quantity' => ['$type' => 'string']]);

    $updateCount = 0;
    foreach ($booksWithWrongType as $book) {
        $id = $book['_id'];
        $stringQuantity = $book['quantity'];
        $intQuantity = (int)$stringQuantity; // Convert string to integer

        // Update the document in the database
        $booksCollection->updateOne(
            ['_id' => $id],
            ['$set' => ['quantity' => $intQuantity]]
        );

        echo "<p>Updated book with ID: {$id}. Changed quantity from '{$stringQuantity}' to {$intQuantity}.</p>";
        $updateCount++;
    }

    if ($updateCount === 0) {
        echo "<h2>✅ All book quantities are already in the correct format. No changes needed.</h2>";
    } else {
        echo "<h2>✅ Finished! Successfully updated {$updateCount} book(s).</h2>";
    }

} catch (Exception $e) {
    echo "<h2 style='color: red;'>❌ An error occurred: " . $e->getMessage() . "</h2>";
}
?>