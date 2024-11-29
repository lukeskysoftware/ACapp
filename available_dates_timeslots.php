<?php
include 'db.php';

$zone = isset($_GET['zone']) ? $_GET['zone'] : '';

if ($zone) {
    try {
        $data = getAvailableDatesAndTimeslots($zone);
        echo json_encode($data);
    } catch (Exception $e) {
        error_log('Error fetching dates and timeslots: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Internal Server Error']);
    }
} else {
    echo json_encode([]);
}
?>
