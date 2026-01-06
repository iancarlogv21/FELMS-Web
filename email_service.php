<?php
// email_service.php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/email_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Picqer\Barcode\BarcodeGeneratorPNG; 


function sendBorrowReceipt(string $recipientEmail, array $receiptData): bool {
    $mail = new PHPMailer(true);

    try {

        $generator = new BarcodeGeneratorPNG();
        // ★★★ CHANGE: Reduced X-dim to 1.5 and Height to 40 for smaller barcode
        $barcodeImage = $generator->getBarcode($receiptData['barcode_data'], $generator::TYPE_CODE_128, 1.5, 40);
        
        $mail->isSMTP();
        $mail->Host      = SMTP_HOST;
        $mail->SMTPAuth  = true;
        $mail->Username  = SMTP_USERNAME;
        $mail->Password  = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_ENCRYPTION;
        $mail->Port      = SMTP_PORT;

      
        $mail->setFrom(SENDER_EMAIL, SENDER_NAME);
        $mail->addAddress($recipientEmail);

      
        $mail->addStringEmbeddedImage($barcodeImage, 'barcode', 'barcode.png', 'base64', 'image/png');

      
        $mail->isHTML(true);
        $mail->Subject = 'LMS Borrow Receipt: ' . $receiptData['title'];
        $mail->Body    = generateReceiptHtml($receiptData);
        $mail->AltBody = 'You have borrowed the book "' . $receiptData['title'] . '". Due Date: ' . $receiptData['due_date'];

        $mail->send();
        return true;
    } catch (Exception $e) {
      
        // For debugging, you might want to log this error:
        // error_log("Mailer Error: " . $e->getMessage());
        return false;
    }
}

function generateEmbeddedBarcode(string $data, float $xDim = 1.8, int $height = 60): string 
{
    if (empty($data)) {
        throw new Exception("Barcode data cannot be empty.");
    }
    
    $generator = new BarcodeGeneratorPNG();
    
    $barcodeImage = $generator->getBarcode(
        $data, 
        $generator::TYPE_CODE_128, 
        $xDim, // Horizontal bar width
        $height // Height
    );
    
    return $barcodeImage;
}

function generateReceiptHtml(array $data): string {
    // --- Date Formatting ---
    $borrowDate = new DateTime($data['borrow_date']);
    $formattedBorrowDate = $borrowDate->format('F j, Y, g:i A');
    $dueDate = new DateTime($data['due_date']);
    $formattedDueDate = $dueDate->format('F j, Y');

 
$logoSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin: 0 auto 1rem auto;"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg>';

    return "
    <!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>LMS Borrow Receipt</title>
        <style>
            /* Email-safe CSS styles */
            body { font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, 'Noto Sans', sans-serif; margin: 0; padding: 0; width: 100%; background-color: #f8fafc; }
            .container { max-width: 600px; margin: 20px auto; background-color: #ffffff; border-radius: 0.75rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -2px rgba(0,0,0,0.1); overflow: hidden; border: 1px solid #e2e8f0; }
            .header { background-color: #ef4444; color: #ffffff; text-align: center; padding: 2rem; }
            .header h1 { margin: 0; font-size: 1.75rem; font-weight: 600; letter-spacing: 1px; }
            .header p { margin: 0.5rem 0 0; font-size: 1rem; font-weight: 400; opacity: 0.9; }
            .content { padding: 1.5rem 2rem; }
            .details-table { width: 100%; margin: 1.5rem 0; border-collapse: collapse; }
            .details-table td { padding: 1rem 0; border-bottom: 1px solid #f1f5f9; }
            .details-table td:first-child { font-weight: 600; color: #334155; width: 130px; }
            .due-date-box { background-color: #fff7ed; border: 1px solid #fed7aa; border-radius: 0.5rem; text-align: center; margin: 1.5rem 0; padding: 1.5rem; }
            .footer { border-top: 1px solid #e2e8f0; font-size: 0.75rem; text-align: center; padding: 2rem; color: #64748b; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                {$logoSvg}
                <h1>Transaction Receipt</h1>
                <p>Fast & Efficient LMS</p>
            </div>
            <div class='content'>
                <p style='font-size: 1.125rem; color: #334155;'>Dear <strong>{$data['student_name']}</strong>,</p>
                <p style='color: #64748b;'>This email is your official receipt for the item borrowed. Please find the details below.</p>
                
                <table class='details-table'>
                    <tr><td>Book Title</td><td style='font-weight: 700; color: #334155;'>{$data['title']}</td></tr>
                    <tr><td>Student No</td><td>{$data['student_no']}</td></tr>
                    <tr><td>Borrow Date</td><td>{$formattedBorrowDate}</td></tr>
                </table>

                <div class='due-date-box'>
                    <p style='color: #9a3412;'>Please return by the due date:</p>
                    <p style='font-size: 2.25rem; font-weight: 700; color: #ea580c; margin-top: 0.5rem;'>{$formattedDueDate}</p>
                </div>

                <div class='barcode-section' style='text-align: center; margin-top: 1.5rem;'>
                    <p style='color: #64748b; margin-bottom: 0.5rem;'>Scan this barcode when returning the book:</p>
                    <img src='cid:barcode' alt='Return Barcode' style='display: block; margin: 0 auto; max-width: 90%; height: 40px;'/>
                    <p style='color: #64748b; margin-top: 1rem; margin-bottom: 0.25rem;'>Receipt ID</p>
                    <p style='font-size: 1.125rem; font-weight: 700; color: #334155;'>{$data['receipt_id']}</p>
                </div>
            </div>
            
            <div class='footer'>
                <p><strong>Borrowing Policy:</strong> The borrowing period is for one (1) week. A fine of 10 pesos per day will be charged for overdue books. Maximum of three (3) books at a time.</p>
                <p style='margin-top: 1rem;'>LMS Library &copy; " . date('Y') . "</p>
            </div>
        </div>
    </body>
    </html>";
}
?>