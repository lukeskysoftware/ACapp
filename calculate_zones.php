<?php
include 'db.php';
include 'config.php';
include 'menu.php';

$address = $_GET['address'];

function getZonesFromAddress($address) {
    global $conn;
    $sql = "SELECT * FROM cp_zones WHERE ST_Distance_Sphere(POINT(lon, lat), POINT(:lon, :lat)) <= radius_km * 1000";
    $stmt = $conn->prepare($sql);
    $coordinates = getCoordinatesFromAddress($address);
    $lon = $coordinates['lon'];
    $lat = $coordinates['lat'];
    $stmt->bindParam(':lon', $lon);
    $stmt->bindParam(':lat', $lat);

    $stmt->execute();
    $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($zones as &$zone) {
        $zone['slots'] = getSlotsForZone($zone['id']);
    }

    return $zones;
}

function getCoordinatesFromAddress($address) {
    $url = "https://maps.googleapis.com/maps/api/geocode/json?address=" . urlencode($address) . "&key=" . GOOGLE_MAPS_API_KEY;
    $response = file_get_contents($url);
    $data = json_decode($response, true);
    return [
        'lat' => $data['results'][0]['geometry']['location']['lat'],
        'lon' => $data['results'][0]['geometry']['location']['lng']
    ];
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
    ob_start(); // Start output buffering
    $zones = getZonesFromAddress($address);
    header('Content-Type: application/json');
    echo json_encode(['zones' => $zones]);
    ob_end_flush(); // End output buffering and flush output
} catch (Exception $e) {
    ob_end_clean(); // Clear buffer in case of exception
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
}
?>
