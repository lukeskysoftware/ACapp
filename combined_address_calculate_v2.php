<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include 'db.php';
include 'menu.php';

// Fetch Google Maps API key from the config table
$apiKey = '';
$sql = "SELECT value FROM config WHERE name = 'GOOGLE_MAPS_API_KEY'";
$result = mysqli_query($conn, $sql);
if ($result && mysqli_num_rows($result) > 0) {
    $row = mysqli_fetch_assoc($result);
    $apiKey = $row['value'];
} else {
    die('Errore nel recupero della chiave API di Google Maps: ' . mysqli_error($conn));
}

// Capture parameters from the URL
$name = isset($_GET['name']) ? $_GET['name'] : '';
$surname = isset($_GET['surname']) ? $_GET['surname'] : '';
$phone = isset($_GET['phone']) ? $_GET['phone'] : '';

// Set locale to Italian
setlocale(LC_TIME, 'it_IT.UTF-8');

// Function to calculate distance between two coordinates
function calculateDistance($origin, $destination) {
    $earthRadiusKm = 6371;

    // Ensure the values are numeric
    if (!is_numeric($origin[0]) || !is_numeric($origin[1]) || !is_numeric($destination[0]) || !is_numeric($destination[1])) {
        throw new Exception("Non-numeric value encountered in coordinates.");
    }

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

// Function to get existing appointments for a specific zone
function getExistingAppointmentsForZone($zoneId) {
    global $conn;
    $sql = "SELECT appointment_date, appointment_time, address FROM cp_appointments
            INNER JOIN cp_patients ON cp_appointments.patient_id = cp_patients.id 
            WHERE zone_id = ?";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log("Database prepare failed for existing appointments: " . mysqli_error($conn));
        throw new Exception("Database prepare failed for existing appointments: " . mysqli_error($conn));
    }

    $stmt->bind_param("i", $zoneId);

    if (!$stmt->execute()) {
        error_log("Database query failed for existing appointments: " . mysqli_error($conn));
        throw new Exception("Database query failed for existing appointments: " . mysqli_error($conn));
    }

    $appointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    return $appointments;
}
// Function to get latitude and longitude from an address using Google Maps Geocoding API
function getCoordinatesFromAddress($address) {
    $apiKey = 'YOUR_GOOGLE_MAPS_API_KEY';
    $address = urlencode($address);
    $url = "https://maps.googleapis.com/maps/api/geocode/json?address={$address}&key={$apiKey}";

    $response = file_get_contents($url);
    $response = json_decode($response, true);

    if ($response['status'] == 'OK') {
        $latitude = $response['results'][0]['geometry']['location']['lat'];
        $longitude = $response['results'][0]['geometry']['location']['lng'];
        return [$latitude, $longitude];
    } else {
        throw new Exception("Geocoding API error: " . $response['status']);
    }
}

