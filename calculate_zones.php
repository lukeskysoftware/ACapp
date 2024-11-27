<?php
include 'db.php';
include 'config.php';
include 'menu.php';

function getZonesFromCoordinates($latitude, $longitude) {
    global $conn;
    $sql = "SELECT * FROM cp_zones WHERE ST_Distance_Sphere(POINT(lon, lat), POINT(:lon, :lat)) <= 5000"; // radius in meters
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':lon', $longitude);
    $stmt->bindParam(':lat', $latitude);

    $stmt->execute();
    $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($zones as &$zone) {
        $zone['slots'] = getSlotsForZone($zone['id']);
    }

    return $zones;
}

function getSlotsForZone($zone_id) {
    global $conn;
    $sql = "SELECT * FROM cp_slots WHERE zone_id = :zone_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':zone_id', $zone_id);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

try {
    if (!isset($_GET['latitude']) || !isset($_GET['longitude'])) {
        throw new Exception('Latitude and Longitude are required.');
    }

    $latitude = $_GET['latitude'];
    $longitude = $_GET['longitude'];

    // Debugging: Log the received coordinates
    error_log("Received coordinates: Latitude=$latitude, Longitude=$longitude");

    $zones = getZonesFromCoordinates($latitude, $longitude);

    // Debugging: Log the zones data
    error_log("Zones data: " . print_r($zones, true));

    header('Content-Type: application/json');
    echo json_encode(['zones' => $zones]);
} catch (Exception $e) {
    // Debugging: Log the exception message
    error_log("Error: " . $e->getMessage());

    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
}
?>
