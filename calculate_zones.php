<?php
include 'db.php';
include 'config.php';
include 'menu.php';

function getApiKey() {
    global $conn;
    $result = $conn->query("SELECT api_key FROM cp_api_keys LIMIT 1");
    if ($result) {
        return $result->fetch_assoc()['api_key'];
    } else {
        throw new Exception('Failed to retrieve API key from the database.');
    }
}

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

function geocodeAddress($address, $apiKey) {
    $url = "https://maps.googleapis.com/maps/api/geocode/json?address=" . urlencode($address) . "&key=" . $apiKey;
    $response = file_get_contents($url);

    // Debugging: Log the raw API response
    error_log("API Response: " . $response);

    $data = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON Decode Error: " . json_last_error_msg());
        throw new Exception('JSON Decode Error: ' . json_last_error_msg());
    }

    if ($data['status'] === 'OK') {
        $latitude = $data['results'][0]['geometry']['location']['lat'];
        $longitude = $data['results'][0]['geometry']['location']['lng'];
        return ['latitude' => $latitude, 'longitude' => $longitude];
    } else {
        throw new Exception('Failed to geocode address: ' . $data['status']);
    }
}

try {
    if (!isset($_GET['address']) && (!isset($_GET['latitude']) || !isset($_GET['longitude']))) {
        throw new Exception('Address or Latitude and Longitude are required.');
    }

    $apiKey = getApiKey();

    if (isset($_GET['address'])) {
        $address = $_GET['address'];
        $coordinates = geocodeAddress($address, $apiKey);
        $latitude = $coordinates['latitude'];
        $longitude = $coordinates['longitude'];
    } else {
        $latitude = $_GET['latitude'];
        $longitude = $_GET['longitude'];
    }

    error_log("Received coordinates: Latitude=$latitude, Longitude=$longitude");

    $zones = getZonesFromCoordinates($latitude, $longitude);

    error_log("Zones data: " . print_r($zones, true));

    header('Content-Type: application/json');
    echo json_encode(['zones' => $zones]);
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());

    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
}
?>
