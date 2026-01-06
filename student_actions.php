<?php
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/vendor/autoload.php'; 
require_once __DIR__ . '/helpers.php'; // **REQUIRED for photo logic and log_activity**

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

// --- DANGER: If UPLOAD_DIR is not defined or is wrong, file uploads will fail ---
// CRITICAL: Ensure this path is correct and writable by the web server (e.g., /path/to/project/uploads/)
// >>> FIX: Changed the directory to point to the general 'uploads' folder
define('UPLOAD_DIR', __DIR__ . '/uploads/');

// Redirect if not logged in or not a POST request
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("location: student.php");
    exit;
}

// Ensure the upload directory exists and is writable
if (!is_dir(UPLOAD_DIR)) {
    // Attempt to create the directory recursively
    if (!mkdir(UPLOAD_DIR, 0777, true)) {
        $_SESSION['error_message'] = "CRITICAL ERROR: Failed to create upload directory. Check file system permissions.";
        header("location: student.php");
        exit;
    }
}


try {
    $dbInstance = Database::getInstance();
    $studentsCollection = $dbInstance->students();
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'save':
            handleSave($studentsCollection);
            break;
        case 'update':
            handleUpdate($studentsCollection);
            break;
        case 'delete':
            handleDelete($studentsCollection);
            break;
        default:
            $_SESSION['error_message'] = "Invalid action specified.";
            header("location: student.php");
            exit;
    }
} catch (Exception $e) {
    $_SESSION['error_message'] = "A critical database error occurred: " . $e->getMessage();
    header("location: student.php"); 
    exit;
}


// --- Helper Functions (Defined here for immediate use) ---
if (!function_exists('log_activity')) {
    function log_activity($type, $message) {
        error_log("ACTIVITY LOG: $type - $message");
    }
}

/**
 * Provides a user-friendly error message for PHP file upload failures.
 */
function getUploadErrorMessage($errorCode, $directory): string {
    switch ($errorCode) {
        case UPLOAD_ERR_INI_SIZE:
            return "Photo upload failed: File size exceeds the server's limit (check php.ini upload_max_filesize).";
        case UPLOAD_ERR_FORM_SIZE:
            return "Photo upload failed: File size exceeds the limit specified in the form.";
        case UPLOAD_ERR_PARTIAL:
            return "Photo upload failed: The file was only partially uploaded.";
        case UPLOAD_ERR_NO_FILE:
            return "No file was uploaded.";
        case UPLOAD_ERR_NO_TMP_DIR:
            return "Photo upload failed: Missing a temporary folder on the server.";
        case UPLOAD_ERR_CANT_WRITE:
            return "Photo upload failed: Server denied writing the file. CRITICAL: Check permissions for the directory: " . $directory;
        case UPLOAD_ERR_EXTENSION:
            return "Photo upload failed: A PHP extension stopped the file upload.";
        default:
            return "Photo upload failed: An unknown upload error occurred.";
    }
}


/**
 * Handles image upload and returns the relative path, or null if no file or failure.
 * @param array|null $file The $_FILES['image'] array entry.
 * @param string $studentNo Student number for unique file naming.
 * @return string|null Relative path to the file (e.g., 'uploads/12345.jpg')
 */
function handleImageUpload(?array $file, string $studentNo): ?string {
    
    // --- 1. Check for basic existence ---
    if (!isset($file) || empty($file['name'])) {
        return null; // No file provided
    }
    
    // --- 2. Check for PHP upload error first ---
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['upload_error'] = getUploadErrorMessage($file['error'], UPLOAD_DIR);
        return null;
    }

    $imageFileType = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
    $maxFileSize = 50 * 1024 * 1024; // **50MB limit**
    
    // --- 3. Basic validation (Size and Type) ---
    if ($file["size"] > $maxFileSize) {
        $_SESSION['upload_error'] = "Photo upload failed: File size exceeds " . ($maxFileSize / 1024 / 1024) . "MB.";
        return null;
    }

    if (!in_array($imageFileType, ['jpg', 'png', 'jpeg'])) {
        $_SESSION['upload_error'] = "Photo upload failed: Only JPG, JPEG, and PNG formats are allowed.";
        return null;
    }

    // --- 4. Prepare file path ---
    $safeStudentNo = preg_replace('/[^a-zA-Z0-9]/', '_', $studentNo);
    // Use a simpler filename since all photos are now in one folder
    $newFileName = $safeStudentNo . '-' . uniqid() . '.' . $imageFileType; 
    $targetFile = UPLOAD_DIR . $newFileName;
    
    // --- 5. Attempt to move the file ---
    if (move_uploaded_file($file["tmp_name"], $targetFile)) {
        // Return the path relative to the root for saving in the database
        return 'uploads/' . $newFileName; 
    } else {
        $_SESSION['upload_error'] = "Photo upload failed: Server denied moving the file. CRITICAL: Check permissions for the directory: " . UPLOAD_DIR;
        return null;
    }
}

