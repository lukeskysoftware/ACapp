<?php
// Start the session and output buffering to prevent any output before headers are sent
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Define the timeout duration (e.g., 1800 seconds = 30 minutes)
$timeout_duration = 1800;

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit(); // Ensure the script stops executing after the redirect
}

// Check if the timeout has been set
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
    // Last request was more than the timeout duration ago
    session_unset();     // Unset $_SESSION variable for the run-time 
    session_destroy();   // Destroy session data in storage
    header("Location: login.php");
    exit(); // Ensure the script stops executing after the redirect
}

// Update last activity time stamp
$_SESSION['last_activity'] = time();

// Include database connection
include 'db.php';

// Define functions for calculations and database operations

// Function to calculate distance between two coordinates
function calculateDistance($origin, $destination) {
    $earthRadiusKm = 6371;

    $dLat = deg2rad($destination[0] - $origin[0]);
    $dLng = deg2rad($destination[1] - $origin[1]);

    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($origin[0])) * cos(deg2rad($destination[0])) *
         sin($dLng/2) * sin($dLng/2);

    $c = 2 * atan2(sqrt($a), sqrt(1-$a));

    return $earthRadiusKm * $c;
}

// Function to get zones from coordinates
function getZonesFromCoordinates($latitude, $longitude) {
    global $conn;
    $sql = "SELECT id, name, latitude, longitude, radius_km FROM cp_zones";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log("Database prepare failed: " . mysqli_error($conn));
        throw new Exception("Database prepare failed: " . mysqli_error($conn));
    }

    if (!$stmt->execute()) {
        error_log("Database query failed: " . mysqli_error($conn));
        throw new Exception("Database query failed: " . mysqli_error($conn));
    }

    $zones = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    return $zones;
}

// Function to get slots for a specific zone
function getSlotsForZone($zoneId) {
    global $conn;
    $sql = "SELECT day, time FROM cp_slots WHERE zone_id = ?";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log("Database prepare failed for slots: " . mysqli_error($conn));
        throw new Exception("Database prepare failed for slots: " . mysqli_error($conn));
    }

    $stmt->bind_param("i", $zoneId);

    if (!$stmt->execute()) {
        error_log("Database query failed for slots: " . mysqli_error($conn));
        throw new Exception("Database query failed for slots: " . mysqli_error($conn));
    }

    $slots = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    return $slots;
}

// Check if appointment is available
function isAppointmentAvailable($zoneId, $appointmentDate, $appointmentTime) {
    global $conn;
    $sql = "SELECT COUNT(*) FROM cp_appointments WHERE zone_id = ? AND appointment_date = ? AND appointment_time = ?";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log("Database prepare failed for checking appointment availability: " . mysqli_error($conn));
        throw new Exception("Database prepare failed for checking appointment availability: " . mysqli_error($conn));
    }

    $stmt->bind_param("iss", $zoneId, $appointmentDate, $appointmentTime);

    if (!$stmt->execute()) {
        error_log("Database query failed for checking appointment availability: " . mysqli_error($conn));
        throw new Exception("Database query failed for checking appointment availability: " . mysqli_error($conn));
    }

    $stmt->bind_result($count);
    $stmt->fetch();

    return $count === 0;
}

// Function to get the next 3 available appointment dates and times
function getNext3AppointmentDates($slots, $zoneId) {
    global $conn;
    $next3Days = [];
    $currentDate = new DateTime();
    $currentDayOfWeek = $currentDate->format('N'); // Day of the week (1 = Monday, 7 = Sunday)

    while (count($next3Days) < 3) {
        foreach ($slots as $slot) {
            $slotDayOfWeek = date('N', strtotime($slot['day']));
            $daysUntilSlot = ($slotDayOfWeek - $currentDayOfWeek + 7) % 7;
            $appointmentDate = clone $currentDate;
            $appointmentDate->modify("+$daysUntilSlot days");
            $formattedDate = $appointmentDate->format('Y-m-d');

            // Check if slot is available
            if (isAppointmentAvailable($zoneId, $formattedDate, $slot['time'])) {
                $next3Days[$formattedDate][] = $slot['time'];
            }
        }
        $currentDate->modify('+1 week');
    }

    return array_slice($next3Days, 0, 3, true);
}

// Function to add patient information to the cp_patients table
function addPatient($name, $surname, $phone, $notes) {
    global $conn;
    $sql = "INSERT INTO cp_patients (name, surname, phone, notes) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log("Database prepare failed for adding patient: " . mysqli_error($conn));
        throw new Exception("Database prepare failed for adding patient: " . mysqli_error($conn));
    }

    $stmt->bind_param("ssss", $name, $surname, $phone, $notes);

    if (!$stmt->execute()) {
        error_log("Database query failed for adding patient: " . mysqli_error($conn));
        throw new Exception("Database query failed for adding patient: " . mysqli_error($conn));
    }

    return $conn->insert_id;
}

