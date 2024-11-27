<?php
include 'db.php';
include 'config.php';

$address = $_GET['address'];

// Funzione per trovare le zone in base all'indirizzo
function getZonesFromAddress($address) {
    global $conn;
    $sql = "SELECT * FROM zones WHERE ST_Distance_Sphere(POINT(lon, lat), POINT(:lon, :lat)) <= radius_km * 1000";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':lon', $lon);
    $stmt->bindParam(':lat', $lat);

    // Converti l'indirizzo in coordinate geografiche (latitudine e longitudine)
    $coordinates = getCoordinatesFromAddress($address);
    $lon = $coordinates['lon'];
    $lat = $coordinates['lat'];

    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Funzione per ottenere le coordinate geografiche da un indirizzo
function getCoordinatesFromAddress($address) {
    // Utilizza l'API di Google Maps per ottenere le coordinate
    $url = "https://maps.googleapis.com/maps/api/geocode/json?address=" . urlencode($address) . "&key=" . GOOGLE_MAPS_API_KEY;
    $response = file_get_contents($url);
    $data = json_decode($response, true);
    return [
        'lat' => $data['results'][0]['geometry']['location']['lat'],
        'lon' => $data['results'][0]['geometry']['location']['lng']
    ];
}

$zones = getZonesFromAddress($address);

header('Content-Type: application/json');
echo json_encode(['zones' => $zones]);
?>
