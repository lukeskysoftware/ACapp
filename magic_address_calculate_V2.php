<?php
// Inizia l'output buffering per gestire il testo spurio
ob_start();

// Inizializza errori e inclusioni
error_reporting(E_ALL);
ini_set('display_errors', 1);
include 'db.php';
include 'menu.php';
include 'utils_appointment.php';

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

   /**
 * Funzione per calcolare la distanza stradale tramite Google Maps API con firma digitale
 * @param float $origin_lat Latitudine dell'origine
 * @param float $origin_lng Longitudine dell'origine
 * @param float $dest_lat Latitudine della destinazione
 * @param float $dest_lng Longitudine della destinazione
 * @return float Distanza in km, -1 in caso di errore
 */
function calculateRoadDistance($originLat, $originLng, $destinationLat, $destinationLng) {
    global $conn;

    // $cacheKey = "distance_" . $originLat . "_" . $originLng . "_" . $destinationLat . "_" . $destinationLng;
    // The cache key is now distributed across multiple columns.

    // Check if the distance is already cached
    $sql = "SELECT distance FROM distance_cache WHERE origin_lat = ? AND origin_lng = ? AND dest_lat = ? AND dest_lng = ?";
    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        error_log("Errore nella preparazione della query (cache check): " . $conn->error);
        return false;
    }

    $stmt->bind_param("dddd", $originLat, $originLng, $destinationLat, $destinationLng);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        return (float)$row['distance']; // Return cached distance
    }

    // Retrieve Google Maps API key from config table
    $sql = "SELECT value FROM config WHERE name = 'GOOGLE_MAPS_API_KEY'";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $apiKey = $row['value'];
    } else {
        error_log("Errore: Impossibile recuperare la chiave API di Google Maps.");
        return false;
    }

    $origins = $originLat . "," . $originLng;
    $destinations = $destinationLat . "," . $destinationLng;

    $url = "https://maps.googleapis.com/maps/api/distancematrix/json?origins=" . urlencode($origins) . "&destinations=" . urlencode($destinations) . "&key=" . $apiKey;

    // Log the URL
    error_log("Distance Matrix API URL: " . $url);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);

    // Log the entire JSON response
    error_log("Distance Matrix API Response: " . $response);

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    error_log("Distance Matrix API HTTP Code: " . $httpCode);

    if ($response === false) {
        error_log('Errore cURL: ' . curl_error($ch));
        curl_close($ch);
        return false;
    }

    curl_close($ch);

    $data = json_decode($response, true);

    if ($data['status'] == 'OK') {
        $distance = $data['rows'][0]['elements'][0]['distance']['value'] / 1000; // in km

        // Cache the distance
        $sql = "INSERT INTO distance_cache (origin_lat, origin_lng, dest_lat, dest_lng, distance) VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);

        if ($stmt === false) {
            error_log("Errore nella preparazione della query (cache insert): " . $conn->error);
            return false;
        }

        $stmt->bind_param("ddddd", $originLat, $originLng, $destinationLat, $destinationLng, $distance);
        $stmt->execute();

        return $distance;
    } else {
        error_log("Errore nell'API Distance Matrix: " . $data['status'] . " - " . $data['error_message']);
        return false;
    }
}

/**
 * Funzione per firmare un URL con la chiave privata
 * @param string $url L'URL da firmare (path e query, senza dominio)
 * @param string $privateKey La chiave privata
 * @return string La firma URL-safe Base64
 */
function signUrlWithPrivateKey($url, $privateKey) {
    // Decodifica la chiave privata da base64 URL-safe a binario
    $decodedKey = base64_decode(strtr($privateKey, '-_', '+/'));

    // Genera la firma usando HMAC-SHA1
    $signature = hash_hmac('sha1', $url, $decodedKey, true);

    // Codifica la firma in base64 URL-safe
    $encodedSignature = strtr(base64_encode($signature), '+/', '-_');

    return $encodedSignature;
}


// Function to calculate distance between two coordinates (distanza euclidea, usata come fallback)
function calculateDistance($origin, $destination) {
    $earthRadiusKm = 6371;

    $dLat = deg2rad($destination[0] - $origin[0]);
    $dLng = deg2rad($destination[1] - $origin[1]);

    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($origin[0])) * cos(deg2rad($destination[0])) *
         sin($dLng/2) * sin($dLng/2);

    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    
    // Distanza euclidea
    $distance = $earthRadiusKm * $c;
    
    // Applicare un fattore di correzione per approssimare meglio la distanza stradale
    // Tipicamente, la distanza stradale è circa 1.3-1.5 volte la distanza euclidea
    $distanceCorrection = 1.4;
    $estimatedRoadDistance = $distance * $distanceCorrection;
    
    error_log("Distanza euclidea: $distance km, Distanza stradale stimata: $estimatedRoadDistance km");
    
    error_log("Distanza stimata: $estimatedRoadDistance km tra [$origin[0],$origin[1]] e [$destination[0],$destination[1]]");
    
    return $estimatedRoadDistance;
}

/**
 * Funzione per trovare appuntamenti vicini entro il raggio specificato
 * @param string $user_address Indirizzo dell'utente
 * @param float $user_latitude Latitudine dell'utente
 * @param float $user_longitude Longitudine dell'utente
 * @param float $radius_km Raggio in km (default 7)
 * @return array Array di appuntamenti vicini
 */
