<?php

if (!isset($total_notifications_count)) {
    $total_notifications_count = 0;
}


if (isset($dbInstance) && isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    try {
        $borrowCollection = $dbInstance->borrows();
        // Use the current time set to the specific timezone (Asia/Manila)
        $today = new DateTime('now', new DateTimeZone('Asia/Manila')); 
        $twoDaysFromNow = (clone $today)->modify('+2 days')->format('Y-m-d'); 

        // 1. Calculate Due Soon Count (Due today or in next 2 days)
        $proactive_count = $borrowCollection->countDocuments([
            'return_date' => null,
            'due_date' => [
                '$gte' => $today->format('Y-m-d'),
                '$lte' => $twoDaysFromNow
            ]
        ]);

        // 2. Calculate Overdue Count (Due before today)
        $overdue_count = $borrowCollection->countDocuments([
            'return_date' => null,
            'due_date' => ['$lt' => $today->format('Y-m-d')] 
        ]);

        // 3. Calculate Total for the Badge
        $total_notifications_count = $proactive_count + $overdue_count;

    } catch (Exception $e) {
        // Log error but keep $total_notifications_count at 0
        error_log("Notification count error: " . $e->getMessage());
    }
}
?>
