<?php
// Includi la connessione al DB e la funzione updateAddressZoneMap
include 'db.php';

// Copia qui la funzione updateAddressZoneMap se non è già caricata
function calculateDistance($origin, $destination) {
    $earthRadiusKm = 6371;
    $dLat = deg2rad($destination[0] - $origin[0]);
    $dLng = deg2rad($destination[1] - $origin[1]);
    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($origin[0])) * cos(deg2rad($destination[0])) *
         sin($dLng/2) * sin($dLng/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    $distance = $earthRadiusKm * $c;
    $distanceCorrection = 1.4;
    $estimatedRoadDistance = $distance * $distanceCorrection;
    return $estimatedRoadDistance;
}
function updateAddressZoneMap($address_cache_id, $lat, $lng) {
    global $conn;
    $conn->query("DELETE FROM address_cache_zone_map WHERE address_cache_id = $address_cache_id");
    $sql = "SELECT id, latitude, longitude, radius_km FROM cp_zones";
    $zones = $conn->query($sql);
    if ($zones) {
        while ($z = $zones->fetch_assoc()) {
            $dist = calculateDistance([$lat, $lng], [$z['latitude'], $z['longitude']]);
            if ($dist <= $z['radius_km']) {
                $stmt = $conn->prepare("INSERT INTO address_cache_zone_map (address_cache_id, zone_id) VALUES (?, ?)");
                $stmt->bind_param("ii", $address_cache_id, $z['id']);
                $stmt->execute();
            }
        }
    }
}

// Script batch
$sql = "SELECT id, latitude, longitude FROM address_cache WHERE latitude IS NOT NULL AND longitude IS NOT NULL";
$res = $conn->query($sql);

$count = 0;
$updated = 0;
while ($row = $res->fetch_assoc()) {
    $count++;
    if ($row['latitude'] !== null && $row['longitude'] !== null && $row['latitude'] !== '' && $row['longitude'] !== '') {
        updateAddressZoneMap($row['id'], $row['latitude'], $row['longitude']);
        $updated++;
    }
}
echo "Batch terminato. $updated/$count indirizzi aggiornati nelle relazioni zona.<br>";

// Opzionale: termina la connessione
$conn->close();
?>