function findNearbyAppointments($user_latitude, $user_longitude, $radius_km = 7) {
    global $conn;
    $nearby_appointments = [];
    $debug_info = [];

    $sql = "SELECT * FROM cp_appointments";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $appointment_id = $row['id'];
            $address = trim($row['address']);
            $debug_item = [
                'id' => $appointment_id,
                'zone_id' => $row['zone_id'],
                'appointment_date' => $row['appointment_date'],
                'appointment_time' => $row['appointment_time'],
                'address' => $address,
            ];

            // Salta se l'indirizzo è vuoto
            if (empty($address)) {
                $debug_item['status'] = 'Saltato - Indirizzo vuoto';
                $debug_item['excluded_reason'] = 'Indirizzo vuoto';
                $debug_info[] = $debug_item;
                continue;
            }

            // Controlla la cache delle coordinate
            $cache_sql = "SELECT latitude, longitude FROM cp_address_cache WHERE appointment_id = ?";
            $cache_stmt = $conn->prepare($cache_sql);
            $cache_stmt->bind_param("i", $appointment_id);
            $cache_stmt->execute();
            $cache_result = $cache_stmt->get_result();

            if ($cache_result && $cache_result->num_rows > 0) {
                $cache_row = $cache_result->fetch_assoc();
                $coordinates = [
                    'lat' => $cache_row['latitude'],
                    'lng' => $cache_row['longitude']
                ];
                $debug_item['status'] = 'In cache';
                $debug_item['coords'] = "Lat: {$coordinates['lat']}, Lng: {$coordinates['lng']}";
            } else if ($cache_result) {
                // Geocodifica l'indirizzo e salvalo
                $coordinates = getCoordinatesFromAddress($address, $appointment_id);
                if ($coordinates) {
                    $debug_item['status'] = 'Geocodificato';
                    $debug_item['coords'] = "Lat: {$coordinates['lat']}, Lng: {$coordinates['lng']}";
                } else {
                    $debug_item['status'] = 'Geocodifica fallita';
                    $debug_item['excluded_reason'] = 'Impossibile ottenere coordinate';
                    $debug_info[] = $debug_item;
                    continue;
                }
            } else {
                $debug_item['status'] = 'Errore SQL cache';
                $debug_item['excluded_reason'] = mysqli_error($conn);
                $debug_info[] = $debug_item;
                continue;
            }

            // Calcola la distanza stradale
            $distance = calculateRoadDistance(
                $user_latitude, $user_longitude,
                $coordinates['lat'], $coordinates['lng']
            );

            if ($distance == -1) {
                $debug_item['status'] = 'Errore calcolo distanza';
                $debug_item['excluded_reason'] = 'Errore calcolo distanza stradale';
                $debug_info[] = $debug_item;
                error_log("Failed to calculate road distance for appointment ID: " . $appointment_id);
                continue;
            }

            /* DEBUG */
            $debug_item['distance'] = number_format($distance, 2) . " km";

            // Se la distanza è entro il raggio, aggiungi all'elenco
            if ($distance <= $radius_km) {
                $row['distance'] = $distance;
                $row['latitude'] = $coordinates['lat'];
                $row['longitude'] = $coordinates['lng'];
                $nearby_appointments[] = $row;
                /* DEBUG */
                $debug_item['status'] .= ' - Entro raggio';
                $debug_item['excluded_reason'] = '';
            } else {
                /* DEBUG */
                $debug_item['status'] .= ' - Fuori raggio';
                $debug_item['excluded_reason'] = 'Distanza > ' . $radius_km . ' km';
            }
            /* DEBUG */
            $debug_info[] = $debug_item;
        }
    }

    // Per debug globale
    global $address_comparison_debug;
    $address_comparison_debug = $debug_info;



    // Ordina gli appuntamenti per distanza
    usort($nearby_appointments, function($a, $b) {
        return $a['distance'] <=> $b['distance'];
    });

    // Salva le informazioni di debug in una variabile globale
    /* DEBUG*/
    global $address_comparison_debug;
    $address_comparison_debug = $debug_info;
    /**/
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
    $zone_id = $appointmentData['zone_id'];
    $duration = 60; // Default duration for zone_id=0
    $last_slot_time = null; // Per memorizzare l'ultimo slot configurato per il giorno
    
    // Ottieni il giorno della settimana dell'appuntamento (1-7, 1=Lun, 7=Dom)
    $appointment_date = $appointmentData['appointment_date'];
    $appointment_day_of_week = date('N', strtotime($appointment_date));

    // Traduci il giorno della settimana in inglese per il confronto nel database
    $day_names = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday'
    ];
    $day_name = $day_names[$appointment_day_of_week];
    
    // Ottieni l'ultimo slot configurato per questo giorno della settimana IN QUALSIASI ZONA
    $last_slot_sql = "SELECT MAX(time) as last_time FROM cp_slots WHERE day = ?";
    $last_slot_stmt = $conn->prepare($last_slot_sql);
    if ($last_slot_stmt) {
        $last_slot_stmt->bind_param("s", $day_name);
        $last_slot_stmt->execute();
        $last_slot_result = $last_slot_stmt->get_result();
        if ($last_slot_row = $last_slot_result->fetch_assoc()) {
            $last_slot_time = $last_slot_row['last_time'];
            error_log("Ultimo slot configurato per il giorno $day_name (in qualsiasi zona): $last_slot_time");
        }
    }
    
    // Calcola la durata dello slot per la zona specifica (se disponibile)
    if ($zone_id != 0) {
        $sql = "SELECT day, time FROM cp_slots WHERE zone_id = ? ORDER BY day, time";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $zone_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $times = [];
                while ($row = $result->fetch_assoc()) {
                    $slot_day_of_week = date('N', strtotime($row['day']));
                    if ($slot_day_of_week == $appointment_day_of_week) {
                        $times[] = $row['time'];
                    }
                }
                sort($times);
                if (count($times) >= 2) {
                    $first_time = strtotime($times[0]);
                    $second_time = strtotime($times[1]);
                    $duration_seconds = $second_time - $first_time;
                    $duration = $duration_seconds / 60; // in minuti
                    error_log("Durata calcolata dello slot per la zona $zone_id: $duration minuti");
                }
            }
        }
    }
    
    // Converti data e ora dell'appuntamento in oggetto DateTime
    $appointment_datetime = new DateTime($appointmentData['appointment_date'] . ' ' . $appointmentData['appointment_time']);
    $before_slot = clone $appointment_datetime;
    $before_slot->modify('-' . $duration . ' minutes');
    $after_slot = clone $appointment_datetime;
    $after_slot->modify('+' . $duration . ' minutes');
    
    // Verifica se lo slot dopo è successivo all'ultimo slot configurato
    $after_slot_exceeds_limit = false;
    if ($last_slot_time) {
        $last_slot_datetime = new DateTime($appointmentData['appointment_date'] . ' ' . $last_slot_time);
        if ($after_slot > $last_slot_datetime) {
            $after_slot_exceeds_limit = true;
            error_log("Lo slot dopo (" . $after_slot->format('H:i:s') . ") supera l'ultimo orario configurato ($last_slot_time) per il giorno $day_name");
        }
    }
    
    // Ottieni tutti gli appuntamenti del giorno per questa zona
    $sql = "SELECT id, appointment_time, address 
            FROM cp_appointments 
            WHERE zone_id = ? AND appointment_date = ?
            ORDER BY appointment_time";
    $stmt = $conn->prepare($sql);
    $appointmentDate = $appointmentData['appointment_date'];
    
    if ($stmt) {
        $stmt->bind_param("is", $zone_id, $appointmentDate);
        $stmt->execute();
        $result = $stmt->get_result();
        $appointments = $result->fetch_all(MYSQLI_ASSOC);
        
        // Trova l'indice dell'appuntamento corrente
        $current_index = -1;
        foreach ($appointments as $i => $app) {
            if ($app['id'] == $appointmentData['id']) {
                $current_index = $i;
                break;
            }
        }
        
        if ($current_index === -1) {
            error_log("Appuntamento non trovato nel set di appuntamenti del giorno");
            return $available_slots;
        }
        
        // Determina appuntamenti precedenti e successivi
        $prev_appointment = ($current_index > 0) ? $appointments[$current_index - 1] : null;
        $next_appointment = ($current_index < count($appointments) - 1) ? $appointments[$current_index + 1] : null;
        
        // --- PATCH: controllo slot PRIMA ---
        if ($current_index > 0 && (!$prev_appointment || isTimeSlotAvailable($zone_id, $before_slot->format('Y-m-d'), $before_slot->format('H:i:s')))) {
            $distance_constraint_met = true;
            $debug_info = [];
            if ($prev_appointment && !empty($prev_appointment['address'])) {
                $prev_coordinates = getCoordinatesFromAddress($prev_appointment['address'], $prev_appointment['id']);
                if ($prev_coordinates) {
                    $current_coordinates = getCoordinatesFromAddress($appointmentData['address'], $appointmentData['id']);
                    if ($current_coordinates) {
                        $distance = calculateRoadDistance(
                            $prev_coordinates['lat'], $prev_coordinates['lng'],
                            $current_coordinates['lat'], $current_coordinates['lng']
                        );
                        $debug_info = [
                            'prev_address' => $prev_appointment['address'],
                            'prev_coords' => $prev_coordinates,
                            'distance' => $distance
                        ];
                        if ($distance > 7) {
                            $distance_constraint_met = false;
                            error_log("Slot prima non disponibile: distanza dall'appuntamento precedente ($distance km) > 7 km");
                        }
                    } else {
                        $distance_constraint_met = false;
                        error_log("Slot prima non disponibile: impossibile ottenere coordinate dell'appuntamento corrente");
                    }
                } else {
                    $distance_constraint_met = false;
                    error_log("Slot prima non disponibile: impossibile ottenere coordinate dell'appuntamento precedente");
                }
            }
            // PATCH: controllo slot già occupato
            if ($distance_constraint_met) {
                $checkSql = "SELECT COUNT(*) as count FROM cp_appointments WHERE appointment_date = ? AND appointment_time = ?";
                $checkStmt = $conn->prepare($checkSql);
                $date = $before_slot->format('Y-m-d');
                $time = $before_slot->format('H:i:s');
                $checkStmt->bind_param("ss", $date, $time);
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result();
                $checkRow = $checkResult->fetch_assoc();
                $alreadyBooked = ($checkRow['count'] > 0);
                $checkStmt->close();

                if (!$alreadyBooked) {
                    $available_slots[] = [
                        'date' => $date,
                        'time' => $time,
                        'type' => 'before',
                        'related_appointment' => $appointmentData,
                        'debug_info' => $debug_info
                    ];
                }
            }
        }
        
        // --- PATCH: controllo slot DOPO ---
        if ((!$next_appointment || isTimeSlotAvailable($zone_id, $after_slot->format('Y-m-d'), $after_slot->format('H:i:s')))
            && !$after_slot_exceeds_limit) {
            $distance_constraint_met = true;
            $debug_info = [];
            if ($next_appointment && !empty($next_appointment['address'])) {
                $next_coordinates = getCoordinatesFromAddress($next_appointment['address'], $next_appointment['id']);
                if ($next_coordinates) {
                    $current_coordinates = getCoordinatesFromAddress($appointmentData['address'], $appointmentData['id']);
                    if ($current_coordinates) {
                        $distance = calculateRoadDistance(
                            $current_coordinates['lat'], $current_coordinates['lng'],
                            $next_coordinates['lat'], $next_coordinates['lng']
                        );
                        $debug_info = [
                            'next_address' => $next_appointment['address'],
                            'next_coords' => $next_coordinates,
                            'distance' => $distance
                        ];
                        if ($distance > 7) {
                            $distance_constraint_met = false;
                            error_log("Slot dopo non disponibile: distanza dall'appuntamento successivo ($distance km) > 7 km");
                        }
                    } else {
                        $distance_constraint_met = false;
                        error_log("Slot dopo non disponibile: impossibile ottenere coordinate dell'appuntamento corrente");
                    }
                } else {
                    $distance_constraint_met = false;
                    error_log("Slot dopo non disponibile: impossibile ottenere coordinate dell'appuntamento successivo");
                }
            }
            // PATCH: controllo slot già occupato
            if ($distance_constraint_met) {
                $checkSql = "SELECT COUNT(*) as count FROM cp_appointments WHERE appointment_date = ? AND appointment_time = ?";
                $checkStmt = $conn->prepare($checkSql);
                $date = $after_slot->format('Y-m-d');
                $time = $after_slot->format('H:i:s');
                $checkStmt->bind_param("ss", $date, $time);
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result();
                $checkRow = $checkResult->fetch_assoc();
                $alreadyBooked = ($checkRow['count'] > 0);
                $checkStmt->close();

                if (!$alreadyBooked) {
                    $available_slots[] = [
                        'date' => $date,
                        'time' => $time,
                        'type' => 'after',
                        'related_appointment' => $appointmentData,
                        'debug_info' => $debug_info
                    ];
                }
            }
        } else if ($after_slot_exceeds_limit) {
            error_log("Slot dopo (" . $after_slot->format('H:i:s') . ") escluso perché supera l'ultimo orario configurato");
        }
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

// Funzione aggiornata per verificare se un orario è disponibile
function isTimeSlotAvailable($zone_id, $date, $time, $duration = 60) {
    global $conn;
    $start_datetime = $date . ' ' . $time;
    $end_datetime = date('Y-m-d H:i:s', strtotime($start_datetime . ' +' . $duration . ' minutes'));
    
    // Prima di proporre uno slot, verificare che sia disponibile
    $checkQuery = "SELECT COUNT(*) as count FROM cp_appointments WHERE appointment_date = ? AND appointment_time = ?";
    $checkStmt = $conn->prepare($checkQuery);
    
    // Verifica se la preparazione della query è riuscita
    if (!$checkStmt) {
        error_log("Errore nella preparazione della query di verifica disponibilità: " . $conn->error);
        return false; // In caso di errore, consideriamo lo slot come non disponibile per sicurezza
    }
    
    $checkStmt->bind_param("ss", $date, $time);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $row = $checkResult->fetch_assoc();
    $isOccupied = ($row['count'] > 0);
    $checkStmt->close();
    
    if ($isOccupied) {
        error_log("Slot $date $time già occupato da un appuntamento esistente");
        return false;
    }
    
    // Verifica prima se lo slot rientra negli unavailable slots
    // Calcola l'orario di fine esplicitamente
    $endTime = date('H:i:s', strtotime($time . " +{$duration} minutes"));
    $availability = isSlotAvailable($date, $time, $endTime, $zone_id);
    
    if (!$availability['available']) {
        error_log("Slot non disponibile per blocco: $date $time-$endTime (zona $zone_id): " . $availability['reason']);
        return false;
    }
    
    // Verifica prima gli appuntamenti nella stessa zona
    $sql1 = "SELECT COUNT(*) FROM cp_appointments 
            WHERE zone_id = ? AND appointment_date = ? AND (
                (appointment_time <= ? AND ADDTIME(appointment_time, '01:00:00') > ?) OR 
                (appointment_time >= ? AND appointment_time < ?)
            )";
    
    $stmt1 = $conn->prepare($sql1);
    if (!$stmt1) {
        error_log("Errore nella preparazione della query isTimeSlotAvailable (stessa zona): " . $conn->error);
        return false;
    }
    
    $stmt1->bind_param("isssss", $zone_id, $date, $time, $start_datetime, $time, $end_datetime);
    $stmt1->execute();
    $stmt1->bind_result($count1);
    $stmt1->fetch();
    $stmt1->close();
    
    if ($count1 > 0) {
        error_log("Sovrapposizione temporale rilevata nella stessa zona: $date $time (zona $zone_id)");
        return false;
    }
    
    // Poi verifica appuntamenti in TUTTE le zone che siano a meno di 7km
    $sql2 = "SELECT a.id, a.zone_id, a.appointment_time, a.address 
            FROM cp_appointments a
            WHERE a.appointment_date = ? AND 
                  a.zone_id != ? AND
                  (a.appointment_time <= ? AND ADDTIME(a.appointment_time, '01:00:00') > ?) OR 
                  (a.appointment_time >= ? AND a.appointment_time < ?)";
    
    $stmt2 = $conn->prepare($sql2);
    if (!$stmt2) {
        error_log("Errore nella preparazione della query isTimeSlotAvailable (altre zone): " . $conn->error);
        return false;
    }
    
    $stmt2->bind_param("sissss", $date, $zone_id, $time, $start_datetime, $time, $end_datetime);
    $stmt2->execute();
    $result2 = $stmt2->get_result();
    
    // Verifica se qualcuno di questi appuntamenti è entro 7km dal punto corrente
    // Nota: questo richiederebbe di conoscere le coordinate dell'indirizzo corrente
    // Per adesso, consideriamo tutti gli appuntamenti temporalmente sovrapposti come non disponibili
    if ($result2->num_rows > 0) {
        error_log("Trovati {$result2->num_rows} appuntamenti sovrapposti in altre zone per $date $time");
        return false;
    }
    
    return true;
}
    


/**
 * Verifica se è possibile inserire un appuntamento in un dato orario
 * Controlla sia la disponibilità temporale che la distanza dagli appuntamenti adiacenti
 * 
 * @param int $zoneId ID della zona
 * @param string $appointmentDate Data dell'appuntamento (Y-m-d)
 * @param string $appointmentTime Orario dell'appuntamento (H:i:s)
 * @param string $address Indirizzo dell'appuntamento
 * @param float $latitude Latitudine dell'indirizzo
 * @param float $longitude Longitudine dell'indirizzo
 * @param int $buffer_minutes Minuti di buffer (default 60)
 * @return bool True se l'appuntamento può essere inserito, false altrimenti
 */
/**
 * Verifica se è possibile inserire un appuntamento in un dato orario
 * Controlla sia la disponibilità temporale che la distanza dagli appuntamenti adiacenti
 * 
 * @param int $zoneId ID della zona
 * @param string $appointmentDate Data dell'appuntamento (Y-m-d)
 * @param string $appointmentTime Orario dell'appuntamento (H:i:s)
 * @param string $address Indirizzo dell'appuntamento
 * @param float $latitude Latitudine dell'indirizzo
 * @param float $longitude Longitudine dell'indirizzo
 * @param int $buffer_minutes Minuti di buffer (default 60)
 * @return bool True se l'appuntamento può essere inserito, false altrimenti
 */
function isAppointmentAvailable($zoneId, $appointmentDate, $appointmentTime, $address, $latitude, $longitude, $buffer_minutes = 60) {
    global $conn;
    
    // Verifica innanzitutto la disponibilità dello slot rispetto agli unavailable slots
    $endTime = date('H:i:s', strtotime($appointmentTime . " +1 hour"));
    $availability = isSlotAvailable($appointmentDate, $appointmentTime, $endTime, $zoneId);
    if (!$availability['available']) {
        error_log("Appuntamento non disponibile: " . $availability['reason']);
        return false;
    }
    
    // Converti data e orario in oggetto DateTime
    $appointmentDateTime = new DateTime($appointmentDate . ' ' . $appointmentTime);
    
    // STEP 1: Calcola la durata dello slot in base alla configurazione della zona
    $slotDuration = $buffer_minutes; // Default se non è possibile calcolare
    
    // Ottieni gli slot configurati per questa zona
    $slotsSql = "SELECT day, time FROM cp_slots WHERE zone_id = ? ORDER BY day, time";
    $slotsStmt = $conn->prepare($slotsSql);
    
    if ($slotsStmt) {
        $slotsStmt->bind_param("i", $zoneId);
        $slotsStmt->execute();
        $slotsResult = $slotsStmt->get_result();
        
        // Raggruppa gli slot per giorno della settimana
        $slotsByDay = [];
        while ($row = $slotsResult->fetch_assoc()) {
            $dayOfWeek = date('N', strtotime($row['day'])); // 1-7 (Lun-Dom)
            if (!isset($slotsByDay[$dayOfWeek])) {
                $slotsByDay[$dayOfWeek] = [];
            }
            $slotsByDay[$dayOfWeek][] = $row['time'];
        }
        
        // Calcola la durata tra slot consecutivi
        $appointmentDayOfWeek = date('N', strtotime($appointmentDate));
        if (isset($slotsByDay[$appointmentDayOfWeek]) && count($slotsByDay[$appointmentDayOfWeek]) >= 2) {
            $daySlots = $slotsByDay[$appointmentDayOfWeek];
            sort($daySlots); // Assicurati che siano ordinati
            
            // Calcola la differenza tra i primi due slot
            $firstSlot = strtotime("2000-01-01 " . $daySlots[0]);
            $secondSlot = strtotime("2000-01-01 " . $daySlots[1]);
            $slotDuration = ($secondSlot - $firstSlot) / 60; // Durata in minuti
            
            error_log("Durata calcolata dello slot per la zona $zoneId: $slotDuration minuti");
        }
    }
        // STEP 2: Ottieni tutti gli appuntamenti per la data specificata
        $sql = "SELECT id, appointment_time, address 
        FROM cp_appointments 
        WHERE zone_id = ? AND appointment_date = ? 
        ORDER BY appointment_time";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("Database prepare failed: " . mysqli_error($conn));
    return false;
}

$stmt->bind_param("is", $zoneId, $appointmentDate);
if (!$stmt->execute()) {
    error_log("Database query failed: " . mysqli_error($conn));
    return false;
}

$result = $stmt->get_result();
$appointments = $result->fetch_all(MYSQLI_ASSOC);

// Se non ci sono appuntamenti, lo slot è disponibile
if (empty($appointments)) {
    return true;
}

// STEP 3: Trova gli appuntamenti immediatamente precedenti e successivi all'orario richiesto
$prevAppointment = null;
$nextAppointment = null;

foreach ($appointments as $appointment) {
    $appTime = $appointmentDate . ' ' . $appointment['appointment_time'];
    
    if ($appTime < $appointmentDate . ' ' . $appointmentTime) {
        $prevAppointment = $appointment;
    } else {
        $nextAppointment = $appointment;
        break;
    }
}

// STEP 4: Verifica la disponibilità temporale

// Verifica rispetto all'appuntamento precedente
if ($prevAppointment) {
    $prevDateTime = new DateTime($appointmentDate . ' ' . $prevAppointment['appointment_time']);
    $prevEndTime = clone $prevDateTime;
    $prevEndTime->modify("+{$slotDuration} minutes");
    
    // Se l'appuntamento richiesto inizia prima della fine dell'appuntamento precedente + durata
    if ($appointmentDateTime <= $prevEndTime) {
        error_log("Appuntamento non disponibile: sovrapposizione temporale con appuntamento precedente");
        return false;
    }
}

// Verifica rispetto all'appuntamento successivo
if ($nextAppointment) {
    $nextDateTime = new DateTime($appointmentDate . ' ' . $nextAppointment['appointment_time']);
    $requestedEndTime = clone $appointmentDateTime;
    $requestedEndTime->modify("+{$slotDuration} minutes");
    
    // Se la fine dell'appuntamento richiesto è dopo l'inizio dell'appuntamento successivo
    if ($requestedEndTime >= $nextDateTime) {
        error_log("Appuntamento non disponibile: sovrapposizione temporale con appuntamento successivo");
        return false;
    }
}

// STEP 5: Verifica la distanza geografica con gli appuntamenti adiacenti

// Verifica distanza dall'appuntamento precedente
if ($prevAppointment && !empty($prevAppointment['address'])) {
    $prevCoordinates = getCoordinatesFromAddress($prevAppointment['address'], $prevAppointment['id']);
    
    if ($prevCoordinates) {
        // Usa la distanza stradale invece di quella in linea d'aria
        $distance = calculateRoadDistance(
            $latitude, $longitude,
            $prevCoordinates['lat'], $prevCoordinates['lng']
        );

        if ($distance == -1) {
            error_log("Failed to calculate road distance for prev appointment");
            return false;
        }
        
        if ($distance > 7) {
            error_log("Appuntamento non disponibile: distanza stradale dall'appuntamento precedente ($distance km) > 7 km");
            return false;
        }
    }
}

// Verifica distanza dall'appuntamento successivo
if ($nextAppointment && !empty($nextAppointment['address'])) {
    $nextCoordinates = getCoordinatesFromAddress($nextAppointment['address'], $nextAppointment['id']);
    
    if ($nextCoordinates) {
        // Usa la distanza stradale invece di quella in linea d'aria
        $distance = calculateRoadDistance(
            $latitude, $longitude,
            $nextCoordinates['lat'], $nextCoordinates['lng']
        );
        if ($distance == -1) {
            error_log("Failed to calculate road distance for next appointment");
            return false;
        }
        
        if ($distance > 7) {
            error_log("Appuntamento non disponibile: distanza stradale dall'appuntamento successivo ($distance km) > 7 km");
            return false;
        }
    }
}

// Se tutti i controlli sono passati, l'appuntamento è disponibile
return true;
}

