<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/helpers.php';

// --- CONFIGURATION ---
$google_api_key = "AIzaSyA8Liid8kD2YPvzqRKI-J1UdM_i_d2yLiU";

// =================================================================
// HELPER FUNCTIONS
// =================================================================

function normalizeIsbn($isbn) {
    $isbn = preg_replace('/[^0-9X]/i', '', $isbn);

    if (strlen($isbn) === 10) {
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int)$isbn[$i] * (10 - $i);
        }
        $checksum = 11 - ($sum % 11);
        if ($checksum == 10) $checksum = 'X';
        if ($checksum == 11) $checksum = '0';
        if (strtoupper($isbn[9]) != $checksum) return false;

        $prefix = '978' . substr($isbn, 0, 9);
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int)$prefix[$i] * (($i % 2 === 0) ? 1 : 3);
        }
        $checksum13 = 10 - ($sum % 10);
        if ($checksum13 == 10) $checksum13 = '0';

        return $prefix . $checksum13;
    } elseif (strlen($isbn) === 13) {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int)$isbn[$i] * (($i % 2 === 0) ? 1 : 3);
        }
        $checksum = 10 - ($sum % 10);
        if ($checksum == 10) $checksum = '0';
        if ($isbn[12] != $checksum) return false;
        
        return $isbn;
    }
    
    return false;
}

function getOpenLibraryCover($isbn) {
    
    return "https://covers.openlibrary.org/b/isbn/{$isbn}-M.jpg?default=false";
}

function fetchFromGoogleBooks($isbn13, $apiKey, $volume = null) {
    $query = "isbn:{$isbn13}";
    if (!empty($volume)) {
        $query .= "+intitle:volume+" . urlencode($volume);
    }

    $apiUrl = "https://www.googleapis.com/books/v1/volumes?q={$query}&key={$apiKey}";
    $response = @file_get_contents($apiUrl);

    if ($response === false) return null;

    $data = json_decode($response, true);
    if (empty($data['items'])) return null;

    $volumeInfo = $data['items'][0]['volumeInfo'];
    return [
        'title' => $volumeInfo['title'] ?? 'Unknown Title',
        'subtitle' => $volumeInfo['subtitle'] ?? '',
        'authors' => $volumeInfo['authors'] ?? [],
        'publisher' => $volumeInfo['publisher'] ?? '',
        'published_date' => $volumeInfo['publishedDate'] ?? '',
        'description' => $volumeInfo['description'] ?? '',
        'isbn' => $isbn13,
        'page_count' => (int)($volumeInfo['pageCount'] ?? 0),
        'genre' => $volumeInfo['categories'][0] ?? 'Uncategorized',
        'language' => $volumeInfo['language'] ?? '',
        'thumbnail' => $volumeInfo['imageLinks']['thumbnail'] ?? ($volumeInfo['imageLinks']['smallThumbnail'] ?? ''),
        'source_api' => 'Google Books'
    ];
}

function fetchFromOpenLibrary($isbn13, $volume = null) {
    $apiUrl = "https://openlibrary.org/api/books?bibkeys=ISBN:{$isbn13}&jscmd=data&format=json";
    $response = @file_get_contents($apiUrl);
    
    if ($response === false) return null;

    $data = json_decode($response, true);
    $bookKey = "ISBN:{$isbn13}";
    if (empty($data) || !isset($data[$bookKey])) return null;

    $bookInfo = $data[$bookKey];
    $authors = array_map(function($author) { return $author['name']; }, $bookInfo['authors'] ?? []);

    return [
        'title' => $bookInfo['title'] ?? 'Unknown Title',
        'subtitle' => $bookInfo['subtitle'] ?? '',
        'authors' => $authors,
        'publisher' => $bookInfo['publishers'][0]['name'] ?? '',
        'published_date' => $bookInfo['publish_date'] ?? '',
        'description' => $bookInfo['notes'] ?? '',
        'isbn' => $isbn13,
        'page_count' => (int)($bookInfo['number_of_pages'] ?? 0),
        'genre' => $bookInfo['subjects'][0]['name'] ?? 'Uncategorized',
        'language' => '',
        'thumbnail' => $bookInfo['cover']['large'] ?? ($bookInfo['cover']['medium'] ?? ''),
        'source_api' => 'Open Library'
    ];
}