/**
 * Deletes an image file if the path is valid (relative to the project root).
 * @param string|null $imagePath Relative path from the database (e.g., 'uploads/...')
 */
function deleteImageFile(?string $imagePath): void {
    if ($imagePath) {
        // Construct the absolute path based on the project root (__DIR__ for student_actions.php location)
        // FIX: Ensure __DIR__ is used, as student_actions.php is in the root.
        $absolutePath = __DIR__ . '/' . $imagePath;
        
        if (file_exists($absolutePath)) {
             // Basic safety check before unlinking
            if (strpos($imagePath, 'uploads/') === 0) { 
                unlink($absolutePath);
            }
        }
    }
}


/**
 * Handles creating a new student record.
 * @param \MongoDB\Collection $collection
 */
function handleSave($collection): void {
    $redirect_url = 'student_edit.php'; // Default redirect location

    if (empty($_POST['student_no']) || empty($_POST['first_name']) || empty($_POST['last_name']) || empty($_POST['email']) || empty($_POST['dob'])) {
        $_SESSION['error_message'] = "Please fill in all required fields (Student No, Name, Email, Birthday).";
        header("location: " . $redirect_url);
        exit;
    }
    
    $student_no = trim($_POST['student_no']);
    $existingStudent = $collection->findOne(['student_no' => $student_no]);
    if ($existingStudent) {
        $_SESSION['error_message'] = "Student number '{$student_no}' already exists. Please use a different one.";
        header("location: " . $redirect_url);
        exit;
    }
    
    // Convert DOB string to UTCDateTime
    $dob_string = $_POST['dob'];
    $dob_bson = null;
    if (!empty($dob_string) && strtotime($dob_string) !== false) {
        $dateTime = new DateTime($dob_string, new DateTimeZone('UTC'));
        $dob_bson = new UTCDateTime($dateTime->getTimestamp() * 1000); 
    }

    // --- Photo Upload Handling ---
    $imagePath = handleImageUpload($_FILES['image'] ?? null, $student_no); 
    
    // Check for upload error before saving to DB
    if (isset($_SESSION['upload_error'])) {
        $_SESSION['error_message'] = $_SESSION['upload_error'];
        unset($_SESSION['upload_error']);
        // Crucial: Delete the file if it uploaded successfully but failed validation/error check
        if ($imagePath) deleteImageFile($imagePath); 
        header("location: " . $redirect_url);
        exit;
    }

    $newStudent = [
        'student_no' => $student_no,
        'first_name' => trim($_POST['first_name']),
        'middle_name' => trim($_POST['middle_name']),
        'last_name' => trim($_POST['last_name']),
        'email' => trim($_POST['email']),
        'gender' => trim($_POST['gender'] ?? ''),
        'program' => trim($_POST['program'] ?? ''),
        'year' => (int)($_POST['year'] ?? 0),
        'section' => trim($_POST['section'] ?? ''),
        
        'dob' => $dob_bson,
        'province_code' => trim($_POST['province_code'] ?? ''),
        'city_code' => trim($_POST['city_code'] ?? ''),
        'barangay_code' => trim($_POST['barangay_code'] ?? ''),
        'street_name' => trim($_POST['street_name'] ?? ''),
        
        // **CRITICAL: Ensure these values are being sent from student_edit.php**
        'province_name' => trim($_POST['province_name'] ?? ''),
        'city_name' => trim($_POST['city_name'] ?? ''),
        'barangay_name' => trim($_POST['barangay_name'] ?? ''),
        
        // CRITICAL: Saving the actual photo path under 'image'
        'image' => $imagePath, 
        'created_at' => new MongoDB\BSON\UTCDateTime(),
    ];

    $result = $collection->insertOne($newStudent);
    
    if ($result->getInsertedCount() > 0) {
        $newId = $result->getInsertedId()->__toString();
        $_SESSION['success_message'] = "Student added successfully!";
        log_activity('STUDENT_ADD', "Added new student: '{$newStudent['first_name']} {$newStudent['last_name']}' (Student No: {$newStudent['student_no']})");
        header("location: student_edit.php?id=" . $newId); 
    } else {
        $_SESSION['error_message'] = "Failed to add student to the database.";
        deleteImageFile($imagePath); // Delete the uploaded file on DB failure (Crucial cleanup)
        header("location: " . $redirect_url);
    }
    exit;
}

/**
 * Handles updating a student record.
 * @param \MongoDB\Collection $collection
 */
