<!DOCTYPE html>
<html>
<head>
    <title>Book Appointment</title>
    <?php include 'config.php'; ?>
    <?php include 'menu.php'; ?>
    
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

        document.addEventListener('DOMContentLoaded', function() {
            loadAPIKey();
        });

        function initAutocomplete() {
            var input = document.getElementById('address');
            var options = {
                types: ['geocode'],
                strictBounds: true,
                bounds: {
                    north: 42.1,
                    south: 40.8,
                    west: 11.5,
                    east: 13.0
                }
            };
            var autocomplete = new google.maps.places.Autocomplete(input, options);

            autocomplete.addListener('place_changed', function() {
                var place = autocomplete.getPlace();
                if (place.geometry) {
                    var latitude = place.geometry.location.lat();
                    var longitude = place.geometry.location.lng();
                    document.getElementById('latitude').value = latitude;
                    document.getElementById('longitude').value = longitude;
                    fetchZones(latitude, longitude);
                }
            });
        }

  function fetchZones(latitude, longitude) {
    const formData = new FormData();
    formData.append('latitude', latitude);
    formData.append('longitude', longitude);

    fetch('', {
        method: 'POST',
        body: formData
    })
        .then(response => response.text()) // Change to text to debug the raw response
        .then(text => {
            console.log('Raw response:', text); // Log the raw response
            try {
                const data = JSON.parse(text); // Try parsing the JSON
                const messageDiv = document.getElementById('message');
                if (data.zones && data.zones.length > 0) {
                    displayZones(data.zones);
                    messageDiv.innerHTML = 'Zones found for this location.';
                    messageDiv.style.color = 'green';
                } else {
                    messageDiv.innerHTML = 'No zones found for this location.';
                    messageDiv.style.color = 'red';
                }
            } catch (e) {
                console.error('JSON Parse Error:', e);
                const messageDiv = document.getElementById('message');
                messageDiv.innerHTML = 'Error parsing zones data.';
                messageDiv.style.color = 'red';
            }
        })
        .catch(error => {
            console.error('Error fetching zones:', error);
            const messageDiv = document.getElementById('message');
            messageDiv.innerHTML = 'Error fetching zones.';
            messageDiv.style.color = 'red';
        });
}

        function displayZones(zones) {
            const zoneDetails = document.getElementById('zoneDetails');
            zoneDetails.innerHTML = '<h3>Zones for the Location:</h3>';
            zones.forEach(zone => {
                const zoneDiv = document.createElement('div');
                zoneDiv.textContent = `Zone: ${zone.name}`;
                zoneDetails.appendChild(zoneDiv);
            });
            zoneDetails.style.display = 'block';
        }

        function checkAddress() {
            const address = document.getElementById('address').value;
            if (address) {
                initAutocomplete(); // Initialize autocomplete to get lat and long
            } else {
                const messageDiv = document.getElementById('message');
                messageDiv.innerHTML = 'Please enter an address.';
                messageDiv.style.color = 'red';
            }
        }
    </script>
</head>
<body>
    <h2>Book Appointment</h2>
    <form id="appointmentForm" action="book_appointment.php" method="post">
        <label for="address">Address:</label><br>
        <input type="text" id="address" name="address" required><br><br>
        <button type="button" onclick="checkAddress()">Check Address</button><br><br>
        
        <div id="message"></div>
        <div id="zoneDetails" style="display:none;"></div>

        <div id="userDetails" style="display:none;">
            <label for="name">Name:</label><br>
            <input type="text" id="name" name="name" required><br><br>
            
            <label for="surname">Surname:</label><br>
            <input type="text" id="surname" name="surname" required><br><br>
            
            <label for="phone">Phone:</label><br>
            <input type="tel" id="phone" name="phone" required><br><br>
            
            <label for="notes">Notes:</label><br>
            <textarea id="notes" name="notes"></textarea><br><br>
            
            <input type="hidden" id="selectedSlot" name="selectedSlot">
            <input type="hidden" id="zone_id" name="zone_id">
            <input type="hidden" id="latitude" name="latitude">
            <input type="hidden" id="longitude" name="longitude">
            <input type="submit" value="Confirm Appointment">
        </div>
    </form>

    <?php
    include 'db.php';

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
        $data = json_decode($response, true);

        if ($data['status'] === 'OK') {
            $latitude = $data['results'][0]['geometry']['location']['lat'];
            $longitude = $data['results'][0]['geometry']['location']['lng'];
            return ['latitude' => $latitude, 'longitude' => $longitude];
        } else {
            throw new Exception('Failed to geocode address: ' . $data['status']);
        }
    }

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        try {
            if (!isset($_POST['latitude']) || !isset($_POST['longitude'])) {
                throw new Exception('Latitude and Longitude are required.');
            }

            $latitude = $_POST['latitude'];
            $longitude = $_POST['longitude'];

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
    }
    ?>
</body>
</html>
