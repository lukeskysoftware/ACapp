<?php
include 'db.php';

// Function to get API key from the database
function getAPIKey() {
    global $conn;
    $sql = "SELECT api_key FROM cp_api_keys LIMIT 1";
    $result = mysqli_query($conn, $sql);

    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        return $row['api_key'];
    } else {
        return null;
    }
}

// Function to get coordinates for an address using Google Maps API
function getCoordinates($address, $apiKey) {
    $address = urlencode($address);
    $url = "https://maps.googleapis.com/maps/api/geocode/json?address={$address}&key={$apiKey}";

    $response = file_get_contents($url);
    $json = json_decode($response, true);

    // Debugging: Log the API response
    error_log("API Response: " . print_r($json, true));

    if ($json['status'] == 'OK') {
        $lat = $json['results'][0]['geometry']['location']['lat'];
        $lng = $json['results'][0]['geometry']['location']['lng'];
        return [$lat, $lng];
    } else {
        // Debugging: Log the error
        error_log("Error: Unable to get coordinates. Status: " . $json['status']);
        return null;
    }
}

// Function to calculate distance between two coordinates
function calculateDistance($origin, $destination, $apiKey) {
    $origins = $origin[0] . ',' . $origin[1];
    $destinations = $destination[0] . ',' . $destination[1];
    $url = "https://maps.googleapis.com/maps/api/distancematrix/json?origins={$origins}&destinations={$destinations}&key={$apiKey}";

    $response = file_get_contents($url);
    $json = json_decode($response, true);

    if ($json['status'] == 'OK' && $json['rows'][0]['elements'][0]['status'] == 'OK') {
        $distance = $json['rows'][0]['elements'][0]['distance']['value'] / 1000; // Convert meters to kilometers
        return $distance;
    } else {
        return null;
    }
}

// Function to get zones from coordinates
function getZonesFromCoordinates($latitude, $longitude) {
    global $conn;
    $sql = "SELECT id, zone_name, latitude, longitude, radius_km FROM cp_zones";
    $stmt = $conn->prepare($sql);

    if (!$stmt->execute()) {
        throw new Exception("Database query failed: " . $stmt->errorInfo()[2]);
    }

    $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return $zones;
}

header('Content-Type: text/plain');

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['address']) && isset($_POST['latitude']) && isset($_POST['longitude'])) {
    $address = $_POST['address'];
    $latitude = $_POST['latitude'];
    $longitude = $_POST['longitude'];

    $apiKey = getAPIKey();
    if (!$apiKey) {
        echo 'Unable to retrieve API key.';
        exit;
    }

    $origin = [$latitude, $longitude];

    if ($origin) {
        $zones = getZonesFromCoordinates($latitude, $longitude);
        foreach ($zones as $zone) {
            $destination = [$zone['latitude'], $zone['longitude']];
            $distance = calculateDistance($origin, $destination, $apiKey);
            if ($distance !== null && $distance <= $zone['radius_km']) {
                echo "Zone: {$zone['zone_name']}\n";
            }
        }
    } else {
        echo 'Unable to get coordinates for the given address.';
    }
    exit;
} else {
    echo 'Invalid request.';
}
?>