function handleUpdate($collection): void {
    if (empty($_POST['student_id'])) {
        $_SESSION['error_message'] = "Student ID is missing.";
        header("location: student.php");
        exit;
    }

    $studentId = new MongoDB\BSON\ObjectId($_POST['student_id']);
    $redirect_url = "student_edit.php?id=" . $_POST['student_id'];

    if (empty($_POST['student_no']) || empty($_POST['first_name']) || empty($_POST['last_name']) || empty($_POST['email']) || empty($_POST['dob'])) {
        $_SESSION['error_message'] = "Please fill in all required fields (Student No, Name, Email, Birthday).";
        header("location: " . $redirect_url);
        exit;
    }

    $existingStudent = $collection->findOne(['_id' => $studentId]);
    if (!$existingStudent) {
        $_SESSION['error_message'] = "Student not found.";
        header("location: student.php");
        exit;
    }
    
    $student_no_to_update = trim($_POST['student_no']);
    $duplicateStudent = $collection->findOne([
        'student_no' => $student_no_to_update,
        '_id' => ['$ne' => $studentId]
    ]);

    if ($duplicateStudent) {
        $_SESSION['error_message'] = "Student number '{$student_no_to_update}' is already assigned to another student.";
        header("location: " . $redirect_url);
        exit;
    }
    
    // Convert DOB string to UTCDateTime
    $dob_string = $_POST['dob'];
    $dob_bson = null;
    if (!empty($dob_string) && strtotime($dob_string) !== false) {
        $dateTime = new DateTime($dob_string, new DateTimeZone('UTC'));
        $dob_bson = new UTCDateTime($dateTime->getTimestamp() * 1000); 
    }

    $updateData = [
        'student_no' => $student_no_to_update,
        'first_name' => trim($_POST['first_name']),
        'middle_name' => trim($_POST['middle_name']),
        'last_name' => trim($_POST['last_name']),
        'email' => trim($_POST['email']),
        'gender' => trim($_POST['gender'] ?? ''),
        'program' => trim($_POST['program'] ?? ''),
        'year' => (int)($_POST['year'] ?? 0),
        'section' => trim($_POST['section'] ?? ''),

        'dob' => $dob_bson,
        'province_code' => trim($_POST['province_code'] ?? ''),
        'city_code' => trim($_POST['city_code'] ?? ''),
        'barangay_code' => trim($_POST['barangay_code'] ?? ''),
        'street_name' => trim($_POST['street_name'] ?? ''),
        
        // **CRITICAL: Ensure these values are being sent from student_edit.php**
        'province_name' => trim($_POST['province_name'] ?? ''),
        'city_name' => trim($_POST['city_name'] ?? ''),
        'barangay_name' => trim($_POST['barangay_name'] ?? ''),
    ];

    $existingImagePath = $existingStudent['image'] ?? null;
    $newImagePath = handleImageUpload($_FILES['image'] ?? null, $student_no_to_update);
    
    // Check for upload error before processing update
    if (isset($_SESSION['upload_error'])) {
        $_SESSION['error_message'] = $_SESSION['upload_error'];
        unset($_SESSION['upload_error']);
        // Crucial: Delete the file if it uploaded successfully but failed validation/error check
        if ($newImagePath) deleteImageFile($newImagePath); 
        header("location: " . $redirect_url);
        exit;
    }

    if ($newImagePath) {
        deleteImageFile($existingImagePath); // Delete old photo
        $updateData['image'] = $newImagePath;
    }

    $result = $collection->updateOne(
        ['_id' => $studentId],
        ['$set' => $updateData]
    );

    if ($result->getModifiedCount() > 0) {
        $_SESSION['success_message'] = "Student updated successfully!";
        log_activity('STUDENT_UPDATE', "Updated details for student: '{$updateData['first_name']} {$updateData['last_name']}' (Student No: {$updateData['student_no']})");
    } else if ($newImagePath) {
        $_SESSION['success_message'] = "Student image updated successfully!";
    } else {
        $_SESSION['success_message'] = "No changes were made to the student record.";
    }
    header("location: " . $redirect_url);
    exit;
}

/**
 * Handles deleting a student record.
 * @param \MongoDB\Collection $collection
 */
function handleDelete($collection): void {
    if (empty($_POST['student_id'])) {
        $_SESSION['error_message'] = "Student ID is missing.";
        header("location: student.php");
        exit;
    }

    $studentId = new MongoDB\BSON\ObjectId($_POST['student_id']);
    $studentToDelete = $collection->findOne(['_id' => $studentId]);
    $imagePath = $studentToDelete['image'] ?? null;
    
    $result = $collection->deleteOne(['_id' => $studentId]);

    if ($result->getDeletedCount() > 0) {
        deleteImageFile($imagePath); // Delete photo upon deletion
        $_SESSION['success_message'] = "Student deleted successfully!";
        $fullName = ($studentToDelete['first_name'] ?? '') . ' ' . ($studentToDelete['last_name'] ?? '');
        log_activity('STUDENT_DELETE', "Deleted student: '{$fullName}' (Student No: {$studentToDelete['student_no']})");
    } else {
        $_SESSION['error_message'] = "Failed to delete student.";
    }
    header("location: student.php");
    exit;
}
?>