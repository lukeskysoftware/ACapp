<?php
// Inizia l'output buffering per gestire il testo spurio
ob_start();

// Inizializza errori e inclusioni
error_reporting(E_ALL);
ini_set('display_errors', 1);
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

    $dLat = deg2rad($destination[0] - $origin[0]);
    $dLng = deg2rad($destination[1] - $origin[1]);

    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($origin[0])) * cos(deg2rad($destination[0])) *
         sin($dLng/2) * sin($dLng/2);

    $c = 2 * atan2(sqrt($a), sqrt(1-$a));

    return $earthRadiusKm * $c;
}

// Funzione per trovare appuntamenti vicini entro il raggio specificato
function findNearbyAppointments($user_address, $user_latitude, $user_longitude, $radius_km = 7) {
    global $conn;
    $today = date('Y-m-d');
    $nearby_appointments = [];
    $debug_info = [];
    
    // Recupera tutti gli appuntamenti futuri
    $sql = "SELECT id, address, appointment_date, appointment_time, zone_id, patient_id, notes 
            FROM cp_appointments 
            WHERE appointment_date >= ? 
            ORDER BY appointment_date, appointment_time";
            
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Database prepare failed: " . mysqli_error($conn));
        return $nearby_appointments;
    }
    
    $stmt->bind_param("s", $today);
    if (!$stmt->execute()) {
        error_log("Execute failed: " . mysqli_error($conn));
        return $nearby_appointments;
    }
    
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        error_log("Nessun appuntamento futuro trovato.");
        return $nearby_appointments;
    }
    
    // Per ogni appuntamento
    while ($row = $result->fetch_assoc()) {
        $appointment_id = $row['id'];
        $address = $row['address'];
        
        $debug_item = [
            'id' => $appointment_id,
            'address' => $address,
            'coords' => 'Non geocodificato',
            'distance' => 'Non calcolata',
            'status' => '',
            'error' => ''
        ];
        
        // Salta se l'indirizzo è vuoto
        if (empty($address)) {
            $debug_item['status'] = 'Saltato - Indirizzo vuoto';
            $debug_info[] = $debug_item;
            continue;
        }
        
        // 1. Verifica se l'appuntamento è già in cache
        $cache_sql = "SELECT appointment_id, latitude, longitude FROM address_cache WHERE appointment_id = ? LIMIT 1";
        $cache_stmt = $conn->prepare($cache_sql);
        $coordinates = null;
        
        if ($cache_stmt) {
            $cache_stmt->bind_param("i", $appointment_id);
            $cache_stmt->execute();
            $cache_result = $cache_stmt->get_result();
            
            if ($cache_result->num_rows > 0) {
                // Coordinate già in cache per questo appuntamento
                $cache_row = $cache_result->fetch_assoc();
                $coordinates = [
                    'lat' => $cache_row['latitude'], 
                    'lng' => $cache_row['longitude']
                ];
                $debug_item['status'] = 'In cache';
                $debug_item['coords'] = "Lat: {$coordinates['lat']}, Lng: {$coordinates['lng']}";
            } else {
                // 2. Nessuna cache, geocodifica l'indirizzo e salvalo
                $coordinates = getCoordinatesFromAddress($address, $appointment_id);
                
                if ($coordinates) {
                    $debug_item['status'] = 'Geocodificato';
                    $debug_item['coords'] = "Lat: {$coordinates['lat']}, Lng: {$coordinates['lng']}";
                } else {
                    $debug_item['status'] = 'Geocodifica fallita';
                    $debug_item['error'] = 'Impossibile ottenere coordinate';
                    $debug_info[] = $debug_item;
                    continue; // Passa al prossimo appuntamento
                }
            }
        } else {
            $debug_item['status'] = 'Errore SQL cache';
            $debug_item['error'] = mysqli_error($conn);
            $debug_info[] = $debug_item;
            continue;
        }
        
        // 3. Calcola la distanza
        $origin = [$user_latitude, $user_longitude];
        $destination = [$coordinates['lat'], $coordinates['lng']];
        
        $distance = calculateDistance($origin, $destination);
        $debug_item['distance'] = number_format($distance, 2) . " km";
        
        // 4. Se la distanza è entro il raggio, aggiungi all'elenco
        if ($distance <= $radius_km) {
            $row['distance'] = $distance;
            $row['latitude'] = $coordinates['lat'];
            $row['longitude'] = $coordinates['lng'];
            $nearby_appointments[] = $row;
            $debug_item['status'] .= ' - Entro raggio';
        } else {
            $debug_item['status'] .= ' - Fuori raggio';
        }
        
        $debug_info[] = $debug_item;
    }
    
    // Ordina gli appuntamenti per distanza
    usort($nearby_appointments, function($a, $b) {
        return $a['distance'] <=> $b['distance'];
    });
    
    // Salva le informazioni di debug in una variabile globale
    global $address_comparison_debug;
    $address_comparison_debug = $debug_info;
    
    return $nearby_appointments;
}