// Helper function to get coordinates from address
function getAddressCoordinates($address, $appointment_id = null) {
// Questa funzione può utilizzare la cache o chiamare getCoordinatesFromAddress
// Qui riutilizziamo la funzione esistente
return getCoordinatesFromAddress($address, $appointment_id);
}
// Modifica la funzione getNext3AppointmentDates per utilizzare le nuove funzioni
function getNext3AppointmentDates($slots, $zoneId, $userLatitude = null, $userLongitude = null) {
    global $conn;
    $next3Days = [];
    $currentDate = new DateTime();
    $iterationCount = 0;

    // Aggiungi log per debug
    error_log("INIZIO getNext3AppointmentDates per zona ID: " . $zoneId);

    while (count($next3Days) < 3 && $iterationCount < 14) {
        // Esamina i giorni successivi (fino a 28 giorni = 4 settimane)
        $checkDayOfWeek = null; // Initialize $checkDayOfWeek
        for ($dayOffset = $iterationCount * 7; $dayOffset < ($iterationCount + 1) * 7; $dayOffset++) {
            $checkDate = clone $currentDate;
            $checkDate->modify("+$dayOffset days");
            $checkDayOfWeek = $checkDate->format('N'); // 1-7 (Lun-Dom)
            $formattedDate = $checkDate->format('Y-m-d');

            error_log("Controllo data: " . $formattedDate . " (giorno della settimana: " . $checkDayOfWeek . ")");

            // Verifica se questa data è disponibile negli unavailable slots
            // Passa null per gli orari perché stiamo verificando l'intera giornata
            $dateAvailability = isSlotAvailable($formattedDate, null, null, $zoneId);

            if (!$dateAvailability['available']) {
                error_log("Data " . $formattedDate . " saltata: " . $dateAvailability['reason']);
                continue;
            }

            // NUOVA FUNZIONALITÀ: Verifica se esistono appuntamenti per questa data in qualsiasi zona
            $existingAppsSql = "SELECT a.id, a.address FROM cp_appointments a WHERE a.appointment_date = ?";
            $existingStmt = $conn->prepare($existingAppsSql);
            $existingStmt->bind_param("s", $formattedDate);
            $existingStmt->execute();
            $existingResult = $existingStmt->get_result();
            $existingAppCount = $existingResult->num_rows;

            error_log("Data " . $formattedDate . " - Numero di appuntamenti esistenti: " . $existingAppCount);

            // Se esistono appuntamenti e abbiamo coordinate dell'utente, verifica la distanza
            if ($existingAppCount > 0 && $userLatitude !== null && $userLongitude !== null) {
                if ($existingAppCount == 1) {
                    // Caso: UN SOLO appuntamento in questa data
                    $app = $existingResult->fetch_assoc();
                    $appAddress = $app['address'];
                    $appId = $app['id'];

                    error_log("Data " . $formattedDate . " - UN SOLO appuntamento - ID: " . $appId . ", Indirizzo: " . $appAddress);

                    $appCoords = getCoordinatesFromAddress($appAddress, $appId);

                    if ($appCoords) {
                        $distance = calculateRoadDistance(
                            $userLatitude, $userLongitude,
                            $appCoords['lat'], $appCoords['lng']
                        );

                        error_log("Data " . $formattedDate . " - Appuntamento ID " . $appId . " - Distanza: " . $distance . " km");

                        if ($distance > 7) {
                            error_log("Data " . $formattedDate . " saltata: UNICO appuntamento esistente a più di 7km (" . $distance . " km)");
                            continue; // Skip this date
                        } else {
                            error_log("Data " . $formattedDate . " - UNICO appuntamento esistente entro 7km (" . $distance . " km)");
                        }
                    } else {
                        error_log("Data " . $formattedDate . " saltata: impossibile ottenere coordinate per l'UNICO appuntamento");
                        continue; // Skip this date if coordinates can't be obtained
                    }
                } else {
                    // Caso: MULTIPLI appuntamenti in questa data
                    $allWithinRadius = true;
                    $existingResult->data_seek(0); // Reset the result pointer

                    while ($app = $existingResult->fetch_assoc()) {
                        $appCoords = getCoordinatesFromAddress($app['address'], $app['id']);

                        if ($appCoords) {
                            $distance = calculateRoadDistance(
                                $userLatitude, $userLongitude,
                                $appCoords['lat'], $appCoords['lng']
                            );

                            error_log("Data " . $formattedDate . " - Appuntamento ID " . $app['id'] . " - Distanza: " . $distance . " km");

                            if ($distance > 7) {
                                $allWithinRadius = false;
                                error_log("Data " . $formattedDate . " saltata: appuntamento esistente a più di 7km");
                                break;
                            }
                        } else {
                            $allWithinRadius = false;
                            error_log("Data " . $formattedDate . " saltata: impossibile ottenere coordinate");
                            break;
                        }
                    }
                    if (!$allWithinRadius) {
                        continue; // Skip this date if not all appointments are within radius
                    }
                }
            }  else {
                error_log("Data " . $formattedDate . " - Nessun appuntamento esistente.");
            }

            // Filtra gli slot configurati per questo giorno della settimana per la zona specifica
            if ($checkDayOfWeek !== null){
                $daySlots = array_filter($slots, function($slot) use ($checkDayOfWeek) {
                    $slotDayOfWeek = date('N', strtotime($slot['day']));
                    return $slotDayOfWeek == $checkDayOfWeek;
                });

                if (empty($daySlots)) {
                    error_log("Nessuno slot configurato per il giorno " . $checkDayOfWeek);
                    continue;
                }

                // Estrai solo gli orari configurati
                $configuredTimes = [];
                foreach ($daySlots as $slot) {
                    $configuredTimes[] = $slot['time'];
                }

                // Verificare ogni orario configurato
                $availableSlots = [];
                foreach ($configuredTimes as $slotTime) {
                    // Se la data è oggi e l'ora è passata, salta
                    if ($formattedDate == date('Y-m-d') && $slotTime <= date('H:i:s')) {
                        continue;
                    }

                    // Calcola l'orario di fine (1 ora dopo l'inizio)
                    // IMPORTANTE: Questo risolve il problema principale
                    $endTime = date('H:i:s', strtotime($slotTime . " +1 hour"));

                    // Verifica se questo slot è disponibile negli unavailable slots
                    // Ora passiamo sia start_time che end_time
                    $slotAvailability = isSlotAvailable($formattedDate, $slotTime, $endTime, $zoneId);

                    if ($slotAvailability['available']) {
                        // Continua con il resto delle verifiche esistenti...

                        // MODIFICA: Ottieni TUTTI gli appuntamenti per questa data, indipendentemente dalla zona
                        $sql = "SELECT appointment_time, zone_id FROM cp_appointments
                                WHERE appointment_date = ?
                                ORDER BY appointment_time";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("s", $formattedDate);
                        $stmt->execute();
                        $result = $stmt->get_result();

                        $bookedTimes = [];
                        while ($row = $result->fetch_assoc()) {
                            // PATCH: normalizza sempre a H:i:s
                            $bookedTimes[] = date('H:i:s', strtotime($row['appointment_time']));
                        }
                        $slotTimeNormalized = date('H:i:s', strtotime($slotTime));

                        // Controlla se questo slot è già prenotato in qualsiasi zona
                        if (!in_array($slotTimeNormalized, $bookedTimes)) {
                            // Controlla se lo slot è troppo vicino a uno prenotato (entro 60 minuti)
                            $slotTimestamp = strtotime($formattedDate . ' ' . $slotTimeNormalized);
                            $tooClose = false;

                            foreach ($bookedTimes as $bookedTime) {
                                $bookedTimestamp = strtotime($formattedDate . ' ' . $bookedTime);
                                $diffMinutes = abs(($slotTimestamp - $bookedTimestamp) / 60);

                                if ($diffMinutes < 60) {
                                    $tooClose = true;
                                    break;
                                }
                            }

                            if (!$tooClose) {
                                $availableSlots[] = $slotTime;
                            }
                        }
                    } else {
                        // Log per debug
                        error_log("Slot " . $formattedDate . " " . $slotTime . " non disponibile: " . $slotAvailability['reason']);
                    }
                }

                // Se ci sono almeno 2 slot disponibili, aggiungi questa data
                if (count($availableSlots) >= 2) {
                    $next3Days[$formattedDate] = $availableSlots;

                    if (count($next3Days) >= 3) {
                        break; // Abbiamo raggiunto le 3 date
                    }
                }
            }
        }

        $iterationCount++;
    }

    // Ordina per data
    ksort($next3Days);

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
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['address'])) {
    // Verifica che latitude e longitude siano presenti e validi prima di procedere
    if (!isset($_POST['latitude']) || !isset($_POST['longitude']) || 
        empty($_POST['latitude']) || empty($_POST['longitude'])) {
        
        echo '<div class="container"><div class="alert alert-danger">';
        echo '<h3><i class="bi bi-exclamation-triangle-fill"></i> Errore</h3>';
        echo '<p>Impossibile procedere senza coordinate geografiche.</p>';
        echo '<p>Per favore, torna indietro e seleziona un indirizzo dal menu a discesa per ottenere le coordinate.</p>';
        echo '<p><a href="javascript:history.back()" class="btn btn-primary">Torna indietro</a></p>';
        echo '</div></div>';
        exit;
    }

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
        
        // Verifica se l'indirizzo corrente ha già un appuntamento
        $existingAppointmentForAddress = null;
        $checkAddressSql = "SELECT a.id, a.appointment_date, a.appointment_time, p.name, p.surname 
                          FROM cp_appointments a
                          JOIN cp_patients p ON a.patient_id = p.id
                          WHERE a.address = ? AND a.appointment_date >= CURDATE()
                          ORDER BY a.appointment_date, a.appointment_time
                          LIMIT 1";
        $checkAddressStmt = $conn->prepare($checkAddressSql);
        $checkAddressStmt->bind_param("s", $address);
        $checkAddressStmt->execute();
        $existingResult = $checkAddressStmt->get_result();
        
        if ($existingResult->num_rows > 0) {
            $existingAppointmentForAddress = $existingResult->fetch_assoc();
        }
        
        // Mostra l'avviso se esiste già un appuntamento per questo indirizzo
        if ($existingAppointmentForAddress) {
            $appDate = date('d/m/Y', strtotime($existingAppointmentForAddress['appointment_date']));
            $appTime = date('H:i', strtotime($existingAppointmentForAddress['appointment_time']));
            $appId = $existingAppointmentForAddress['id'];
            $patientName = $existingAppointmentForAddress['name'] . ' ' . $existingAppointmentForAddress['surname'];
            
            echo "<div class='container' style='margin-bottom: 30px;'>";
            echo "<div class='alert alert-danger' style='font-size: 1.2em; padding: 20px; text-align: center;'>";
            echo "<h3 style='color: #721c24;'><i class='bi bi-exclamation-triangle-fill'></i> ATTENZIONE: Per questo indirizzo esiste già un appuntamento!</h3>";
            echo "<p>Paziente: <strong>{$patientName}</strong></p>";
            echo "<p>Data: <strong>{$appDate}</strong> alle <strong>{$appTime}</strong></p>";
            
            // Pulsante Vedi Agenda
            echo "<button class='btn btn-info btn-lg' type='button' data-bs-toggle='collapse' data-bs-target='#existingAppAgenda' aria-expanded='false' aria-controls='existingAppAgenda' style='margin-right: 10px;'>";
            echo "<i class='bi bi-calendar'></i> Vedi Agenda";
            echo "</button>";
            
            // Pulsante Modifica Appuntamento che reindirizza alla pagina corretta
            echo "<a href='manage_appointments.php?find_appointment={$appId}' class='btn btn-warning btn-lg'>";
            echo "<i class='bi bi-pencil-square'></i> Modifica Appuntamento";
            echo "</a>";
            
            // Div collassabile per l'agenda
            echo "<div class='collapse mt-3' id='existingAppAgenda'>";
            echo "<div class='card card-body' id='existingAppAgendaContent'>";
            echo "<div class='text-center'>";
            echo "<div class='spinner-border text-primary' role='status'>";
            echo "<span class='visually-hidden'>Caricamento appuntamenti...</span>";
            echo "</div>";
            echo "<p>Caricamento appuntamenti...</p>";
            echo "</div>";
            echo "</div>";
            echo "</div>";
            
            echo "</div></div>";
            
            // Script per caricare dinamicamente l'agenda e aprire la finestra di modifica
            echo "<script>
            document.addEventListener('DOMContentLoaded', function() {
                // Funzione per caricare l'agenda quando si clicca su 'Vedi Agenda'
                const agendaElement = document.querySelector('#existingAppAgenda');
                if (agendaElement) {
                    agendaElement.addEventListener('shown.bs.collapse', function() {
                        fetch('get_appointments_modal.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: 'appointment_date=" . $existingAppointmentForAddress['appointment_date'] . "'
                        })
                        .then(function(response) {
                            if (!response.ok) throw new Error('Errore di rete');
                            return response.text();
                        })
                        .then(function(html) {
                            document.getElementById('existingAppAgendaContent').innerHTML = html;
                        })
                        .catch(function(error) {
                            document.getElementById('existingAppAgendaContent').innerHTML = '<div class=\"alert alert-danger\"><p>Si è verificato un errore: ' + error.message + '</p></div>';
                        });
                    });
                }
            });

                
                // Funzione per aprire la pagina di modifica dell'appuntamento
                function openModifyAppointment(appointmentId) {
                    // Prima creiamo un form nascosto
                    var form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'manage_appointments.php';
                    form.style.display = 'none';
                    
                    // Aggiungiamo un campo nascosto con l'ID dell'appuntamento
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'open_edit_appointment';
                    input.value = appointmentId;
                    form.appendChild(input);
                    
                    // Aggiungiamo il form al body e lo inviamo
                    document.body.appendChild(form);
                    form.submit();
                }
            </script>";
        }
        $available_slots_near_appointments = [];
        
        // Per ogni appuntamento trovato vicino, verifica slot disponibili
        foreach ($nearby_appointments as $appointment) {
            $slots = checkAvailableSlotsNearAppointment($appointment);
            $available_slots_near_appointments = array_merge($available_slots_near_appointments, $slots);
        }
        
        // Mostra i risultati
        echo "<div class='container'><center><h2>Indirizzo: <span style='color:green; font-weight:700;'>{$address}</span></h2>";
        echo "<p>Coordinate dell'indirizzo: Latitudine={$latitude}, Longitudine={$longitude}</p></center></div><hr>";
       
       
        // Funzione di debug per visualizzare i dettagli degli appuntamenti