// --- MAIN SCRIPT LOGIC ---

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    echo json_encode(['success' => false, 'message' => 'User not logged in.']);
    exit;
}

function send_json_response($success, $message, $data = []) {
    $response = ['success' => $success, 'message' => $message];
    if (!empty($data)) {
        $response = array_merge($response, $data);
    }
    echo json_encode($response);
    exit;
}

try {
    $db = Database::getInstance()->books();
} catch (Exception $e) {
    send_json_response(false, "Database connection error: " . $e->getMessage());
}

$action = $_REQUEST['action'] ?? '';

// =================================================================
// ACTION: Check for Book
// =================================================================
if ($action === 'check_and_fetch_book') {
    $raw_isbn = trim($_GET['isbn'] ?? '');
    $volume = trim($_GET['volume'] ?? null);

    if (empty($raw_isbn)) {
        send_json_response(false, 'ISBN is required.');
    }

    $isbn = normalizeIsbn($raw_isbn);
    if ($isbn === false) {
        send_json_response(false, 'Invalid ISBN format.');
    }

    // --- IMPROVED WATERFALL LOGIC ---
    // 1. Try Google Books first for all data.
    $formattedBook = fetchFromGoogleBooks($isbn, $google_api_key, $volume);

    // 2. If Google Books worked but the thumbnail is empty, try the Open Library Covers API.
    if ($formattedBook && empty($formattedBook['thumbnail'])) {
        $formattedBook['thumbnail'] = getOpenLibraryCover($raw_isbn); // Use raw ISBN for OL Covers
    }

    // 3. If Google Books failed entirely, fall back to the main Open Library API for all data.
    if ($formattedBook === null) {
        $formattedBook = fetchFromOpenLibrary($isbn, $volume);
    }
    // --- END IMPROVED LOGIC ---
    
    if ($formattedBook === null) {
        send_json_response(false, 'No book found for this ISBN from any available source.');
    }

    $existingBook = $db->findOne(['isbn' => $isbn]);
    
    if ($existingBook) {
        $finalBookData = array_merge(
            json_decode(json_encode($existingBook), true),
            $formattedBook
        );

        send_json_response(true, "Book found in library. Details synced from {$formattedBook['source_api']}.", [
            'exists' => true,
            'book' => $finalBookData
        ]);
    } else {
        $formattedBook['quantity'] = 1;
        $formattedBook['status'] = 'On Shelf';
        $formattedBook['location'] = '';
        
        send_json_response(true, "New book details fetched from {$formattedBook['source_api']}.", [
            'exists' => false,
            'book' => $formattedBook
        ]);
    }
}

function generateUniqueAccessionNumber(MongoDB\Collection $db) {
    // Determine the current highest sequence number.
    $prefix = 'ACC-' . date('Y') . '-';
    
    // Find the last book inserted for the CURRENT year's prefix
    $lastBook = $db->findOne(
        // Use the proper regex search for the current year's prefix
        ['accession_number' => ['$regex' => '^' . $prefix] ], 
        ['sort' => ['accession_number' => -1]]
    );

    $lastNumber = 0;
    if ($lastBook && isset($lastBook['accession_number'])) {
        // Extract the numeric part (everything after the last dash)
        $parts = explode('-', $lastBook['accession_number']);
        $lastNumber = (int)end($parts);
    }
    
    $newNumber = $lastNumber + 1;
    return $prefix . str_pad($newNumber, 6, '0', STR_PAD_LEFT);
}

