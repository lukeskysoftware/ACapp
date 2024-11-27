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

    ob_start(); // Start output buffering
    $zones = getZonesFromCoordinates($latitude, $longitude);
    header('Content-Type: application/json');
    echo json_encode(['zones' => $zones]);
    ob_end_flush(); // End output buffering and flush output
} catch (Exception $e) {
    ob_end_clean(); // Clear buffer in case of exception
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
}
?>
