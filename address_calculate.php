<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
include 'db.php';

// Function to calculate distance between two coordinates
function calculateDistance($origin, $destination) {
    $earthRadiusKm = 6371;

    $dLat = deg2rad($destination[0] - $origin[0]);
    $dLng = deg2rad($destination[1] - $origin[1]);

    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($origin[0])) * cos(deg2rad($destination[0])) *
         sin($dLng/2) * sin($dLng/2);

    $c = 2 * atan2(sqrt($a), sqrt(1-$a));

    return $earthRadiusKm * $c;
}

// Function to get zones from coordinates
function getZonesFromCoordinates($latitude, $longitude) {
    global $conn;
    $sql = "SELECT id, name, latitude, longitude, radius_km FROM cp_zones";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log("Database prepare failed: " . mysqli_error($conn));
        throw new Exception("Database prepare failed: " . mysqli_error($conn));
    }

    if (!$stmt->execute()) {
        error_log("Database query failed: " . mysqli_error($conn));
        throw new Exception("Database query failed: " . mysqli_error($conn));
    }

    $zones = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    return $zones;
}

// Function to get slots for a specific zone
function getSlotsForZone($zoneId) {
    global $conn;
    $sql = "SELECT day, time FROM cp_slots WHERE zone_id = ?";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log("Database prepare failed for slots: " . mysqli_error($conn));
        throw new Exception("Database prepare failed for slots: " . mysqli_error($conn));
    }

    $stmt->bind_param("i", $zoneId);

    if (!$stmt->execute()) {
        error_log("Database query failed for slots: " . mysqli_error($conn));
        throw new Exception("Database query failed for slots: " . mysqli_error($conn));
    }

    $slots = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    return $slots;
}

// Function to get the next 3 available appointment dates and times
function getNext3AppointmentDates($slots) {
    $next3Days = [];
    $currentDate = new DateTime();
    $currentDayOfWeek = $currentDate->format('N'); // Day of the week (1 = Monday, 7 = Sunday)

    while (count($next3Days) < 3) {
        foreach ($slots as $slot) {
            $slotDayOfWeek = date('N', strtotime($slot['day']));
            $daysUntilSlot = ($slotDayOfWeek - $currentDayOfWeek + 7) % 7;
            $appointmentDate = clone $currentDate;
            $appointmentDate->modify("+$daysUntilSlot days");
            $next3Days[$appointmentDate->format('d-m-Y')][] = $slot['time'];
        }
        $currentDate->modify('+1 week');
    }

    return array_slice($next3Days, 0, 3, true);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['address']) && isset($_POST['latitude']) && isset($_POST['longitude'])) {
    header('Content-Type: text/html; charset=UTF-8');
    $latitude = $_POST['latitude'];
    $longitude = $_POST['longitude'];

    // Debugging: Log the received POST data
    error_log("Received POST data: latitude={$latitude}, longitude={$longitude}");

    try {
        $zones = getZonesFromCoordinates($latitude, $longitude);
        $origin = [$latitude, $longitude];

        // Debugging: Log the origin coordinates
        error_log("Origin coordinates: lat={$latitude}, lng={$longitude}");

        echo "<h2>Coordinate dell'indirizzo: Latitudine={$latitude}, Longitudine={$longitude}</h2>";

        $zonesFound = false;
        foreach ($zones as $zone) {
            $destination = [$zone['latitude'], $zone['longitude']];
            $distance = calculateDistance($origin, $destination);
            $difference = $distance - $zone['radius_km'];

            echo "<h3>Zona: {$zone['name']}</h3>";
            echo "<p>Coordinate della zona: Latitudine={$zone['latitude']}, Longitudine={$zone['longitude']}</p>";
            echo "<p>Distanza: {$distance} km</p>";
            echo "<p>Raggio: {$zone['radius_km']} km</p>";
            echo "<p>Differenza: {$difference} km</p>";

            if ($distance <= $zone['radius_km']) {
                $zonesFound = true;
                $slots = getSlotsForZone($zone['id']);
                if (!empty($slots)) {
                    echo "<h4>Appuntamenti disponibili per i prossimi 3 giorni:</h4>";
                    $next3Days = getNext3AppointmentDates($slots);
                    foreach ($next3Days as $date => $times) {
                        echo "<p>Data: {$date}</p>";
                        echo "<p>Fasce orarie disponibili: ";
                        foreach ($times as $time) {
                            echo "<a href='book_appointment.php?zone_id={$zone['id']}&date={$date}&time={$time}'>{$time}</a> ";
                        }
                        echo "</p>";
                    }
                } else {
                    echo "<p>Nessun appuntamento disponibile per i prossimi 3 giorni.</p>";
                }
            }
        }

        if ($zonesFound) {
            echo "<p>L'indirizzo si trova in una o più zone.</p>";
        } else {
            echo "<p>L'indirizzo non si trova in nessuna zona.</p>";
        }
    } catch (Exception $e) {
        error_log("Exception: " . $e->getMessage());
        echo 'Si è verificato un errore: ' . $e->getMessage();
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Calcolo Indirizzo</title>
</head>
<body>
    <h1>Calcolo Indirizzo</h1>
    <form method="POST" action="address_calculate.php">
        <label for="address">Indirizzo:</label>
        <input type="text" id="address" name="address" required><br><br>

        <label for="latitude">Latitudine:</label>
        <input type="text" id="latitude" name="latitude" required><br><br>

        <label for="longitude">Longitudine:</label>
        <input type="text" id="longitude" name="longitude" required><br><br>

        <button type="submit">Calcola</button>
    </form>

    <div id="result"></div>
</body>
</html>