// =================================================================
// ACTION: Add or Manually Add a Book - FIXING CRITICAL DUPLICATION FLAW
// =================================================================
if ($action === 'add' || $action === 'add_manual') {
    $raw_isbn = trim($_POST['isbn'] ?? '');
    $normalized_isbn = normalizeIsbn($raw_isbn);

    $bookIdentifier = [];
    $log_message_part = '';
    
    // 1. --- IDENTIFIER LOGIC: ISBN or Accession Number ---
    if (!empty($normalized_isbn)) {
        // A valid ISBN is present. Use it.
        $existing = $db->findOne(['isbn' => $normalized_isbn]);
        if ($existing) {
            send_json_response(false, 'A book with this ISBN already exists in the library.');
        }

        $bookIdentifier['isbn'] = $normalized_isbn;
        $bookIdentifier['accession_number'] = null; 
        $log_message_part = "(ISBN: {$normalized_isbn})";

    } else {
        // CRITICAL FIX: ISBN is empty/invalid. Use Accession Number as unique ID.
        
        if (empty(trim($_POST['title'] ?? ''))) {
            send_json_response(false, 'Title is required to add a book manually.');
        }
        
        $accessionNumber = generateUniqueAccessionNumber($db); 
        
        $existing = $db->findOne(['accession_number' => $accessionNumber]);
        if ($existing) {
            send_json_response(false, 'Failed to generate a unique Accession Number. Please try again.');
        }

        $bookIdentifier['accession_number'] = $accessionNumber;
        // CRITICAL FIX: The ISBN field MUST be set to NULL to avoid shared identifiers
        $bookIdentifier['isbn'] = null; 
        $log_message_part = "(Accession No: {$accessionNumber})";
    }
    // --- END IDENTIFIER LOGIC ---

    $authors = !empty($_POST['authors']) ? array_map('trim', explode(',', $_POST['authors'])) : [];

    $newBook = array_merge($bookIdentifier, [
      
        
        'title' => $_POST['title'] ?? '',
        'authors' => $authors,
        'description' => $_POST['description'] ?? '',
        'published_date' => $_POST['published_date'] ?? '',
        'publisher' => $_POST['publisher'] ?? '',
        'genre' => $_POST['genre'] ?? 'Uncategorized',
        'language' => $_POST['language'] ?? '',
        'quantity' => (int)($_POST['quantity'] ?? 1),
        'status' => $_POST['status'] ?? 'On Shelf',
        'thumbnail' => $_POST['thumbnail'] ?? ''
    ]);

    $result = $db->insertOne($newBook);

    if ($result->getInsertedCount() > 0) {
        log_activity('CREATE_BOOK', "A new book '{$newBook['title']}' {$log_message_part} was added to the library.");
        send_json_response(true, 'Book successfully added to the library!');
    } else {
        send_json_response(false, 'Failed to add book. Please check database connection.');
    }
}