// Function to add appointment information to the cp_appointments table
function addAppointment($zoneId, $patientId, $appointmentDate, $appointmentTime) {
    global $conn;
    $formattedDate = date('Y-m-d', strtotime($appointmentDate)); // Ensure correct date format
    $sql = "INSERT INTO cp_appointments (zone_id, patient_id, appointment_date, appointment_time) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log("Database prepare failed for adding appointment: " . mysqli_error($conn));
        throw new Exception("Database prepare failed for adding appointment: " . mysqli_error($conn));
    }

    $stmt->bind_param("iiss", $zoneId, $patientId, $formattedDate, $appointmentTime);

    if (!$stmt->execute()) {
        error_log("Database query failed for adding appointment: " . mysqli_error($conn));
        throw new Exception("Database query failed for adding appointment: " . mysqli_error($conn));
    }
}

// Handle address input and available appointment slots
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['address']) && isset($_POST['latitude']) && isset($_POST['longitude'])) {
    header('Content-Type: text/html; charset=UTF-8');
    $address = $_POST['address'];
    $latitude = $_POST['latitude'];
    $longitude = $_POST['longitude'];
    $name = isset($_POST['name']) ? $_POST['name'] : '';
    $surname = isset($_POST['surname']) ? $_POST['surname'] : '';
    $phone = isset($_POST['phone']) ? $_POST['phone'] : '';

    // Debugging: Log the received POST data
    error_log("Received POST data: address={$address}, latitude={$latitude}, longitude={$longitude}, name={$name}, surname={$surname}, phone={$phone}");

    try {
        $zones = getZonesFromCoordinates($latitude, $longitude);
        $origin = [$latitude, $longitude];

        // Debugging: Log the origin coordinates
        error_log("Origin coordinates: lat={$latitude}, lng={$longitude}");

        echo "<h2>Indirizzo: {$address}</h2>";
        echo "<p>Coordinate dell'indirizzo: Latitudine={$latitude}, Longitudine={$longitude}</p>";

        $zonesFound = false;
        $zoneNames = [];
        foreach ($zones as $zone) {
            $destination = [$zone['latitude'], $zone['longitude']];
            $distance = calculateDistance($origin, $destination);
            $difference = $distance - $zone['radius_km'];

            // Hidden div for calculations
            echo "<div style='display:none;'>Zona: {$zone['name']}<br>";
            echo "Coordinate della zona: Latitudine={$zone['latitude']}, Longitudine={$zone['longitude']}<br>";
            echo "Distanza: {$distance} km<br>";
            echo "Raggio: {$zone['radius_km']} km<br>";
            echo "Differenza: {$difference} km<br></div>";

            if ($distance <= $zone['radius_km']) {
                $zonesFound = true;
                $zoneNames[] = $zone['name'];
                $slots = getSlotsForZone($zone['id']);
                if (!empty($slots)) {
                    echo "<h4>Appuntamenti disponibili per i prossimi 3 giorni per la zona {$zone['name']}:</h4>";
                    $next3Days = getNext3AppointmentDates($slots, $zone['id']);
                    foreach ($next3Days as $date => $times) {
                        $formattedDisplayDate = strftime('%d %B %Y', strtotime($date)); // Change format for display
                        echo "<p>Data: {$formattedDisplayDate}</p>";
                        echo "<p>Fasce orarie disponibili: ";
                        foreach ($times as $time) {
                            $formattedTime = date('H:i', strtotime($time)); // Remove seconds
                            echo "<a href='book_appointment_combined.php?zone_id={$zone['id']}&date={$date}&time={$formattedTime}&address=" . urlencode($address) . "&latitude={$latitude}&longitude={$longitude}&name={$name}&surname={$surname}&phone={$phone}'>{$formattedTime}</a> ";
                        }
                        echo "</p>";
                    }
                } else {
                    echo "<p>Nessun appuntamento disponibile per i prossimi 3 giorni per la zona {$zone['name']}.</p>";
                }
            }
        }

        if ($zonesFound) {
            $zoneText = implode(', ', $zoneNames);
            echo "<p>L'indirizzo appartiene alla zona {$zoneText}.</p>";
        } else {
            echo "<p>L'indirizzo non si trova in nessuna zona.</p>";
        }
    } catch (Exception $e) {
        error_log("Exception: " . $e->getMessage());
        echo 'Si è verificato un errore: ' . $e->getMessage();
    }
    exit;
}

// Handle appointment booking
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['zone_id']) && isset($_POST['date']) && isset($_POST['time']) && isset($_POST['name']) && isset($_POST['surname']) && isset($_POST['phone'])) {
    header('Content-Type: text/html; charset=UTF-8');
    $zoneId = $_POST['zone_id'];
    $appointmentDate = $_POST['date'];
    $appointmentTime = $_POST['time'];
    $name = $_POST['name'];
    $surname = $_POST['surname'];
    $phone = $_POST['phone'];
    $notes = $_POST['notes'];

    try {
        if (isAppointmentAvailable($zoneId, $appointmentDate, $appointmentTime)) {
            $patientId = addPatient($name, $surname, $phone, $notes);
            addAppointment($zoneId, $patientId, $appointmentDate, $appointmentTime);

            // Ensure correct date formatting
            $appointmentDateFormatted = strftime('%d %B %Y', strtotime($appointmentDate)); // Italian format with month name

            $formattedAppointmentTime = date('H:i', strtotime($appointmentTime)); // Remove seconds

            echo "<p>Appuntamento prenotato con successo per il {$appointmentDateFormatted} alle {$formattedAppointmentTime}.</p>";
        } else {
            echo "<p>L'orario selezionato non è disponibile. Si prega di scegliere un altro orario.</p>";
        }
    } catch (Exception $e) {
        error_log("Exception: " . $e->getMessage());
        echo 'Si è verificato un errore: ' . $e->getMessage();
    }
    exit;
}

