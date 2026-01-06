<?php
// This would be inside a file like `return_action.php`

/**
 * Handles returning a book, calculating and saving the final penalty.
 */
function handle_return_book($dbInstance) {
    $borrow_id = $_POST['borrow_id'] ?? null;
    if (!$borrow_id) {
        throw new Exception('Borrow ID is required to return a book.');
    }

    $borrowCollection = $dbInstance->borrows();
    $booksCollection = $dbInstance->books();
    
    $transaction = $borrowCollection->findOne(['borrow_id' => $borrow_id, 'return_date' => null]);
    if (!$transaction) {
        throw new Exception('No active borrow transaction found with that ID.');
    }

    // --- PENALTY CALCULATION ---
    $finalPenalty = 0;
    $penaltyRate = 10; // ₱10 per day
    $today = new DateTime('now', new DateTimeZone('Asia/Manila'));
    $dueDate = new DateTime($transaction['due_date']);

    if ($today > $dueDate) {
        $daysOverdue = $today->diff($dueDate)->days;
        $finalPenalty = $daysOverdue * $penaltyRate;
    }

    // --- UPDATE THE DATABASE ---
    $updateResult = $borrowCollection->updateOne(
        ['_id' => $transaction['_id']],
        ['$set' => [
            'return_date' => date('Y-m-d H:i:s'),
            'penalty' => $finalPenalty
        ]]
    );

    if ($updateResult->getModifiedCount() > 0) {
        // Increment the book's quantity back in stock
        $booksCollection->updateOne(
            ['isbn' => $transaction['isbn']],
            ['$inc' => ['quantity' => 1]]
        );
        send_json_response(['success' => true, 'message' => "Book returned successfully. Final penalty: ₱" . number_format($finalPenalty, 2)]);
    } else {
        throw new Exception('Failed to update the borrow record.');
    }
}

?>