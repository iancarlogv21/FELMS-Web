<?php
// A command-line script to pre-fetch all missing book thumbnails.

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/helpers.php';

echo "Starting thumbnail caching script...\n";

try {
    $dbInstance = Database::getInstance();
    $booksCollection = $dbInstance->books();

    // Find all books where the thumbnail_url either doesn't exist or is empty.
    $booksToCache = $booksCollection->find([
        '$or' => [
            ['thumbnail_url' => ['$exists' => false]],
            ['thumbnail_url' => '']
        ]
    ]);

    $count = 0;
    foreach ($booksToCache as $book) {
        echo "Fetching thumbnail for ISBN: " . ($book['isbn'] ?? 'N/A') . " - " . ($book['title'] ?? 'No Title') . "\n";
        
        // Use the same helper function to fetch and save the URL
        getOrFetchBookThumbnail(
            $book['isbn'] ?? null,
            null, // Force a fetch by passing null as the existing URL
            $booksCollection
        );
        $count++;
        sleep(1); // Be polite to the APIs, wait 1 second between requests.
    }

    echo "----------------------------------------\n";
    echo "Script finished. Cached thumbnails for $count books.\n";

} catch (Exception $e) {
    echo "An error occurred: " . $e->getMessage() . "\n";
}