// Funzione per ottenere coordinate da un indirizzo
function getCoordinatesFromAddress($address, $appointment_id = null) {
    global $conn;
    
    // Log dell'operazione
    error_log("Tentativo di geocodifica per indirizzo: " . $address);
    
    // Controlla se abbiamo già le coordinate per questo indirizzo
    $sql = "SELECT latitude, longitude FROM address_cache WHERE address = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("s", $address);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            // Se abbiamo l'ID appuntamento, aggiorniamo la cache
            if ($appointment_id) {
                $insertSql = "INSERT INTO address_cache (appointment_id, address, latitude, longitude) 
             VALUES (?, ?, ?, ?) 
             ON DUPLICATE KEY UPDATE address = VALUES(address), latitude = VALUES(latitude), longitude = VALUES(longitude)";
                $insertStmt = $conn->prepare($insertSql);
                if ($insertStmt) {
                    $insertStmt->bind_param("isdd", $appointment_id, $address, $row['latitude'], $row['longitude']);
                    $insertStmt->execute();
                }
            }
            error_log("Coordinate recuperate dalla cache per: " . $address);
            return ['lat' => $row['latitude'], 'lng' => $row['longitude']];
        }
    }
    
    // Recupera la chiave API dalla tabella config
    $apiKey = '';
    $sql = "SELECT value FROM config WHERE name = 'GOOGLE_MAPS_API_KEY'";
    $result = mysqli_query($conn, $sql);
    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        $apiKey = $row['value'];
    } else {
        error_log('Errore nel recupero della chiave API di Google Maps: ' . mysqli_error($conn));
        return null;
    }
    
    if (empty($apiKey)) {
        error_log("API key non trovata per la geocodifica");
        return null;
    }
    
       $url = "https://maps.googleapis.com/maps/api/geocode/json?address=" . urlencode($address) . "&key=" . $apiKey;
    
    // Usa cURL invece di file_get_contents
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'PHP Geocoding Application');
    $response = curl_exec($ch);
    
    if ($response === false) {
        error_log("Errore cURL durante la chiamata all'API di geocodifica: " . curl_error($ch));
        curl_close($ch);
        return null;
    }
    
    curl_close($ch);
    
    $data = json_decode($response, true);
    
    if ($data['status'] == 'OK') {
        $lat = $data['results'][0]['geometry']['location']['lat'];
        $lng = $data['results'][0]['geometry']['location']['lng'];
        
        // Salva nella cache
        if ($appointment_id) {
           $sql = "INSERT INTO address_cache (appointment_id, address, latitude, longitude) 
       VALUES (?, ?, ?, ?) 
       ON DUPLICATE KEY UPDATE address = VALUES(address), latitude = VALUES(latitude), longitude = VALUES(longitude)";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("isdd", $appointment_id, $address, $lat, $lng);
    if (!$stmt->execute()) {
        error_log("Errore nell'inserimento nella cache: " . $stmt->error);
    } else {
        error_log("Cache aggiornata con successo per appointment_id=$appointment_id");
    }
}
        } else {
            // Cache senza appointment_id (per indirizzi temporanei)
            $sql = "INSERT INTO address_cache (address, latitude, longitude) 
                   VALUES (?, ?, ?)";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("sdd", $address, $lat, $lng);
                $stmt->execute();
            }
        }
        
        error_log("Geocodifica riuscita per: " . $address);
        return ['lat' => $lat, 'lng' => $lng];
    } else {
        // Errore nella risposta API
        error_log("Errore geocodifica per " . $address . ": " . $data['status'] . " - " . ($data['error_message'] ?? ''));
        return null;
    }
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