function displayAppointmentDetails($appointments) {
    echo "<div class='container'><center>";
    echo "<h3>Dettagli degli appuntamenti considerati per gli slot disponibili:</h3>";
    echo "<table class='pure-table pure-table-bordered' style='margin: 0 auto; width: 100%; font-size: 14px;'>";
    echo "<thead><tr>
        <th>ID</th>
        <th>Zona</th>
        <th>Data</th>
        <th>Ora</th>
        <th>Distanza</th>
        <th>Primo Slot</th>
        <th>Ultimo Slot</th>
        <th>Motivo esclusione</th>
    </tr></thead>";
    echo "<tbody>";

    foreach ($appointments as $appointment) {
        // Ottieni il primo e l'ultimo slot per questa zona e data
        global $conn;
        $zone_id = $appointment['zone_id'];
        $date = $appointment['appointment_date'];

        $sql = "SELECT MIN(appointment_time) as first_time, MAX(appointment_time) as last_time 
                FROM cp_appointments 
                WHERE zone_id = ? AND appointment_date = ?";
        $stmt = $conn->prepare($sql);
if(!$stmt) {
    error_log("Errore prepare SQL: " . $conn->error . " - Query: $sql");
    $first_time = 'N/A';
    $last_time = 'N/A';
} else {
    $stmt->bind_param("is", $zone_id, $date);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $first_time = $row['first_time'] ?: 'N/A';
    $last_time = $row['last_time'] ?: 'N/A';
}
       

        echo "<tr>";
        echo "<td>{$appointment['id']}</td>";
        echo "<td>{$appointment['zone_id']}</td>";
        echo "<td>{$appointment['appointment_date']}</td>";
        echo "<td>{$appointment['appointment_time']}</td>";
        echo "<td>" . (isset($appointment['distance']) ? number_format($appointment['distance'], 2) . " km" : '') . "</td>";
        echo "<td>{$first_time}</td>";
        echo "<td>{$last_time}</td>";
        echo "<td>" . (isset($appointment['excluded_reason']) ? htmlspecialchars($appointment['excluded_reason']) : '') . "</td>";
        echo "</tr>";
    }

    echo "</tbody></table>";
    echo "</center></div><hr>";
}
        
       
       
        // Mostra appuntamenti trovati nel raggio (opzionale, per debug)
        if (!empty($nearby_appointments)) {
            echo "<div class='container'><center>";
            echo "<h3>Appuntamenti trovati nel raggio di 7km: " . count($nearby_appointments) . "</h3>";
            echo "</center></div>";
            displayAppointmentDetails($nearby_appointments);
        }
        
       
