<?php
include 'db.php';

// Silent error handling
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $theta = $lon1 - $lon2;
    $distance = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
    $distance = acos($distance);
    $distance = rad2deg($distance);
    $distance = $distance * 60 * 1.1515 * 1.609344;
    return $distance;
}

function getNextDateForDayOfWeek($dayOfWeek, $startingDate) {
    $nextDate = clone $startingDate;
    $nextDate->modify('next ' . getItalianDayOfWeek($dayOfWeek));
    return $nextDate;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $latitude = $_POST['latitude'];
        $longitude = $_POST['longitude'];

        $zones = getZonesFromCoordinates($latitude, $longitude);

        // Handle empty response
        if (!isset($zones)) {
            $zones = [];
        }

        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['zones' => $zones]);
    } catch (Exception $e) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function getZonesFromCoordinates($latitude, $longitude) {
    global $conn;
    $sql = "SELECT id, zone_name, latitude, longitude, day_of_week, start_time, end_time FROM cp_zones";
    $stmt = $conn->prepare($sql);

    // Handle database execution errors
    if (!$stmt->execute()) {
        throw new Exception("Database query failed: " . $stmt->errorInfo()[2]);
    }

    $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $assigned_zones = [];
    $current_date = new DateTime();

    foreach ($zones as $row) {
        $distance = calculateDistance($latitude, $longitude, $row['latitude'], $row['longitude']);
        if ($distance <= 5) {
            $next_available_times = [];
            for ($i = 1; $i <= 3; $i++) {
                $next_date = getNextDateForDayOfWeek($row['day_of_week'], $current_date);
                $next_available_time = $next_date->format('Y-m-d') . ' ' . $row['start_time'];

                $query_appointments = "SELECT COUNT(*) AS num_appointments FROM appointments WHERE zone_id = '{$row['id']}' AND appointment_date = '$next_available_time'";
                $result_appointments = $conn->query($query_appointments);
                $appointment_count = $result_appointments->fetchColumn();

                if ($appointment_count == 0) {
                    $next_available_times[] = $next_available_time;
                }

                $current_date->add(new DateInterval('P1D'));
            }

            if (!empty($next_available_times)) {
                $assigned_zones[] = [
                    'zone_id' => $row['id'],
                    'zone_name' => $row['zone_name'],
                    'next_available_times' => $next_available_times,
                    'distance' => $distance
                ];
            }
        }
    }

    return $assigned_zones;
}
?>
