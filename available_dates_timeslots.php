<?php
include 'db.php';

function getAvailableDatesAndTimeslots($zone) {
    global $conn;
    $datesAndTimeslots = [];
    $sql = "SELECT DISTINCT appointment_date, appointment_time 
            FROM cp_appointments 
            WHERE zone_id = (SELECT id FROM cp_zones WHERE name = '" . mysqli_real_escape_string($conn, $zone) . "') 
            AND appointment_date >= CURDATE() 
            ORDER BY appointment_date, appointment_time ASC";
    $result = mysqli_query($conn, $sql);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $datesAndTimeslots[$row['appointment_date']][] = $row['appointment_time'];
        }
    }
    return $datesAndTimeslots;
}

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
