<?php
// email_card.php (API endpoint to send the library card)
session_start();

// Include all necessary files
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/email_config.php'; // For SMTP constants
require_once __DIR__ . '/helpers.php'; // **Required for getStudentPhotoUrl and log_activity**
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/email_service.php'; // **Assumed to contain generateEmbeddedBarcode**

use MongoDB\BSON\ObjectId;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// --- Helper for JSON Response ---
function send_json_error(string $message) {
    // Send a standard JSON error back to the AJAX call
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

// Ensure the request is valid
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    send_json_error('Unauthorized');
}
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    send_json_error('Invalid request method.');
}

$studentId = $_GET['id'] ?? null;
if (!$studentId || !preg_match('/^[a-f\d]{24}$/i', $studentId)) {
    send_json_error('Invalid Student ID provided.');
}

$dbInstance = Database::getInstance();
$studentsCollection = $dbInstance->students();

try {
    // 1. Fetch Student Data
    $student = $studentsCollection->findOne(['_id' => new ObjectId($studentId)]);
    
    if (!$student) {
        send_json_error('Student not found.');
    }
    
    $student = (array) $student;
    $studentEmail = $student['email'] ?? null;
    $studentNo = $student['student_no'] ?? null;

    if (!$studentEmail || !filter_var($studentEmail, FILTER_VALIDATE_EMAIL)) {
        send_json_error('Student has no valid email address to send the card.');
    }
    if (!$studentNo) {
        send_json_error('Student record is missing a student number/barcode data.');
    }
    
    // Prepare Data for Email, INCLUDING photoUrl
    $cardData = [
        'student_no' => $studentNo,
        'student_name' => htmlspecialchars($student['first_name'] ?? '') . ' ' . htmlspecialchars($student['last_name'] ?? ''),
        'program' => htmlspecialchars($student['program'] ?? 'N/A'),
        'year_section' => htmlspecialchars($student['year'] ?? 'N/A') . ' - ' . htmlspecialchars($student['section'] ?? 'N/A'),
        // **NEW**: Fetch and include the student's photo URL
        'photoUrl' => getStudentPhotoUrl($student),
    ];

    // 2. Call the Email Service Function
    // Assumes sendStudentLibraryCard and generateEmbeddedBarcode are available (e.g., in email_service.php)
    if (sendStudentLibraryCard($studentEmail, $cardData)) {
        // Log activity (assuming log_activity is in helpers.php)
        if (function_exists('log_activity')) {
            log_activity('EMAIL_CARD', "Sent Digital Library Card email to: '{$cardData['student_name']}' ({$studentEmail})");
        }

        // Set success message for the next page load (used by student_view.php)
        $_SESSION['success_message'] = "Digital Library Card sent successfully to {$studentEmail}!";
        
        // Send success JSON back to the AJAX call
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        
        exit;
    } else {
        // This path is usually hit if the email fails internally (not just MailerException)
        send_json_error('Email failed to send for an unknown reason.');
    }
    
} catch (Exception $e) {
    // Log error and send generic JSON message back to the client
    error_log("Card Email failed for Student ID {$studentId}: " . $e->getMessage()); 
    send_json_error('An error occurred while trying to send the email: ' . $e->getMessage());
}


/**
 * Sends the student library card email with embedded assets.
 * * NOTE: This function should ideally be placed in email_service.php
 * * @param string $toEmail The recipient's email address.
 * @param array $data Array of card data (must include 'photoUrl').
 * @return bool True on successful send.
 * @throws Exception if mailer fails or required data is missing.
 */
function sendStudentLibraryCard(string $toEmail, array $data): bool {
    // Assumes generateEmbeddedBarcode is defined and accessible
    if (!function_exists('generateEmbeddedBarcode')) {
        throw new Exception("Required function generateEmbeddedBarcode is not defined.");
    }

    $mail = new PHPMailer(true);

    try {
        // Barcode Data is the Student Number
        $barcodeData = $data['student_no'];

        // 1. Generate the Barcode Image Data
        $barcodeImage = generateEmbeddedBarcode($barcodeData, 1.8, 60); 
        
        // --- 2. Embed the Photo ---
        $photoContent = @file_get_contents($data['photoUrl']);
        $photoCid = $data['photoUrl']; // Default to URL if embedding fails

        if ($photoContent !== false) {
            // Embed the photo content with CID (using JPEG/JPG assumed by file_get_contents)
            $mail->addStringEmbeddedImage($photoContent, 'student_photo', 'student_photo.jpg', 'base64', 'image/jpeg');
            $photoCid = 'cid:student_photo'; // The reference used in HTML
        } 
        
        // --- 3. Embed the Barcode Image ---
        $mail->addStringEmbeddedImage($barcodeImage, 'library_barcode', 'library_barcode.png', 'base64', 'image/png');


        // --- 4. SMTP Configuration ---
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_ENCRYPTION; // from config/email_config.php
        $mail->Port       = SMTP_PORT;

        // --- 5. Recipients and Content ---
        $mail->setFrom(SENDER_EMAIL, SENDER_NAME);
        $mail->addAddress($toEmail, $data['student_name']);

        $mail->isHTML(true);
        $mail->Subject = 'Your Digital Library Card: ' . $data['student_no'];
        // Pass the photo CID/URL to the HTML generator
        $mail->Body    = generateLibraryCardHtml($data, $photoCid); 
        $mail->AltBody = "Your Digital Library Card Details:\nName: {$data['student_name']}\nStudent No: {$data['student_no']}\nProgram: {$data['program']}";

        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Library Card Mailer Error for {$toEmail}: " . $mail->ErrorInfo);
        throw $e; // Re-throw to be caught by the main try/catch block
    }
}