// Codice per la visualizzazione degli slot disponibili
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
        
        // Aggiungi le informazioni di debug sull'indirizzo precedente/successivo
        if (isset($slot['debug_info']) && !empty($slot['debug_info'])) {
            $debug = $slot['debug_info'];
            if ($slot['type'] == 'before' && isset($debug['prev_address'])) {
                echo "<p><small>Rispetto all'indirizzo precedente: " . $debug['prev_address'] . "<br>";
                echo "Distanza: " . number_format($debug['distance'], 1) . " km</small></p>";
            } elseif ($slot['type'] == 'after' && isset($debug['next_address'])) {
                echo "<p><small>Rispetto all'indirizzo successivo: " . $debug['next_address'] . "<br>";
                echo "Distanza: " . number_format($debug['distance'], 1) . " km</small></p>";
            }
        }
        
        // MODIFICA: Rimosso il messaggio "Questo slot precede il primo appuntamento della giornata"
        // Il blocco if seguente è stato rimosso:
        // if ($slot['type'] == 'before' && $isFirstSlot) {
        //     echo "<p style='color:#FF0000; font-weight:bold; font-size:1.1em;'>Questo slot precede il primo appuntamento della giornata</p>";
        // }

        // Aggiungi il pulsante "Vedi agenda" 
        $date = $slot['date'];
        // Crea un ID univoco per il collapsible
        $collapseId = "collapse-slot-" . preg_replace('/[^a-zA-Z0-9]/', '', $date) . "-" . $slot['related_appointment']['zone_id'];
        $contentId = "agenda-content-slot-" . $collapseId;

        echo "<button class='btn btn-sm btn-outline-primary' type='button' data-bs-toggle='collapse' data-bs-target='#$collapseId' aria-expanded='false' aria-controls='$collapseId'>
            <i class='bi bi-calendar'></i> Vedi agenda
        </button>";

                // Aggiunta del div collassabile per i contenuti dell'agenda
                echo "<div class='collapse mb-3 mt-2' id='$collapseId'>
                    <div class='card card-body agenda-details' id='$contentId'>
                        <div class='text-center'>
                            <div class='spinner-border text-primary' role='status'>
                                <span class='visually-hidden'>Caricamento appuntamenti...</span>
                            </div>
                            <p>Caricamento appuntamenti...</p>
                        </div>
                    </div>
                </div>";

                // Script inline per caricare i contenuti
                echo "<script>
                    (function() {
                        var collapseEl = document.getElementById('$collapseId');
                        var contentEl = document.getElementById('$contentId');
                        var date = '$date';
                        var zoneId = {$slot['related_appointment']['zone_id']};
                        
                        if (collapseEl) {
                            collapseEl.addEventListener('shown.bs.collapse', function() {
                                fetch('get_appointments_modal.php', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/x-www-form-urlencoded',
                                    },
                                    body: 'appointment_date=' + date
                                })
                                    .then(function(response) {
                                        if (!response.ok) throw new Error('Errore di rete');
                                        return response.text();
                                    })
                                    .then(function(html) {
                                        contentEl.innerHTML = html;
                                    })
                                    .catch(function(error) {
                                        contentEl.innerHTML = '<div class=\"alert alert-danger\">' +
                                            '<p>Si è verificato un errore: ' + error.message + '</p>' +
                                            '<button class=\"btn btn-sm btn-outline-danger\" onclick=\"reloadAgenda(\\'$contentId\\', \\'$date\\', ' + zoneId + ')\">' +
                                            '<i class=\"bi bi-arrow-clockwise\"></i> Riprova' +
                                            '</button>' +
                                        '</div>';
                                    });
                            });
                        }
                    })();
                </script>";

                // Continue with the booking link...
                $nameEncoded = !empty($name) ? urlencode($name) : '';
                $surnameEncoded = !empty($surname) ? urlencode($surname) : '';
                $phoneEncoded = !empty($phone) ? urlencode($phone) : '';
                $addressEncoded = urlencode($address);

                echo "<br><a href='book_appointment.php?zone_id={$slot['related_appointment']['zone_id']}&date={$slot['date']}&time={$slot_time}";
                echo "&address={$addressEncoded}&latitude={$latitude}&longitude={$longitude}";
                echo "&name={$nameEncoded}&surname={$surnameEncoded}&phone={$phoneEncoded}";
                echo "' class='btn btn-success mt-2 fw-bold'>Seleziona</a>";

                echo "</div>";
            }
            echo "</center></div><hr>";
        }else {
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
                            $next3Days = getNext3AppointmentDates($slots, $zone['id'], $latitude, $longitude);
                            
                            // Diamo feedback all'utente se non ci sono date disponibili
                            if (empty($next3Days)) {
                                echo "<div class='container'><center>";
                                echo "<p style='color:red;'>Non sono state trovate date con slot disponibili per la zona {$zone['name']}.</p>";
                                echo "</center></div>";
                            } else {
                                echo "<div class='container'><center><h4>Appuntamenti disponibili per i prossimi 3 giorni per la zona <span style='color:green; font-weight:700;'>{$zone['name']}</span>:</h4>";
                                
                                foreach ($next3Days as $date => $times) {
                                    $formattedDisplayDate = strftime('%d %B %Y', strtotime($date)); // Change format for display
                                    
                                    // MODIFICA: Verifica tutti gli appuntamenti per questa data in TUTTE le zone, non solo in questa zona specifica
                                    $existingAppsSql = "SELECT COUNT(*) as count FROM cp_appointments 
                                                      WHERE appointment_date = ?"; // Rimosso il filtro per zone_id
                                    $existingStmt = $conn->prepare($existingAppsSql);
                                    $existingStmt->bind_param("s", $date); // Passato solo la data, non la zona
                                    $existingStmt->execute();
                                    $existingResult = $existingStmt->get_result();
                                    $existingRow = $existingResult->fetch_assoc();
                                    $hasExistingAppointments = ($existingRow['count'] > 0);
                                    
                                    echo "<p style='margin-top:2rem; font-size:120%; font-weight:700;'>Data: {$formattedDisplayDate}";

// Add an indicator if there are existing appointments
if ($hasExistingAppointments) {
    echo " <span style='font-size:80%; color:#ff9900;'>(ci sono altri appuntamenti in questa data)</span>";
    
    // Crea un ID univoco per il collapsible
    $collapseId = "collapse-" . preg_replace('/[^a-zA-Z0-9]/', '', $date) . "-" . $zone['id'];
    $contentId = "agenda-content-" . $collapseId;
    
    // Mostra il pulsante "Vedi agenda" SOLO se ci sono appuntamenti
    echo " <button class='btn btn-sm btn-outline-primary' type='button' data-bs-toggle='collapse' data-bs-target='#$collapseId' aria-expanded='false' aria-controls='$collapseId'>
        <i class='bi bi-calendar'></i> Vedi agenda
    </button>";
    
    // Aggiunta del div collassabile per i contenuti dell'agenda
    echo "</p>";
    echo "<div class='collapse mb-3' id='$collapseId'>
        <div class='card card-body agenda-details' id='$contentId'>
            <div class='text-center'>
                <div class='spinner-border text-primary' role='status'>
                    <span class='visually-hidden'>Caricamento appuntamenti...</span>
                </div>
                <p>Caricamento appuntamenti...</p>
            </div>
        </div>
    </div>";
    
    // Script inline per caricare i contenuti
    echo "<script>
        (function() {
            var collapseEl = document.getElementById('$collapseId');
            var contentEl = document.getElementById('$contentId');
            var date = '$date';
            var zoneId = {$zone['id']};
            
            if (collapseEl) {
                collapseEl.addEventListener('shown.bs.collapse', function() {
                    fetch('get_appointments_modal.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'appointment_date=' + date
                    })
                        .then(function(response) {
                            if (!response.ok) throw new Error('Errore di rete');
                            return response.text();
                        })
                        .then(function(html) {
                            contentEl.innerHTML = html;
                        })
                        .catch(function(error) {
                            contentEl.innerHTML = '<div class=\"alert alert-danger\">' +
                                '<p>Si è verificato un errore: ' + error.message + '</p>' +
                                '<button class=\"btn btn-sm btn-outline-danger\" onclick=\"reloadAgenda(\\'$contentId\\', \\'$date\\', ' + zoneId + ')\">' +
                                '<i class=\"bi bi-arrow-clockwise\"></i> Riprova' +
                                '</button>' +
                            '</div>';
                        });
                });
            }
        })();
    </script>";
} else {
    // Chiudi il paragrafo se non ci sono appuntamenti
    echo "</p>";
    // Non inserire nessuno script qui perché le variabili non sono definite
}

// Rimuovi queste righe duplicate che stanno causando gli errori
// echo "</p>";
// echo "<div class='collapse mb-3' id='$collapseId'>
//     <div class='card card-body agenda-details' id='$contentId'>
//         <div class='text-center'>
//             <div class='spinner-border text-primary' role='status'>
//                 <span class='visually-hidden'>Caricamento appuntamenti...</span>
//             </div>
//             <p>Caricamento appuntamenti...</p>
//         </div>
//     </div>
// </div>";

// Rimuovi anche questo script che usa le variabili non definite
// echo "<script>
//     (function() {
//         var collapseEl = document.getElementById('$collapseId');
//         var contentEl = document.getElementById('$contentId');
//         var date = '$date';
//         var zoneId = {$zone['id']};
//         
//         if (collapseEl) {
//             collapseEl.addEventListener('shown.bs.collapse', function() {
//                 fetch('get_appointments_modal.php', {
//                     method: 'POST',
//                     headers: {
//                         'Content-Type': 'application/x-www-form-urlencoded',
//                     },
//                     body: 'appointment_date=' + date
//                 })
//                     .then(function(response) {
//                         if (!response.ok) throw new Error('Errore di rete');
//                         return response.text();
//                     })
//                     .then(function(html) {
//                         contentEl.innerHTML = html;
//                     })
//                     .catch(function(error) {
//                         contentEl.innerHTML = '<div class=\"alert alert-danger\">' +
//                             '<p>Si è verificato un errore: ' + error.message + '</p>' +
//                             '<button class=\"btn btn-sm btn-outline-danger\" onclick=\"reloadAgenda(\\'$contentId\\', \\'$date\\', ' + zoneId + ')\">' +
//                             '<i class=\"bi bi-arrow-clockwise\"></i> Riprova' +
//                             '</button>' +
//                         '</div>';
//                     });
//             });
//         }
//     })();
// </script>";
                                    
                                    echo "</p>";
                                    if (empty($times)) {
                                        echo "<p>Nessuna fascia oraria disponibile per questa data.</p>";
                                    } else {
                                        echo "<p>Fasce orarie disponibili: ";
                                        foreach ($times as $time) {
                                            $formattedTime = date('H:i', strtotime($time)); // Remove seconds
                                            
                                            $nameEncoded = !empty($name) ? urlencode($name) : '';
                                            $surnameEncoded = !empty($surname) ? urlencode($surname) : '';
                                            $phoneEncoded = !empty($phone) ? urlencode($phone) : '';
                                            $addressEncoded = urlencode($address);
                                            
                                            echo "<a href='book_appointment.php?zone_id={$zone['id']}&date={$date}&time={$formattedTime}";
                                            echo "&address={$addressEncoded}&latitude={$latitude}&longitude={$longitude}";
                                            echo "&name={$nameEncoded}&surname={$surnameEncoded}&phone={$phoneEncoded}";
                                            echo "' class='btn btn-success m-1 fw-bold'>{$formattedTime}</a>";
                                        }
                                        echo "</p>";
                                    }
                                }
                                echo "</center></div>";
                            }
                            echo "</center></div><hr>";
                        } else {
                            // Non mostrare nulla per zone senza slot configurati
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
        
        if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['zone_id']) && isset($_POST['date']) && isset($_POST['time']) && isset($_POST['name']) && isset($_POST['surname']) && isset($_POST['phone']) && isset($_POST['address'])) {
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
                if (isAppointmentAvailable($zoneId, $appointmentDate, $appointmentTime, $address, $_POST['latitude'], $_POST['longitude'])) {
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
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Calcolo Indirizzo</title>
            <!-- Bootstrap CSS -->
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
            <!-- Bootstrap Icons -->
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.min.css">
            <!-- Bootstrap Bundle with Popper -->
            <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
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
            
                .pure-button-primary {
                    background-color: #4CAF50 !important;
                    color: white !important;
                    font-weight: bold !important;
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
        
        // Funzione per ricaricare l'agenda
        function reloadAgenda(contentId, date, zoneId) {
            var contentEl = document.getElementById(contentId);
            contentEl.innerHTML = '<div class="text-center"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Caricamento appuntamenti...</span></div><p>Caricamento appuntamenti...</p></div>';
            
            fetch('get_appointments_modal.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'appointment_date=' + date
            })
                .then(function(response) {
                    if (!response.ok) throw new Error('Errore di rete');
                    return response.text();
                })
                .then(function(html) {
                    contentEl.innerHTML = html;
                })
                .catch(function(error) {
                    contentEl.innerHTML = '<div class="alert alert-danger">' +
                        '<p>Si è verificato un errore: ' + error.message + '</p>' +
                        '<button class="btn btn-sm btn-outline-danger" onclick="reloadAgenda(\'' + contentId + '\', \'' + date + '\', ' + zoneId + ')">' +
                        '<i class="bi bi-arrow-clockwise"></i> Riprova' +
                        '</button>' +
                    '</div>';
                });
        }
    </script>
    
    
    
    
    <?php
// Keep the existing head content, then add our script at the end of the head section (just before </head>)

// Add these CSS and JavaScript to the head section (around line 1000, inside the <head> tag)
?>
<!-- Bootstrap and Calendar Resources -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.8.1/font/bootstrap-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

<style>
    .agenda-button {
        background-color: #fd7e14;
        color: #fff;
        border: none;
        margin-left: 10px;
        padding: 5px 10px;
        border-radius: 4px;
        cursor: pointer;
        display: inline-block;
        text-decoration: none;
    }
    .agenda-button:hover {
        background-color: #e8590c;
        color: #fff;
    }
    .appointment-time {
        font-weight: bold;
        font-size: 1.2rem;
    }
    .appointment-details {
        margin-bottom: 15px;
        padding: 10px;
        border-bottom: 1px solid #ddd;
    }
    .modal-xl {
        max-width: 90%;
    }
    .name, .surname {
        font-weight: bold;
    }
    .no-appointments {
        text-align: center;
        padding: 20px;
        color: #6c757d;
    }
</style>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    // Quando si clicca sul pulsante "Vedi agenda"
    $(document).on('click', '.vedi-agenda-btn', function() {
        // Ottieni la data dal data-attribute del pulsante
        var appointmentDate = $(this).data('date');
        console.log("Data selezionata:", appointmentDate); // debug
        
        $('#toggle-appointments-container').html('<div class="loading">Caricamento appuntamenti...</div>');
        $('#toggle-appointments-container').show();
        
        $.ajax({
            type: "POST",
            url: "get_appointments_modal.php",
            data: { appointment_date: appointmentDate },
            success: function(response) {
                $('#toggle-appointments-container').html(response);
            },
            error: function(xhr, status, error) {
                console.error("Errore:", error);
                $('#toggle-appointments-container').html('<div class="error-message">Errore durante il caricamento.</div>');
            }
        });
    });
});
</script>


