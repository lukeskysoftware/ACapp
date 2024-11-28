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

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['address']) && isset($_POST['latitude']) && isset($_POST['longitude'])) {
    header('Content-Type: text/plain');
    $latitude = $_POST['latitude'];
    $longitude = $_POST['longitude'];

    // Debugging: Log the received POST data
    error_log("Received POST data: latitude={$latitude}, longitude={$longitude}");

    try {
        $zones = getZonesFromCoordinates($latitude, $longitude);
        $origin = [$latitude, $longitude];

        // Debugging: Log the origin coordinates
        error_log("Origin coordinates: lat={$latitude}, lng={$longitude}");

        echo "Address Coordinates: Latitude={$latitude}, Longitude={$longitude}\n\n";

        $zonesFound = false;
        foreach ($zones as $zone) {
            $destination = [$zone['latitude'], $zone['longitude']];
            $distance = calculateDistance($origin, $destination);
            $difference = $distance - $zone['radius_km'];

            echo "Zone: {$zone['name']}\n";
            echo "Zone Coordinates: Latitude={$zone['latitude']}, Longitude={$zone['longitude']}\n";
            echo "Distance: {$distance} km\n";
            echo "Radius: {$zone['radius_km']} km\n";
            echo "Difference: {$difference} km\n\n";

            if ($distance <= $zone['radius_km']) {
                $zonesFound = true;
            }
        }

        if ($zonesFound) {
            echo "The address is within one or more zones.\n";
        } else {
            echo "The address is not within any zones.\n";
        }
    } catch (Exception $e) {
        error_log("Exception: " . $e->getMessage());
        echo 'Error occurred: ' . $e->getMessage();
    }
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Address Calculate</title>
</head>
<body>
    <h1>Address Calculate</h1>
    <form method="POST" action="address_calculate.php">
        <label for="address">Address:</label>
        <input type="text" id="address" name="address" required><br><br>

        <label for="latitude">Latitude:</label>
        <input type="text" id="latitude" name="latitude" required><br><br>

        <label for="longitude">Longitude:</label>
        <input type="text" id="longitude" name="longitude" required><br><br>

        <button type="submit">Calculate</button>
    </form>

    <div id="result"></div>
</body>
</html>
