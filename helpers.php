<?php
// helpers.php

if (!function_exists('getStudentPhotoUrl')) {
    /**
     * Determines the correct image URL for a student, prioritizing an uploaded file.
     *
     * @param array|object|null $student The student data.
     * @return string The final, safe-to-display image URL.
     */
    function getStudentPhotoUrl($student): string {
        // Convert the input to an array if it's an object.
        if (is_object($student)) {
            $student = (array) $student;
        }

        // 1. Check for a specific, valid uploaded image first.
        $uploadedImagePath = $student['image'] ?? null;
        if ($uploadedImagePath) {
            
            // Extract just the filename (e.g., 'image_123.png')
            $imageFile = basename($uploadedImagePath); 
            
            // **CRITICAL FIX:** Use the ABSOLUTE filesystem path for the check.
            // This path must be correct relative to the root where helpers.php is located.
            $filesystemPath = __DIR__ . '/uploads/' . $imageFile;
            
            if (file_exists($filesystemPath) && is_file($filesystemPath)) {
                // If found, return the web-accessible URL.
                return 'uploads/' . $imageFile; 
            }
        }
        
        // 2. Fallback to default based on gender.
        $gender = strtolower($student['gender'] ?? '');
        if ($gender === 'female') {
            return 'pictures/girl.png'; 
        } elseif ($gender === 'male') {
            return 'pictures/boy.png'; 
        }
        
        // 3. Ultimate fallback.
        return 'https://placehold.co/150x150/e2e8f0/475569?text=Photo';
    }
}

function getBookThumbnailUrl($book): ?string {
    if (!$book) {
        return null;
    }

    // Your book_actions.php saves the URL in 'thumbnail_small'.
    // This function now correctly reads that field.
    return $book['thumbnail_small'] ?? null;
}
function log_activity(string $action, string $details, ?string $userId = null) {
    // --- START FIX: Filter out non-essential logging actions ---
    $noisyActions = [
        'open-activity-log',
        'PAGE_VIEW',         
        'AJAX_REFRESH'       
    ];

    // Convert the action to uppercase for consistent checking
    $action = strtoupper($action);

    // If the action is in our list of noisy actions, we exit and do NOT log it.
    if (in_array($action, $noisyActions)) {
        return;
    }
    // --- END FIX ---

    try {
        $db = Database::getInstance();
        $logsCollection = $db->activity_logs(); 

        $logData = [
            'timestamp' => new MongoDB\BSON\UTCDateTime(),
            'action'    => $action, // Use the uppercased action code
            'details'   => $details, 
            'user_id'   => $userId ?? ($_SESSION['user_id'] ?? null),
            'username'  => $_SESSION['username'] ?? 'System',
        ];

        $logsCollection->insertOne($logData);

    } catch (Exception $e) {
        error_log("Failed to log activity: " . $e->getMessage());
    }
}

function formatMongoDate($mongoDate): string
{
    if (!$mongoDate) {
        return 'N/A';
    }

    try {
        $dateTime = null;
        if ($mongoDate instanceof MongoDB\BSON\UTCDateTime) {
            // It's a BSON date object
            $dateTime = $mongoDate->toDateTime();
        } elseif (is_string($mongoDate)) {
            // It's a string, try to parse it
            $dateTime = new DateTime($mongoDate);
        }

        if ($dateTime) {
            // Set the timezone to your local one
            $dateTime->setTimezone(new DateTimeZone('Asia/Manila'));
            return $dateTime->format('M d, Y, h:i A');
        }
    } catch (Exception $e) {
        // If parsing fails, return N/A
        return 'N/A';
    }

    return 'N/A';
}

/**
 * Formats a student's name into "Last, First Middle" format.
 *
 * @param array|object|null $student The student data from MongoDB.
 * @return string The formatted name.
 */