<?php // End of added head content ?>
</head>
<body>
   
<div class="container">
    <h2>A quale indirizzo fare la visita?</h2>
    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-6"> <!-- Sarà al 100% su mobile, ~60% su desktop -->
            <form id="addressForm" method="POST" action="magic_address_calculate_V2.php" class="mb-4">
                <div class="mb-3">
                    <label for="address" class="form-label fw-bold">Indirizzo:</label>
                    <input type="text" id="address" name="address" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label for="latitude" class="form-label fw-bold">Latitudine:</label>
                    <input type="text" id="latitude" name="latitude" class="form-control" readonly>
                </div>
                <div class="mb-3">
                    <label for="longitude" class="form-label fw-bold">Longitudine:</label>
                    <input type="text" id="longitude" name="longitude" class="form-control" readonly>
                </div>
                <input type="hidden" id="name" name="name" value="<?php echo htmlspecialchars($name); ?>">
                <input type="hidden" id="surname" name="surname" value="<?php echo htmlspecialchars($surname); ?>">
                <input type="hidden" id="phone" name="phone" value="<?php echo htmlspecialchars($phone); ?>">
                <button type="submit" class="btn btn-primary">Avanti</button>
            </form>
        </div>
    </div>
    <div id="coordinates" style="margin-top: 10px;"></div>
    <div id="messageContainer" style="display:none;"></div>
    <a href="dashboard.php">Torna alla dashboard</a>
</div>





    <div class="container">
        <div id="appointmentForm" style="display:none; margin-top: 20px; padding: 20px; border: 1px solid #ddd; border-radius: 5px; background-color: #f9f9f9;">
            <h2>Prenota Appuntamento</h2>
            <form method="POST" action="magic_address_calculate_V2.php" class="pure-form pure-form-stacked">
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

<script>
document.getElementById('addressForm').addEventListener('submit', function(e) {
    var lat = document.getElementById('latitude').value;
    var lng = document.getElementById('longitude').value;
    
    if (!lat || !lng) {
        e.preventDefault();
        alert('Impossibile procedere senza coordinate geografiche. Per favore, seleziona un indirizzo dal menu a discesa per ottenere le coordinate.');
        return false;
    }
});
</script>


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
