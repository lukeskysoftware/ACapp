<?php
include 'db.php';

// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $theta = $lon1 - $lon2;
    $distance = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
    $distance = acos($distance);
    $distance = rad2deg($distance);
    $distance = $distance * 60 * 1.1515 * 1.609344;
    return $distance;
}

function getZonesFromCoordinates($latitude, $longitude) {
    global $conn;
    $sql = "SELECT id, zone_name, latitude, longitude, radius_km FROM cp_zones";
    $stmt = $conn->prepare($sql);

    // Handle database execution errors
    if (!$stmt->execute()) {
        throw new Exception("Database query failed: " . $stmt->errorInfo()[2]);
    }

    $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return $zones;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['latitude']) && isset($_POST['longitude'])) {
    $latitude = $_POST['latitude'];
    $longitude = $_POST['longitude'];

    $zones = getZonesFromCoordinates($latitude, $longitude);
    $result = [];

    foreach ($zones as $zone) {
        $distance = calculateDistance($latitude, $longitude, $zone['latitude'], $zone['longitude']);
        if ($distance <= $zone['radius_km']) {
            $result[] = $zone;
        }
    }

    header('Content-Type: application/json');
    echo json_encode(['zones_in_radius' => $result]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calcolo Indirizzo</title>
    <link rel="stylesheet" href="styles.css">
    <script>
        async function loadAPIKey() {
            try {
                const response = await fetch('get_api_key.php');
                const data = await response.json();
                const apiKey = data.api_key;
                const script = document.createElement('script');
                script.src = `https://maps.googleapis.com/maps/api/js?key=${apiKey}&libraries=places&language=it`;
                script.async = true;
                script.onload = initAutocomplete;
                document.head.appendChild(script);
            } catch (error) {
                console.error('Error fetching API key:', error);
            }
        }

        window.addEventListener('load', loadAPIKey);

        function initAutocomplete() {
            var input = document.getElementById('address');
            var autocomplete = new google.maps.places.Autocomplete(input, { types: ['geocode'] });

            autocomplete.addListener('place_changed', function() {
                var place = autocomplete.getPlace();
                if (place.geometry) {
                    document.getElementById('latitude').value = place.geometry.location.lat();
                    document.getElementById('longitude').value = place.geometry.location.lng();
                    checkAddressInRadius();
                }
            });
        }

        function checkAddressInRadius() {
            const latitude = document.getElementById('latitude').value;
            const longitude = document.getElementById('longitude').value;

            const formData = new FormData();
            formData.append('latitude', latitude);
            formData.append('longitude', longitude);

            fetch('address_calculate.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.zones_in_radius && data.zones_in_radius.length > 0) {
                    displayZonesInRadius(data.zones_in_radius);
                } else {
                    alert('No zones within the radius found for this location.');
                }
            })
            .catch(error => {
                console.error('Error fetching zones:', error);
                alert('Error fetching zones: ' + error.message);
            });
        }

        function displayZonesInRadius(zones) {
            const zoneDetails = document.getElementById('zoneDetails');
            zoneDetails.innerHTML = '<h3>Zones within Radius:</h3>';
            zones.forEach(zone => {
                const zoneDiv = document.createElement('div');
                zoneDiv.textContent = `Zone: ${zone.zone_name}`;
                zoneDetails.appendChild(zoneDiv);
            });
            zoneDetails.style.display = 'block';
        }
    </script>
</head>
<body>
    <div class="container">
        <h2>Calcolo Indirizzo</h2>
        <form>
            <label for="address">Indirizzo:</label>
            <input type="text" id="address" name="address" required><br>

            <input type="hidden" id="latitude" name="latitude">
            <input type="hidden" id="longitude" name="longitude">
        </form>

        <div id="zoneDetails" style="display:none;"></div>
    </div>
    <div class="container">
        <a href="dashboard.php">Torna alla dashboard</a>
    </div>
</body>
</html>
