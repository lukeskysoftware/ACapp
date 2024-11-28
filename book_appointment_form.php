<?php
include db.php;

// Silent error handling
ini_set(display_errors, 0);
ini_set(log_errors, 1);
error_reporting(E_ALL);

function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $theta = $lon1 - $lon2;
    $distance = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
    $distance = acos($distance);
    $distance = rad2deg($distance);
    $distance = $distance * 60 * 1.1515 * 1.609344;
    return $distance;
}

function getNextDateForDayOfWeek($dayOfWeek, $startingDate) {
    $nextDate = clone $startingDate;
    $nextDate->modify(next . getItalianDayOfWeek($dayOfWeek));
    return $nextDate;
}

function getZonesFromCoordinates($latitude, $longitude) {
    global $conn;
    $sql = SELECT id, zone_name, latitude, longitude, day_of_week, start_time, end_time FROM cp_zones;
    $stmt = $conn->prepare($sql);

    // Handle database execution errors
    if (!$stmt->execute()) {
        throw new Exception(Database query failed: . $stmt->errorInfo()[2]);
    }

    $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $assigned_zones = [];
    $current_date = new DateTime();

    foreach ($zones as $row) {
        $distance = calculateDistance($latitude, $longitude, $row[latitude], $row[longitude]);
        if ($distance <= 5) {
            $next_available_times = [];
            for ($i = 1; $i <= 3; $i++) {
                $next_date = getNextDateForDayOfWeek($row[day_of_week], $current_date);
                $next_available_time = $next_date->format(Y-m-d) . . $row[start_time];

                $query_appointments = SELECT COUNT(*) AS num_appointments FROM appointments WHERE zone_id = {$row[id]} AND appointment_date = $next_available_time;
                $result_appointments = $conn->query($query_appointments);
                $appointment_count = $result_appointments->fetchColumn();

                if ($appointment_count == 0) {
                    $next_available_times[] = $next_available_time;
                }

                $current_date->add(new DateInterval(P1D));
            }

            if (!empty($next_available_times)) {
                $assigned_zones[] = [
                    zone_id => $row[id],
                    zone_name => $row[zone_name],
                    next_available_times => $next_available_times,
                    distance => $distance
                ];
            }
        }
    }

    return $assigned_zones;
}

if ($_SERVER[REQUEST_METHOD] == POST) {
    if (isset($_POST[latitude]) && isset($_POST[longitude])) {
        try {
            $latitude = $_POST[latitude];
            $longitude = $_POST[longitude];

            $zones = getZonesFromCoordinates($latitude, $longitude);

            // Handle empty response
            if (!isset($zones)) {
                $zones = [];
            }

            ob_end_clean();
            header(Content-Type: application/json);
            echo json_encode([zones => $zones]);
            exit;
        } catch (Exception $e) {
            ob_end_clean();
            header(Content-Type: application/json);
            echo json_encode([error => $e->getMessage()]);
            exit;
        }
    } elseif (isset($_POST[zone_id]) && isset($_POST[selectedSlot])) {
        $zone_id = $_POST[zone_id];
        $selectedSlot = $_POST[selectedSlot];
        $name = $_POST[name];
        $surname = $_POST[surname];
        $phone = $_POST[phone];
        $notes = $_POST[notes];

        // Verifica disponibilità dello slot
        $stmt = $conn->prepare(SELECT COUNT(*) FROM appointments WHERE zone_id = ? AND appointment_date = ?);
        $stmt->execute([$zone_id, $selectedSlot]);
        $count = $stmt->fetchColumn();

        if ($count > 0) {
            die(Slot not available);
        }

        // Inserisci appuntamento
        $stmt = $conn->prepare(INSERT INTO appointments (zone_id, appointment_date, name, surname, phone, notes) VALUES (?, ?, ?, ?, ?, ?));
        $stmt->execute([$zone_id, $selectedSlot, $name, $surname, $phone, $notes]);

        echo Appointment successfully booked!;
        exit;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Book Appointment</title>
    <script>
        async function loadAPIKey() {
            try {
                const response = await fetch(get_api_key.php);
                const data = await response.json();
                const apiKey = data.api_key;
                const script = document.createElement(script);
                script.src = https://maps.googleapis.com/maps/api/js?key=${apiKey}&libraries=places&language=it;
                script.async = true;
                script.onload = initAutocomplete;
                document.head.appendChild(script);
            } catch (error) {
                console.error(Error fetching API key:, error);
            }
        }

        document.addEventListener(DOMContentLoaded, function() {
            loadAPIKey();
        });

        function initAutocomplete() {
            const input = document.getElementById(address);
            const autocomplete = new google.maps.places.Autocomplete(input, { types: [geocode] });

            autocomplete.addListener(place_changed, function() {
                const place = autocomplete.getPlace();
                if (place.geometry) {
                    const latitude = place.geometry.location.lat();
                    const longitude = place.geometry.location.lng();
                    document.getElementById(latitude).value = latitude;
                    document.getElementById(longitude).value = longitude;
                    fetchZones(latitude, longitude);
                }
            });
        }

        function fetchZones(latitude, longitude) {
            const formData = new FormData();
            formData.append(latitude, latitude);
            formData.append(longitude, longitude);

            fetch(book_appointment_form.php, {
                method: POST,
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.zones && data.zones.length > 0) {
                    displayZones(data.zones);
                } else {
                    alert(No zones found for this location.);
                }
            })
            .catch(error => {
                console.error(Error fetching zones:, error);
                alert(Error fetching zones:  + error.message);
            });
        }

        function displayZones(zones) {
            const zoneDetails = document.getElementById(zoneDetails);
            zoneDetails.innerHTML = <h3>Zones for the Location:</h3>;
            zones.forEach(zone => {
                const zoneDiv = document.createElement(div);
                zoneDiv.textContent = Zone: ${zone.zone_name};
                zoneDiv.innerHTML += <br>Next available times: <br>;
                zone.next_available_times.forEach(time => {
                    zoneDiv.innerHTML += <a href=# onclick=selectSlot(${zone.zone_id}, ${time})>${time}</a><br>;
                });
                zoneDetails.appendChild(zoneDiv);
            });
            zoneDetails.style.display = block;
        }

        function selectSlot(zoneId, time) {
            document.getElementById(zone_id).value = zoneId;
            document.getElementById(selectedSlot).value = time;
            document.getElementById(userDetails).style.display = block;
        }
    </script>
</head>
<body>
    <h2>Book Appointment</h2>
    <form id=appointmentForm action=book_appointment_form.php method=post>
        <label for=address>Address:</label><br>
        <input type=text id=address name=address required><br><br>
        <input type=hidden id=latitude name=latitude>
        <input type=hidden id=longitude name=longitude>
        
        <div id=zoneDetails style=display:none;></div>

        <div id=userDetails style=display:none;>
            <label for=name>Name:</label><br>
            <input type=text id=name name=name required><br><br>
            
            <label for=surname>Surname:</label><br>
            <input type=text id=surname name=surname required><br><br>
            
            <label for=phone>Phone:</label><br>
            <input type=tel id=phone name=phone required><br><br>
            
            <label for=notes>Notes:</label><br>
            <textarea id=notes name=notes></textarea><br><br>
            
            <input type=hidden id=selectedSlot name=selectedSlot>
            <input type=hidden id=zone_id name=zone_id>
            <input type=submit value=Confirm Appointment>
        </div>
    </form>
</body>
</html>
