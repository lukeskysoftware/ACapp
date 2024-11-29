<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
include 'db.php';

// Set locale to Italian
setlocale(LC_TIME, 'it_IT.UTF-8');

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

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['address']) && isset($_POST['latitude']) && isset($_POST['longitude'])) {
    header('Content-Type: text/html; charset=UTF-8');
    $address = $_POST['address'];
    $latitude = $_POST['latitude'];
    $longitude = $_POST['longitude'];

    // Debugging: Log the received POST data
    error_log("Received POST data: address={$address}, latitude={$latitude}, longitude={$longitude}");

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
                            echo "<a href='book_appointment.php?zone_id={$zone['id']}&date={$date}&time={$formattedTime}&address=" . urlencode($address) . "'>{$formattedTime}</a> ";
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

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['zone_id']) && isset($_POST['date']) && isset($_POST['time']) && isset($_POST['name']) && isset($_POST['surname']) && isset($_POST['phone']) && isset($_POST['notes'])) {
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
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Calcolo Indirizzo</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/purecss@3.0.0/build/pure-min.css" integrity="sha384-X38yfunGUhNzHpBaEBsWLO+A0HDYOQi8ufWDkZ0k9e0eXz/tH3II7uKZ9msv++Ls" crossorigin="anonymous">
</head>
<body>
    <h1>Calcolo Indirizzo</h1>
    <form method="POST" action="address_calculate.php">
        <label for="address">Indirizzo:</label>
        <input type="text" id="address" name="address" required><br><br>

        <label for="latitude">Latitudine:</label>
        <input type="text" id="latitude" name="latitude" required><br><br>

        <label for="longitude">Longitudine:</label>
        <input type="text" id="longitude" name="longitude" required><br><br>

        <button type="submit">Calcola</button>
    </form>

    <div id="result"></div>

    <div id="appointmentForm" style="display:none;">
        <h2>Prenota Appuntamento</h2>
        <form method="POST" action="address_calculate.php">
            <input type="hidden" id="zone_id" name="zone_id">
            <input type="hidden" id="date" name="date">
            <input type="hidden" id="time" name="time">
            
            <label for="name">Nome:</label>
            <input type="text" id="name" name="name" required><br><br>

            <label for="surname">Cognome:</label>
            <input type="text" id="surname" name="surname" required><br><br>

            <label for="phone">Telefono:</label>
            <input type="text" id="phone" name="phone" required><br><br>

            <label for="notes">Note:</label>
            <textarea id="notes" name="notes"></textarea><br><br>

            <button type="submit">Prenota</button>
        </form>
    </div>

    <script>
        document.querySelectorAll("a[href^='book_appointment.php']").forEach(function(el) {
            el.addEventListener('click', function(event) {
                event.preventDefault();
                document.getElementById('zone_id').value = this.href.split('zone_id=')[1].split('&')[0];
                document.getElementById('date').value = this.href.split('date=')[1].split('&')[0];
                document.getElementById('time').value = this.href.split('time=')[1].split('&')[0];
                document.getElementById('appointmentForm').style.display = 'block';
                window.scrollTo(0, document.getElementById('appointmentForm').offsetTop);
            });
        });
    </script>
</body>
</html>