// Function to get the next 3 available appointment dates and times considering existing appointments
function getNext3AppointmentDates($slots, $zoneId, $currentLatitude, $currentLongitude) {
    global $conn;
    $next3Days = [];
    $currentDate = new DateTime();
    $currentDayOfWeek = $currentDate->format('N'); // Day of the week (1 = Monday, 7 = Sunday)

    $existingAppointments = getExistingAppointmentsForZone($zoneId);

    while (count($next3Days) < 3) {
        foreach ($slots as $slot) {
            $slotDayOfWeek = date('N', strtotime($slot['day']));
            $daysUntilSlot = ($slotDayOfWeek - $currentDayOfWeek + 7) % 7;
            $appointmentDate = clone $currentDate;
            $appointmentDate->modify("+$daysUntilSlot days");
            $formattedDate = $appointmentDate->format('Y-m-d');

            $isAvailable = isAppointmentAvailable($zoneId, $formattedDate, $slot['time']);
            $isPreferred = false;

            if ($isAvailable) {
                foreach ($existingAppointments as $appointment) {
                    if ($appointment['appointment_date'] == $formattedDate) {
                        try {
                            list($latitude, $longitude) = getCoordinatesFromAddress($appointment['address']);
                            $distance = calculateDistance([$currentLatitude, $currentLongitude], [$latitude, $longitude]);
                            if ($distance <= 7) {
                                $isPreferred = true;
                                break;
                            }
                        } catch (Exception $e) {
                            error_log("Geocoding error: " . $e->getMessage());
                        }
                    }
                }

                if ($isPreferred) {
                    $next3Days['preferred'][$formattedDate][] = $slot['time'];
                } else {
                    $next3Days['regular'][$formattedDate][] = $slot['time'];
                }
            }
        }
        $currentDate->modify('+1 week');
    }

    return $next3Days;
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

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['zone_id']) && isset($_POST['date']) && isset($_POST['time']) && isset($_POST['name']) && isset($_POST['surname']) && isset($_POST['phone'])) {
    header('Content-Type: text/html; charset=UTF-8');
    $zoneId = $_POST['zone_id'];
    $appointmentDate = $_POST['date'];
    $appointmentTime = $_POST['time'];
    $name = $_POST['name'];
    $surname = $_POST['surname'];
    $phone = $_POST['phone'];
    $notes = isset($_POST['notes']) ? $_POST['notes'] : '';

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
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calcolo Indirizzo</title>
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
        .centrato {
            text-align: center;
        }
        form {
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        input[type="text"] {
            width: 100%;
            max-width: 300px;
            margin-bottom: 10px;
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

        document.addEventListener("DOMContentLoaded", function() {
            document.querySelectorAll(".booking-link").forEach(function(el) {
                el.addEventListener("click", function(event) {
                    event.preventDefault();
                    const zoneId = this.dataset.zoneId;
                    const date = this.dataset.date;
                    const time = this.dataset.time;
                    const address = decodeURIComponent(this.dataset.address);
                    const latitude = this.dataset.latitude;
                    const longitude = this.dataset.longitude;
                    const name = this.dataset.name;
                    const surname = this.dataset.surname;
                    const phone = this.dataset.phone;

                    document.getElementById("zone_id").value = zoneId;
                    document.getElementById("date").value = date;
                    document.getElementById("time").value = time;
                    document.getElementById("address").value = address;
                    document.getElementById("latitude").value = latitude;
                    document.getElementById("longitude").value = longitude;
                    document.getElementById("name").value = name;
                    document.getElementById("surname").value = surname;
                    document.getElementById("phone").value = phone;

                    document.getElementById("appointmentForm").style.display = "block";
                    window.scrollTo(0, document.getElementById("appointmentForm").offsetTop);
                });
            });
        });
    </script>
</head>
<body>
    <div class="container">
        <h2>A quale indirizzo fare la visita?</h2>
        <form id="addressForm" method="POST" action="combined_address_calculate_v2.php" class="pure-form pure-form-stacked">
            <label class="etic" for="address">Indirizzo:</label>
            <input type="text" id="address" name="address" required><br>
            <label class="etic" for="latitude">Latitudine:</label>
            <input type="text" id="latitude" name="latitude" readonly><br>
            <label class="etic" for="longitude">Longitudine:</label>
            <input type="text" id="longitude" name="longitude" readonly><br>
            <input type="hidden" id="name" name="name" value="<?php echo htmlspecialchars($name); ?>">
            <input type="hidden" id="surname" name="surname" value="<?php echo htmlspecialchars($surname); ?>">
            <input type="hidden" id="phone" name="phone" value="<?php echo htmlspecialchars($phone); ?>">
            <button type="submit" class="pure-button pure-button-primary">Avanti</button>
        </form>
        <div id="coordinates" style="margin-top: 10px;"></div>
        <div id="messageContainer" style="display:none;"></div>
        <a href="dashboard.php">Torna alla dashboard</a>
    </div>

    <div class="container">
        <div id="appointmentForm" style="display:none; margin-top: 20px;">
            <h2>Prenota Appuntamento</h2>
            <form method="POST" action="combined_address_calculate_v2.php" class="pure-form pure-form-stacked">
                <input type="hidden" id="zone_id" name="zone_id">
                <input type="hidden" id="date" name="date">
                <input type="hidden" id="time" name="time">
                <input type="hidden" id="address" name="address">
                <input type="hidden" id="latitude" name="latitude">
                <input type="hidden" id="longitude" name="longitude">
                <input type="hidden" id="name" name="name">
                <input type="hidden" id="surname" name="surname">
                <input type="hidden" id="phone" name="phone">
                <label for="name">Nome:</label>
                <input type="text" id="name" name="name" required><br><br>
                <label for="surname">Cognome:</label>
                <input type="text" id="surname" name="surname" required><br><br>
                <label for="phone">Telefono:</label>
                <input type="text" id="phone" name="phone" required><br><br>
                <label for="notes">Note:</label>
                <textarea id="notes" name="notes"></textarea><br><br>
                <button type="submit" class="pure-button pure-button-primary">Prenota</button>
            </form>
        </div>
    </div>
</body>
</html>