// Funzione per verificare slot disponibili prima/dopo un appuntamento esistente
function checkAvailableSlotsNearAppointment($appointmentData, $buffer_minutes = 60) {
    global $conn;
    $available_slots = [];
    $default_duration = 60; // Durata predefinita in minuti
    
    // Converti data e ora dell'appuntamento in oggetto DateTime
    $appointment_datetime = new DateTime($appointmentData['appointment_date'] . ' ' . $appointmentData['appointment_time']);
    
    // Slot prima (-60 minuti)
    $before_slot = clone $appointment_datetime;
    $before_slot->modify('-' . $buffer_minutes . ' minutes');
    
    // Slot dopo (+60 minuti)
    $after_slot = clone $appointment_datetime;
    $after_slot->modify('+' . $default_duration . ' minutes'); // Assumiamo 60 min per appuntamento
    
    // Verifica disponibilità prima
    if (isTimeSlotAvailable($appointmentData['zone_id'], $before_slot->format('Y-m-d'), $before_slot->format('H:i:s'))) {
        $available_slots[] = [
            'date' => $before_slot->format('Y-m-d'),
            'time' => $before_slot->format('H:i:s'),
            'type' => 'before',
            'related_appointment' => $appointmentData
        ];
    }
    
    // Verifica disponibilità dopo
    if (isTimeSlotAvailable($appointmentData['zone_id'], $after_slot->format('Y-m-d'), $after_slot->format('H:i:s'))) {
        $available_slots[] = [
            'date' => $after_slot->format('Y-m-d'),
            'time' => $after_slot->format('H:i:s'),
            'type' => 'after',
            'related_appointment' => $appointmentData
        ];
    }
    
    return $available_slots;
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

// Funzione per verificare se un orario è disponibile
function isTimeSlotAvailable($zone_id, $date, $time, $duration = 60) {
    global $conn;
    $start_datetime = $date . ' ' . $time;
    $end_datetime = date('Y-m-d H:i:s', strtotime($start_datetime . ' +' . $duration . ' minutes'));
    
    // Verifica se ci sono appuntamenti sovrapposti nella stessa zona
    $sql = "SELECT COUNT(*) FROM cp_appointments 
            WHERE zone_id = ? AND 
            ((appointment_date = ? AND appointment_time <= ? AND 
              DATE_ADD(CONCAT(appointment_date, ' ', appointment_time), INTERVAL 60 MINUTE) > ?) OR 
             (appointment_date = ? AND appointment_time >= ? AND 
              appointment_time < ?))";
                  $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }
    
    $stmt->bind_param("issssss", $zone_id, $date, $time, $start_datetime, $date, $time, $end_datetime);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    
    return ($count == 0);
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
function addAppointment($zoneId, $patientId, $appointmentDate, $appointmentTime, $address) {
    global $conn;
    $formattedDate = date('Y-m-d', strtotime($appointmentDate)); // Ensure correct date format
    $sql = "INSERT INTO cp_appointments (zone_id, patient_id, appointment_date, appointment_time, address) VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log("Database prepare failed for adding appointment: " . mysqli_error($conn));
        throw new Exception("Database prepare failed for adding appointment: " . mysqli_error($conn));
    }

    $stmt->bind_param("iisss", $zoneId, $patientId, $formattedDate, $appointmentTime, $address);

    if (!$stmt->execute()) {
        error_log("Database query failed for adding appointment: " . mysqli_error($conn));
        throw new Exception("Database query failed for adding appointment: " . mysqli_error($conn));
    }
}

// Gestione del POST per la ricerca di appuntamenti
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
        // Prima cerca appuntamenti vicini entro 7km
        $nearby_appointments = findNearbyAppointments($address, $latitude, $longitude, 7);
        
        //////STAMPA A SCHERMO BLOCCO INDIRIZZI CONFRONTATI////////