// =================================================================
// ACTION: Update a Book - CORRECTED COMPARISON AND SANITIZATION LOGIC
// =================================================================
if ($action === 'update') {
    $bookId = $_POST['book_id'] ?? '';
    if (empty($bookId)) {
        send_json_response(false, 'Book ID is missing.');
    }

    try {
        $objectId = new MongoDB\BSON\ObjectId($bookId);
    } catch (Exception $e) {
        send_json_response(false, 'Invalid Book ID format.');
    }
    
    // 1. Fetch the existing book record
    $existingBook = $db->findOne(['_id' => $objectId]);

    if (!$existingBook) {
        send_json_response(false, 'Book not found.');
    }

    // --- PREPARE NEW DATA ---
    // Authors must be handled separately as an array
    $newAuthors = !empty($_POST['authors']) ? array_map('trim', explode(',', $_POST['authors'])) : [];
    
    // Sanitize and prepare scalar fields
    // NOTE: HTML entities are now used only for output; input data is stored cleanly.
    $newData = [
        'title'          => trim($_POST['title'] ?? ''),
        'description'    => trim($_POST['description'] ?? ''),
        'publisher'      => trim($_POST['publisher'] ?? ''),
        'genre'          => trim($_POST['genre'] ?? 'Uncategorized'),
        'status'         => trim($_POST['status'] ?? 'On Shelf'),
        'published_date' => trim($_POST['published_date'] ?? ''),
        'language'       => trim($_POST['language'] ?? ''),
        'thumbnail'      => trim($_POST['thumbnail'] ?? ''),
        'edition'        => trim($_POST['edition'] ?? ''),
        'location'       => trim($_POST['location'] ?? ''),
        // Type casting for numerical fields
        'quantity'       => (int)($_POST['quantity'] ?? 1),
        'page_count'     => (int)($_POST['page_count'] ?? 0),
    ];

    if ($newData['quantity'] === 0) {
        // If the new quantity is 0, force the status to 'Unavailable'.
        $newData['status'] = 'Unavailable';
    }
    

    // --- 2. COMPARE NEW DATA WITH EXISTING DATA ---
    $changesMade = false;
    $updateFields = [];

    // Comparison for Scalar Fields (Strings/Numbers)
    foreach ($newData as $key => $value) {
        // Retrieve existing value, ensure handling of BSON types, nulls, and defaults
        $existingValue = $existingBook[$key] ?? '';
        
        // Handle BSON types (like BSON\Int32) and ensure comparison against native PHP types
        if (is_object($existingValue) && method_exists($existingValue, 'toint')) {
            $existingValue = $existingValue->toint();
        } elseif (is_object($existingValue) && method_exists($existingValue, 'tostring')) {
            $existingValue = $existingValue->tostring();
        }

        // Apply type casting for explicit comparison
        if (in_array($key, ['quantity', 'page_count'])) {
            $existingValue = (int)$existingValue;
            $value = (int)$value;
        } else {
             // Ensure both sides are strings for simple string comparison
             $existingValue = (string)$existingValue;
             $value = (string)$value;
        }

        if ($value !== $existingValue) {
            $changesMade = true;
            $updateFields[$key] = $value;
        }
    }

    // Comparison for 'authors' Array
    // 1. Convert BSON Array/PHP Array to a native PHP array of strings
    $existingAuthors = [];
    if (isset($existingBook['authors'])) {
        $existingAuthors = is_object($existingBook['authors']) && method_exists($existingBook['authors'], 'getArrayCopy') 
                           ? $existingBook['authors']->getArrayCopy() 
                           : (array)$existingBook['authors'];
    }
    
    // 2. Sort both arrays to compare content regardless of order
    sort($newAuthors);
    sort($existingAuthors);

    // 3. Use json_encode for a simple, reliable string-to-string comparison
    if (json_encode($newAuthors) !== json_encode($existingAuthors)) {
        $changesMade = true;
        $updateFields['authors'] = $newAuthors; // Save the new, clean array
    }
    
    // --- 4. EXECUTE UPDATE OR SEND 'NO CHANGES' RESPONSE ---
    if (!$changesMade) {
        send_json_response(true, 'No changes were made to the book.');
    }

    // Only update the fields that actually changed
    $updateData = ['$set' => $updateFields];
    
    $result = $db->updateOne(['_id' => $objectId], $updateData);

    // --- 5. FINAL RESPONSE ---
    if ($result->getModifiedCount() > 0) {
        log_activity('UPDATE_BOOK', "Book details for '{$newData['title']}' were updated.");
        send_json_response(true, 'Book updated successfully!');
    } else {
        // Fallback: If changesMade was true but getModifiedCount was 0, it means the comparison was slightly off,
        // but since we checked for no changes, we can send a success/no change message here too.
        send_json_response(true, 'No changes were made to the book.');
    }
}

// =================================================================
// ACTION: Delete a Book - (Updated Logging for ACC NO.)
// =================================================================
if ($action === 'delete') {
    // Your delete code is already well-written and secure.
    $bookId = $_POST['book_id'] ?? '';
    if (empty($bookId)) {
        send_json_response(false, 'Book ID is missing.');
    }

    try {
        $objectId = new MongoDB\BSON\ObjectId($bookId);
    } catch (Exception $e) {
        send_json_response(false, 'Invalid Book ID format.');
    }

    $bookToDelete = $db->findOne(['_id' => $objectId]);
    if (!$bookToDelete) {
        send_json_response(false, 'Book not found.');
        exit;
    }
    
    // Determine the primary identifier for logging
    $bookTitle = $bookToDelete['title'];
    // Use ISBN if available, otherwise use accession_number
    $bookIdentifier = $bookToDelete['isbn'] ?? $bookToDelete['accession_number'] ?? 'N/A';
    $identifierType = (isset($bookToDelete['isbn']) && !empty($bookToDelete['isbn'])) ? 'ISBN' : 'Accession Number';

    $result = $db->deleteOne(['_id' => $objectId]);

    if ($result->getDeletedCount() > 0) {
        log_activity('DELETE_BOOK', "Book '{$bookTitle}' ({$identifierType}: {$bookIdentifier}) was deleted from the library.");
        // Clean and professional success message
        send_json_response(true, 'Book successfully deleted!');
    } else {
        // Clean error message
        send_json_response(false, 'Failed to delete book. The record may no longer exist.');
    }
}