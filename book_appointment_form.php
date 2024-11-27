<!DOCTYPE html>
<html>
<head>
    <title>Book Appointment</title>
    <?php include 'config.php'; ?>
    <?php include 'menu.php'; ?>

    <script>
        let rawResponseText = '';

        async function loadAPIKey() {
            try {
                const response = await fetch('get_api_key.php');
                if (!response.ok) throw new Error('Failed to load API key');
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
            .then(response => {
                if (!response.ok) throw new Error('Failed to fetch zones');
                return response.json();
            })
            .then(data => {
                rawResponseText = JSON.stringify(data);
                const messageDiv = document.getElementById('message');
                messageDiv.innerHTML = 'Data fetched. Click "Process Data" to process the data.';
                messageDiv.style.color = 'green';
                document.getElementById('rawData').value = rawResponseText;
            })
            .catch(error => {
                console.error('Error fetching zones:', error);
                const messageDiv = document.getElementById('message');
                messageDiv.innerHTML = 'Error fetching zones: ' + error.message;
                messageDiv.style.color = 'red';
            });
        }

        function processData() {
            try {
                const rawData = document.getElementById('rawData').value;
                const data = JSON.parse(rawData);
                const messageDiv = document.getElementById('message');
                if (data.zones && data.zones.length > 0) {
                    displayZones(data.zones);
                    messageDiv.innerHTML = 'Zones found for this location. Calculations: ' + JSON.stringify(data.debug);
                    messageDiv.style.color = 'green';
                } else {
                    messageDiv.innerHTML = 'No zones found for this location.';
                    messageDiv.style.color = 'red';
                }
            } catch (e) {
                console.error('Data Processing Error:', e);
                const messageDiv = document.getElementById('message');
                messageDiv.innerHTML = 'Error processing zones data: ' + e.message;
                messageDiv.style.color = 'red';
            }
        }

        function displayZones(zones) {
            const zoneDetails = document.getElementById('zoneDetails');
            zoneDetails.innerHTML = '<h3>Zones for the Location:</h3>';
            zones.forEach(zone => {
                const zoneDiv = document.createElement('div');
                zoneDiv.textContent = `Zone: ${zone.zone_name}`;
                zoneDetails.appendChild(zoneDiv);
            });
            zoneDetails.style.display = 'block';
        }

        function checkAddress() {
            const address = document.getElementById('address').value;
            if (address) {
                initAutocomplete();
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
        <button type="button" onclick="processData()">Process Data</button><br><br>
        
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
            <input type="hidden" id="rawData" name="rawData">
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

    function calculateDistance($lat1, $lon1, $lat2, $lon2) {
        $theta = $lon1 - $lon2;
        $distance = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
        $distance = acos($distance);
        $distance = rad2deg($distance);
        $distance = $distance * 60 * 1.1515 * 1.609344;
        return $distance;
    }

    function getItalianDayOfWeek($dayNumber) {
        $days = ["Domenica", "Lunedì", "Martedì", "Mercoledì", "Giovedì", "Venerdì", "Sabato"];
        return $days[$dayNumber - 1];
    }

    function getNextDateForDayOfWeek($dayOfWeek, $startingDate) {
        $nextDate = clone $startingDate;
        $nextDate->modify('next ' . getItalianDayOfWeek($dayOfWeek));
        return $nextDate;
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
            echo json_encode([
                'zones' => $zones,
                'debug' => [
                    'received_coordinates' => [
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                    ],
                    'zones_data' => $zones,
                ],
            ]);
        } catch (Exception $e) {
            error_log("Error: " . $e->getMessage());

            header('Content-Type: application/json');
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    function getZonesFromCoordinates($latitude, $longitude) {
        global $conn;
        $sql = "SELECT id, zone_name, latitude, longitude, day_of_week, start_time, end_time FROM cp_zones";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $assigned_zones = [];
        $current_date = new DateTime();
        $current_day_of_week = (int)$current_date->format('N');

        foreach ($zones as $row) {
            $distance = calculateDistance($latitude, $longitude, $row['latitude'], $row['longitude']);
            if ($distance <= 5) {
                if ($row['day_of_week'] == $current_day_of_week) {
                    $current_date->add(new DateInterval('P1D'));
                    continue;
                }
                $next_occurrence_date = getNextDateForDayOfWeek($row['day_of_week'], $current_date);
                $next_available_time = $next_occurrence_date->format('Y-m-d') . ' ' . $row['start_time'];

                $query_appointments = "SELECT COUNT(*) AS num_appointments FROM appointments WHERE zone_id = '{$row['id']}' AND appointment_date = '$next_available_time' AND start_time = '{$row['start_time']}' AND end_time = '{$row['end_time']}'";
                $result_appointments = $conn->query($query_appointments);
                $appointment_count = ($result_appointments->num_rows > 0) ? $result_appointments->fetch_assoc()['num_appointments'] : 0;

                if ($appointment_count == 0) {
                    $assigned_zones[] = [
                        'zone_id' => $row['id'],
                        'zone_name' => $row['zone_name'],
                        'next_available_time' => $next_available_time,
                        'distance' => $distance
                    ];
                }
            }
        }

        return $assigned_zones;
    }
    ?>
</body>
</html>