// Handle GET request for appointment booking
if ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET['zone_id']) && isset($_GET['date']) && isset($_GET['time']) && isset($_GET['address'])) {
    $zone_id = $_GET['zone_id'];
    $date = $_GET['date'];
    $time = $_GET['time'];
    $address = $_GET['address'];
    $name = isset($_GET['name']) ? $_GET['name'] : '';
    $surname = isset($_GET['surname']) ? $_GET['surname'] : '';
    $phone = isset($_GET['phone']) ? $_GET['phone'] : '';

    // Debugging: Log the received GET data
    error_log("Received GET data: zone_id={$zone_id}, date={$date}, time={$time}, address={$address}");

    // Ensure all parameters are received
    if (!$zone_id || !$date || !$time || !$address) {
        echo "Missing parameters!";
        exit;
    }

    // Check if the appointment time slot is already booked
    $query = $conn->prepare("SELECT COUNT(*) AS count FROM cp_appointments WHERE zone_id = ? AND appointment_date = ? AND appointment_time = ?");
    $query->bind_param("iss", $zone_id, $date, $time);
    $query->execute();
    $result = $query->get_result();
    $row = $result->fetch_assoc();

    if ($row['count'] > 0) {
        echo "This time slot is already booked. Please choose another time.";
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Prenota Appuntamento</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/purecss@3.0.0/build/pure-min.css" integrity="sha384-X38yfunGUhNzHpBaEBsWLO+A0HDYOQi8ufWDkZ0k9e0eXz/tH3II7uKZ9msv++Ls" crossorigin="anonymous">
    <link rel="stylesheet" href="styles.css">
    <style>
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            text-align: center;
        }
        .menu {
            margin-bottom: 20px;
        }
    </style>
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
                displayMessage('Error fetching API key: ' + error.message);
            }
        }

        window.addEventListener('load', loadAPIKey);

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
                    document.getElementById('latitude').value = place.geometry.location.lat();
                    document.getElementById('longitude').value = place.geometry.location.lng();
                    displayCoordinates(place.geometry.location.lat(), place.geometry.location.lng());
                }
            });
        }

        function displayCoordinates(lat, lng) {
            document.getElementById('coordinates').innerText = `Latitudine: ${lat}, Longitudine: ${lng}`;
        }

        function displayMessage(message) {
            const messageContainer = document.getElementById('messageContainer');
            messageContainer.innerHTML = `<p>${message}</p>`;
            messageContainer.style.display = 'block';
        }

        function searchSurname() {
            const surnameInput = document.getElementById('surname_search').value;
            if (surnameInput.length > 2) {
                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'book_appointment_combined.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onreadystatechange = function () {
                    if (xhr.readyState === 4 && xhr.status === 200) {
                        document.getElementById('patientsList').innerHTML = xhr.responseText;
                    }
                };
                xhr.send('surname_search=' + encodeURIComponent(surnameInput));
            } else {
                document.getElementById('patientsList').innerHTML = '';
            }
        }

        function selectPatient(name, surname, phone) {
            document.getElementById('name').value = name;
            document.getElementById('surname').value = surname;
            document.getElementById('phone').value = phone;
            document.getElementById('patientsList').innerHTML = '';
        }
    </script>
</head>
<body>
    <div class="menu">
        <?php include 'menu.php'; ?>
    </div>
    <div class="container">
        <h1>Prenota Appuntamento</h1>
        <label for="surname_search">Cerca Paziente per Cognome:</label>
        <input type="text" id="surname_search" onkeyup="searchSurname()">
        <div id="patientsList"></div>

        <form class="pure-form" action="book_appointment_combined.php" method="POST">
            <fieldset class="pure-group">
                <input type="text" class="pure-input-1-2" id="address" name="address" placeholder="Indirizzo" required>
                <input type="hidden" id="latitude" name="latitude">
                <input type="hidden" id="longitude" name="longitude">
            </fieldset>

            <fieldset class="pure-group">
                <input type="text" class="pure-input-1-2" id="name" name="name" placeholder="Nome" required>
                <input type="text" class="pure-input-1-2" id="surname" name="surname" placeholder="Cognome" required>
                <input type="text" class="pure-input-1-2" id="phone" name="phone" placeholder="Telefono" required>
                <textarea class="pure-input-1-2" name="notes" placeholder="Note"></textarea>
            </fieldset>

            <button type="submit" class="pure-button pure-button-primary">Cerca Disponibilità</button>
        </form>
        
        <div id="coordinates"></div>
        <div id="messageContainer" style="display: none;"></div>
    </div>
</body>
</html>