/**
 * Generates the modern HTML content for the student library card email, focusing on the card UI.
 *
 * @param array $data Array of card data (must include: student_name, student_no, program, year_section).
 * @param string $photoCid The CID (Content ID) or fallback URL for the student's photo.
 * @return string The complete HTML email body.
 */
function generateLibraryCardHtml(array $data, string $photoCid): string {
    // Colors for the new scheme: White, Red, and Dark Text
    $primaryColor = '#E3342F'; // Red for the header/accent
    $textColor = '#1F2937'; // Dark text
    $cardBackgroundColor = '#ffffff'; // Enforced white background for card body and barcode section

    // Logic to attempt stripping acronyms like (BSTM), assuming they are enclosed in parentheses.
    // This provides a cleaner look on the card, as requested.
    $cleanedProgram = preg_replace('/\s+\([^)]+\)$/', '', $data['program']);

    return "
    <!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Digital Library Card</title>
        <style>
            /* Reset: Enforce White Background for Dark Mode Compatibility */
            body { 
                font-family: sans-serif; 
                background-color: #ffffff; /* Use white for email background */
                margin: 0; 
                padding: 0; 
            }

            /* Card container - the core UI element */
            .card-container { 
                max-width: 320px;
                margin: 40px auto; 
                padding: 0; 
                background-color: {$cardBackgroundColor}; /* Card is explicitly white */
                border-radius: 12px; 
                box-shadow: 0 5px 15px rgba(0,0,0,0.1); /* Reduced shadow for cleaner look */
                overflow: hidden; 
                border: 1px solid #e5e7eb;
            }

            /* Header Section */
            .card-header { 
                background: {$primaryColor}; /* Solid red color */
                padding: 15px 20px; 
                color: white; 
                position: relative;
                overflow: hidden;
                text-align: center;
            }
            /* Remove abstract background pattern as it might conflict with solid color request */
            /* .card-header::before rules have been removed */
            .header-text { 
                margin: 0; 
                font-size: 1rem; 
                font-weight: bold; 
                line-height: 1.2;
            }
            
            /* Profile Info Section */
            .profile-section { 
                padding: 20px; 
                text-align: center; 
                background-color: {$cardBackgroundColor}; /* Ensure white */
            }
            .profile-photo { 
                width: 70px; 
                height: 70px; 
                border-radius: 50%; 
                object-fit: cover; 
                border: 2px solid white; 
                box-shadow: 0 0 0 2px #eeeeee; /* Light gray ring for separation */
                margin-bottom: 10px; 
            }
            .student-name { font-size: 1.25rem; font-weight: bold; color: {$textColor}; margin: 0 0 4px 0; line-height: 1.2; }
            .program-info { font-size: 0.9rem; color: #4B5563; margin: 0; line-height: 1.2; }
            
            /* Barcode Section - ENFORCED WHITE BACKGROUND FOR BARCODE VISIBILITY */
            .barcode-section { 
                text-align: center; 
                padding: 15px 20px 20px; 
                background-color: {$cardBackgroundColor}; /* ENSURE WHITE FOR BARCODE SCANNING */
                border-top: 1px solid #E5E7EB; 
            }
            .barcode-text { font-size: 1.1rem; font-family: monospace; font-weight: bold; color: {$textColor}; margin: 5px 0 0 0; }
            .barcode-image { display: block; margin: 0 auto; max-width: 90%; height: 60px; }
            
        </style>
    </head>
    <body>
        <div class='card-container'>
            <div class='card-header'>
                <p class='header-text'>Fast & Efficient LMS</p>
                <div style='font-size: 0.85rem; opacity: 0.8; margin-top: 3px;'>Student Library Card</div>
            </div>
            
            <div class='profile-section'>
                <img src='{$photoCid}' alt='Student Photo' class='profile-photo'>
                <p class='student-name'>{$data['student_name']}</p>
                <p class='program-info'>{$cleanedProgram}</p>
            </div>
            
            <div class='barcode-section'>
                <img src='cid:library_barcode' alt='Student Number Barcode' class='barcode-image'/>
                <p class='barcode-text'>{$data['student_no']}</p>
            </div>

            <div style='padding: 10px 20px; font-size: 0.75rem; text-align: center; color: #9CA3AF; border-top: 1px solid #E5E7EB;'>
                <p style='margin: 0;'>System Generated Card. Do not reply to this email.</p>
            </div>
        </div>
    </body>
    </html>";
}