function formatName($student): string {
    if (!$student) {
        return 'N/A';
    }
    // Access properties as array keys for compatibility
    $lastName = htmlspecialchars($student['last_name'] ?? '');
    $firstName = htmlspecialchars($student['first_name'] ?? '');
    $middleName = htmlspecialchars($student['middle_name'] ?? '');

    return trim("$lastName, $firstName $middleName");
}


 
function formatStatusBadge(?string $status): string {
    $statusText = htmlspecialchars($status ?? 'Unknown');
    // Default color for 'Unknown' status
    $colorClasses = 'bg-slate-400/80 text-white';

    switch (strtolower($statusText)) {
        case 'returned':
            $colorClasses = 'bg-emerald-500 text-white';
            break;
        case 'overdue':
            $colorClasses = 'bg-red-500 text-white';
            break;
        case 'borrowed':
            $colorClasses = 'bg-sky-500 text-white';
            break;
    }

    return "<span class='px-3 py-1 inline-flex text-xs leading-5 font-bold rounded-full {$colorClasses}'>{$statusText}</span>";
}



if (!function_exists('getUserPhotoUrl')) {
    /**
     * Determines the correct image URL for a user (admin/librarian).
     *
     * @param array|object|null $user The user data (e.g., from $_SESSION or database).
     * @return string The final, safe-to-display image URL.
     */
    function getUserPhotoUrl($user): string {
        // Convert the input to an array if it's an object for consistent access
        if (is_object($user)) {
            $user = (array) $user;
        }

        // 1. Check for a specific, valid uploaded image path
        $uploadedImagePath = $user['profile_image'] ?? null; // Assuming 'profile_image' in session/DB
        if ($uploadedImagePath && file_exists($uploadedImagePath)) {
            return $uploadedImagePath; 
        }
        
        // 2. Fallback to UI-Avatars for initials if no image
        $username = $user['username'] ?? 'User';
        $firstName = $user['first_name'] ?? ''; // Assuming you have first_name, last_name in session/DB
        $lastName = $user['last_name'] ?? '';
        
        $nameForAvatar = (!empty($firstName) && !empty($lastName)) 
                         ? urlencode($firstName . ' ' . $lastName) 
                         : urlencode($username);

        // Customize colors if you want
        return "https://ui-avatars.com/api/?name={$nameForAvatar}&background=random&color=fff&size=128";
    }
}


function getOrFetchBookThumbnail(?string $isbn, ?string $existingUrl, \MongoDB\Collection $booksCollection): ?string
{
    // If we already have a URL from our database, use it immediately!
    if (!empty($existingUrl)) {
        return $existingUrl;
    }

    // If there's no ISBN, we can't search.
    if (empty($isbn)) {
        return null;
    }

    // --- If not in DB, call the APIs (logic from your previous function) ---
    $newThumbnailUrl = null;

    // 1. Try Google Books API
    $googleApiUrl = "https://www.googleapis.com/books/v1/volumes?q=isbn:" . urlencode($isbn);
    $googleResponse = @file_get_contents($googleApiUrl);
    if ($googleResponse) {
        $data = json_decode($googleResponse, true);
        if (!empty($data['items'][0]['volumeInfo']['imageLinks']['thumbnail'])) {
            $newThumbnailUrl = str_replace('http://', 'https://', $data['items'][0]['volumeInfo']['imageLinks']['thumbnail']);
        }
    }

    // 2. Fallback to Open Library if Google fails
    if (!$newThumbnailUrl) {
        $openLibraryUrl = "https://covers.openlibrary.org/b/isbn/" . urlencode($isbn) . "-M.jpg";
        $headers = @get_headers($openLibraryUrl);
        if ($headers && strpos($headers[0], '200 OK') !== false) {
            $newThumbnailUrl = $openLibraryUrl;
        }
    }

    // --- IMPORTANT: Save the new URL back to the database ---
    if ($newThumbnailUrl) {
        $booksCollection->updateOne(
            ['isbn' => $isbn],
            ['$set' => ['thumbnail_url' => $newThumbnailUrl]]
        );
    }

    return $newThumbnailUrl;
}



?>