// Visualizza informazioni di debug
global $address_comparison_debug;
echo "<div class='container'>";
echo "<h3>Confronto indirizzi (Debug):</h3>";
echo "<table class='pure-table pure-table-bordered' style='margin: 0 auto; width: 100%; font-size: 14px;'>";
echo "<thead><tr><th>ID</th><th>Indirizzo appuntamento</th><th>Coordinate</th><th>Distanza</th><th>Stato</th></tr></thead>";
echo "<tbody>";

if (empty($address_comparison_debug)) {
    echo "<tr><td colspan='5'>Nessun indirizzo di appuntamento elaborato.</td></tr>";
} else {
    foreach ($address_comparison_debug as $item) {
        $rowClass = (strpos($item['status'], 'Entro raggio') !== false) ? "style='background-color:#d4edda'" : "";
        
        echo "<tr {$rowClass}>";
        echo "<td>{$item['id']}</td>";
        echo "<td>{$item['address']}</td>";
        echo "<td>{$item['coords']}</td>";
        echo "<td>{$item['distance']}</td>";
        echo "<td>" . ($item['error'] ? "<span style='color:red'>{$item['error']}</span>" : $item['status']) . "</td>";
        echo "</tr>";
    }
}

echo "</tbody></table>";
echo "</div><hr>";
         //////FINE STAMPA A SCHERMO BLOCCO INDIRIZZI CONFRONTATI////////

        
        $available_slots_near_appointments = [];
        
        // Per ogni appuntamento trovato vicino, verifica slot disponibili
        foreach ($nearby_appointments as $appointment) {
            $slots = checkAvailableSlotsNearAppointment($appointment);
            $available_slots_near_appointments = array_merge($available_slots_near_appointments, $slots);
        }
        
        // Mostra i risultati
        echo "<div class='container'><center><h2>Indirizzo: <span style='color:green; font-weight:700;'>{$address}</span></h2>";
        echo "<p>Coordinate dell'indirizzo: Latitudine={$latitude}, Longitudine={$longitude}</p></center></div><hr>";
        
        // Mostra appuntamenti trovati nel raggio (opzionale, per debug)
        if (!empty($nearby_appointments)) {
            echo "<div class='container'><center>";
            echo "<h3>Appuntamenti trovati nel raggio di 7km: " . count($nearby_appointments) . "</h3>";
            echo "</center></div>";
        }
        
        // Mostra gli slot disponibili vicini ad appuntamenti esistenti
        if (!empty($available_slots_near_appointments)) {
            echo "<div class='container'><center>";
            echo "<h3>Slot disponibili vicino ad altri appuntamenti (entro 7km)</h3>";
            
            foreach ($available_slots_near_appointments as $slot) {
                $slot_date = date('d/m/Y', strtotime($slot['date']));
                $slot_time = date('H:i', strtotime($slot['time']));
                $distance = number_format($slot['related_appointment']['distance'], 1);
                $slot_type = ($slot['type'] == 'before') ? '60 minuti prima' : '60 minuti dopo';
                
                echo "<div style='margin: 15px; padding: 10px; border-left: 5px solid #4CAF50; background-color: #f9f9f9;'>";
                echo "<h4>{$slot_date} {$slot_time}</h4>";
                echo "<p><strong>{$slot_type}</strong> dell'appuntamento in<br>";
                echo "{$slot['related_appointment']['address']}<br>";
                echo "<small>Distanza: {$distance} km</small></p>";
                
                //echo "<a href='book_appointment.php?zone_id={$slot['related_appointment']['zone_id']}&date={$slot['date']}&time={$slot['time']}&address=" . urlencode($address) . 
                 //    "&latitude={$latitude}&longitude={$longitude}&name={$name}&surname={$surname}&phone={$phone}' class='pure-button pure-button-primary'>Seleziona</a>";
                 
                 
echo "<a href='javascript:void(0)' onclick='return selectAppointment(\"{$slot['related_appointment']['zone_id']}\", \"{$slot['date']}\", \"{$slot['time']}\", \"" . urlencode($address) . "\", \"{$latitude}\", \"{$longitude}\", \"" . urlencode($name) . "\", \"" . urlencode($surname) . "\", \"" . urlencode($phone) . "\");' class='pure-button pure-button-primary'>Seleziona</a>";


// Aggiungi questo debug temporaneo per vedere i parametri:
echo "<!-- DEBUG: zone_id={$slot['related_appointment']['zone_id']}, date={$slot['date']}, time={$slot['time']}, address={$address}, lat={$latitude}, lng={$longitude}, name={$name}, surname={$surname}, phone={$phone} -->";
                 
                echo "</div>";
            }
            
            echo "</center></div><hr>";
        } else {
            echo "<div class='container'><center><p>Nessun appuntamento trovato entro 7km con slot disponibili.</p></center></div><hr>";
        }
        
        // Continua con la logica esistente per le zone
        $zones = getZonesFromCoordinates($latitude, $longitude);
        $origin = [$latitude, $longitude];

        // Debugging: Log the origin coordinates
        error_log("Origin coordinates: lat={$latitude}, lng={$longitude}");

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
                    echo "<div class='container'><center><h4>Appuntamenti disponibili per i prossimi 3 giorni per la zona <span style='color:green; font-weight:700;'>{$zone['name']}</span>:</h4>";
                    $next3Days = getNext3AppointmentDates($slots, $zone['id']);
                    foreach ($next3Days as $date => $times) {
                                                $formattedDisplayDate = strftime('%d %B %Y', strtotime($date)); // Change format for display
                        echo "<p style='margin-top:2rem; font-size:120%; font-weight:700;'>Data: {$formattedDisplayDate}</p>";
                        echo "<p>Fasce orarie disponibili: ";
                        foreach ($times as $time) {
                            $formattedTime = date('H:i', strtotime($time)); // Remove seconds
echo "<a href='javascript:void(0)' onclick='return selectAppointment(\"{$zone['id']}\", \"{$date}\", \"{$formattedTime}\", \"" . urlencode($address) . "\", \"{$latitude}\", \"{$longitude}\", \"" . urlencode($name) . "\", \"" . urlencode($surname) . "\", \"" . urlencode($phone) . "\");'>{$formattedTime}</a> ";
                        }
                        echo "</p>";
                    }
                    echo "</center></div><hr>";
                } else {
                    echo "<div class='container'><p>Nessun appuntamento disponibile per i prossimi 3 giorni per la zona {$zone['name']}.</p></div>";
                }
            }
        }

        if ($zonesFound) {
            $zoneText = implode(', ', $zoneNames);
            echo "<div class='container'><center><p style='margin-top:2rem; font-size:120%; font-weight:700;'>L'indirizzo appartiene alla zona <span style='color:green;'>{$zoneText}</span>.</p></center></div>";
        } else {
            echo "<div class='container'><center><p>L'indirizzo non si trova in nessuna zona.</p></center></div>";
        }
    } catch (Exception $e) {
        error_log("Exception: " . $e->getMessage());
        echo 'Si è verificato un errore: ' . $e->getMessage();
    }
    exit;
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
    $address = isset($_POST['address']) ? $_POST['address'] : '';

    try {
        if (isAppointmentAvailable($zoneId, $appointmentDate, $appointmentTime)) {
            $patientId = addPatient($name, $surname, $phone, $notes);
            addAppointment($zoneId, $patientId, $appointmentDate, $appointmentTime, $address);

            // Ensure correct date formatting
            $appointmentDateFormatted = strftime('%d %B %Y', strtotime($appointmentDate));
            $formattedAppointmentTime = date('H:i', strtotime($appointmentTime));

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
    <script>
function selectAppointment(zoneId, date, time, address, latitude, longitude, name, surname, phone) {
    console.log("selectAppointment chiamata con:", zoneId, date, time); // Debug
    
    // Popola i campi
    document.getElementById("zone_id").value = zoneId;
    document.getElementById("date").value = date;
    document.getElementById("time").value = time;
    document.getElementById("form_address").value = decodeURIComponent(address);
    document.getElementById("form_latitude").value = latitude;
    document.getElementById("form_longitude").value = longitude;
    document.getElementById("form_name").value = decodeURIComponent(name || '');
    document.getElementById("form_surname").value = decodeURIComponent(surname || '');
    document.getElementById("form_phone").value = decodeURIComponent(phone || '');
    
    // Mostra il form
    var appointmentForm = document.getElementById("appointmentForm");
    appointmentForm.style.display = "block";
    
    // Scroll al form
    appointmentForm.scrollIntoView({behavior: "smooth"});
    
    // Evidenzia brevemente il form
    appointmentForm.style.backgroundColor = "#ffffcc";
    setTimeout(function() {
        appointmentForm.style.backgroundColor = "";
    }, 1500);
    
    // Importante: previeni il comportamento predefinito del link
    return false;
}
</script>
    
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
        .etic{font-size:100%; font-weight:700;}
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
    function selectAppointment(zoneId, date, time, address, latitude, longitude, name, surname, phone) {
    // Popola il form con i dati dell'appuntamento selezionato
    document.getElementById("zone_id").value = zoneId;
    document.getElementById("date").value = date;
    document.getElementById("time").value = time;
    document.getElementById("form_address").value = address;
    document.getElementById("form_latitude").value = latitude;
    document.getElementById("form_longitude").value = longitude;
    document.getElementById("form_name").value = name;
    document.getElementById("form_surname").value = surname;
    document.getElementById("form_phone").value = phone;

    // Mostra il form di prenotazione
    document.getElementById("appointmentForm").style.display = "block";
    document.getElementById("appointmentForm").scrollIntoView({behavior: "smooth"});
    
    // Evidenzia il form con un'animazione
    document.getElementById("appointmentForm").classList.add("highlight");
    setTimeout(function() {
        document.getElementById("appointmentForm").classList.remove("highlight");
    }, 2000);
}
</script>

<style>
    /* Stile esistente... */
    
    /* Aggiungi questo stile per l'highlight */
    .highlight {
        animation: highlightAnimation 2s;
    }
    
    @keyframes highlightAnimation {
        0% { background-color: #ffff99; }
        100% { background-color: transparent; }
    }
</style>
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
    <div id="appointmentForm" style="display:none; margin-top: 20px; padding: 20px; border: 1px solid #ddd; border-radius: 5px; background-color: #f9f9f9;">
        <h2>Prenota Appuntamento</h2>
        <form method="POST" action="combined_address_calculate_v2.php" class="pure-form pure-form-stacked">
            <input type="hidden" id="zone_id" name="zone_id">
            <input type="hidden" id="date" name="date">
            <input type="hidden" id="time" name="time">
            
            <div style="display: flex; flex-wrap: wrap; gap: 15px;">
                <div style="flex: 1; min-width: 250px;">
                    <label for="form_name">Nome:</label>
                    <input type="text" id="form_name" name="name" style="width: 100%;" required>
                </div>
                <div style="flex: 1; min-width: 250px;">
                    <label for="form_surname">Cognome:</label>
                    <input type="text" id="form_surname" name="surname" style="width: 100%;" required>
                </div>
            </div>
            
            <div style="display: flex; flex-wrap: wrap; gap: 15px; margin-top: 15px;">
                <div style="flex: 1; min-width: 250px;">
                    <label for="form_phone">Telefono:</label>
                    <input type="text" id="form_phone" name="phone" style="width: 100%;" required>
                </div>
                <div style="flex: 1; min-width: 250px;">
                    <label for="form_address">Indirizzo:</label>
                    <input type="text" id="form_address" name="address" readonly style="width: 100%;">
                </div>
            </div>
            
            <input type="hidden" id="form_latitude" name="latitude">
            <input type="hidden" id="form_longitude" name="longitude">
            
            <div style="margin-top: 15px;">
                <label for="notes">Note:</label>
                <textarea id="notes" name="notes" rows="4" style="width: 100%;"></textarea>
            </div>
            
            <div style="margin-top: 20px; text-align: center;">
                <button type="submit" class="pure-button pure-button-primary" style="font-size: 120%; padding: 10px 30px;">Conferma Prenotazione</button>
            </div>
        </form>
    </div>
</div>
</body>
</html>
<?php
$output = ob_get_clean();
// Rimuovi il testo spurio "Current Date..." e "Current User's Login"
$lines = explode("\n", $output);
if (count($lines) > 0 && strpos($lines[0], "Current Date") !== false) {
    array_shift($lines);
}
if (count($lines) > 0 && strpos($lines[0], "Current User") !== false) {
    array_shift($lines);
}
echo implode("\n", $lines);
?>
