<?php
// Inizia l'output buffering per gestire il testo spurio
ob_start();

// Inizializza errori e inclusioni
error_reporting(E_ALL);
ini_set('display_errors', 1);
include 'db.php';
include 'menu.php';
include 'utils_appointment.php';

// NUOVA COSTANTE
define('MAX_OPERATOR_HOP_KM', 15); // Esempio: max 15km tra appuntamenti consecutivi dell'operatore

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
$address = isset($_GET['address']) ? $_GET['address'] : '';
// Precompila lat/lng se address presente e trovato in address_cache
$prefill_lat = '';
$prefill_lng = '';
if ($address) {
    $sql = "SELECT latitude, longitude FROM address_cache WHERE address = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("s", $address);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $prefill_lat = $row['latitude'];
            $prefill_lng = $row['longitude'];
        }
        $stmt->close();
    }
}

// Set locale to Italian
setlocale(LC_TIME, 'it_IT.UTF-8');


// Funzione batch che aggiorna la cache indirizzi per tutti gli appuntamenti futuri senza lat/lng
function batchUpdateAddressCache($conn) {
    $sql = "SELECT a.id, a.address
            FROM cp_appointments a
            LEFT JOIN address_cache c ON a.id = c.appointment_id
            WHERE (c.latitude IS NULL OR c.longitude IS NULL OR c.latitude = '' OR c.longitude = '')
              AND a.address != ''";
    $result = $conn->query($sql);
    $count = 0;
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            getCoordinatesFromAddress($row['address'], $row['id']);
            $count++;
        }
    } else {
    }
}



   /**
 * Funzione per calcolare la distanza stradale tramite Google Maps API con firma digitale
 * @param float $origin_lat Latitudine dell'origine
 * @param float $origin_lng Longitudine dell'origine
 * @param float $dest_lat Latitudine della destinazione
 * @param float $dest_lng Longitudine della destinazione
 * @return float Distanza in km, -1 in caso di errore
 */
/**
 * Funzione migliorata per calcolare la distanza stradale tra coordinate
 */
function calculateRoadDistance($originLat, $originLng, $destinationLat, $destinationLng) {
    global $conn;

    // Verificare che le coordinate siano numeri validi
    if (!is_numeric($originLat) || !is_numeric($originLng) || 
        !is_numeric($destinationLat) || !is_numeric($destinationLng)) {
        return false;
    }

    // Normalizzazione dei valori a float con 6 decimali di precisione
    $originLat = round((float)$originLat, 6);
    $originLng = round((float)$originLng, 6);
    $destinationLat = round((float)$destinationLat, 6);
    $destinationLng = round((float)$destinationLng, 6);

    // Verifica se è un calcolo di distanza dallo stesso punto
    if (abs($originLat - $destinationLat) < 0.0000001 && abs($originLng - $destinationLng) < 0.0000001) {
        return 0;
    }

    // Verifica se la distanza è già in cache (in entrambe le direzioni)
    $sql = "SELECT distance FROM distance_cache 
            WHERE (ABS(origin_lat - ?) < 0.000001 AND ABS(origin_lng - ?) < 0.000001 
                 AND ABS(dest_lat - ?) < 0.000001 AND ABS(dest_lng - ?) < 0.000001)
            OR (ABS(origin_lat - ?) < 0.000001 AND ABS(origin_lng - ?) < 0.000001 
                AND ABS(dest_lat - ?) < 0.000001 AND ABS(dest_lng - ?) < 0.000001)";
    
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        return false;
    }
    
    $stmt->bind_param("dddddddd", 
                      $originLat, $originLng, $destinationLat, $destinationLng,
                      $destinationLat, $destinationLng, $originLat, $originLng);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return (float)$row['distance'];
    }

    // Non trovato in cache, chiama l'API di Google Maps
    $apiKey = '';
    $sql = "SELECT value FROM config WHERE name = 'GOOGLE_MAPS_API_KEY'";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $apiKey = $row['value'];
    } else {
        return false;
    }

    $origins = $originLat . "," . $originLng;
    $destinations = $destinationLat . "," . $destinationLng;

    $url = "https://maps.googleapis.com/maps/api/distancematrix/json?origins=" . urlencode($origins) . 
           "&destinations=" . urlencode($destinations) . "&key=" . $apiKey;


    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15); // Timeout di 15 secondi per assicurare una risposta

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($response === false) {
        $error_message = curl_error($ch);
        curl_close($ch);
        return false;
    }

    curl_close($ch);
    $data = json_decode($response, true);

    if ($data['status'] == 'OK' && isset($data['rows'][0]['elements'][0]['status']) && 
        $data['rows'][0]['elements'][0]['status'] == 'OK' && 
        isset($data['rows'][0]['elements'][0]['distance']['value'])) {
        
        $distance = $data['rows'][0]['elements'][0]['distance']['value'] / 1000; // converti da metri a km
        
        // Salva in cache per uso futuro
        $sql = "INSERT INTO distance_cache (origin_lat, origin_lng, dest_lat, dest_lng, distance) 
                VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("ddddd", $originLat, $originLng, $destinationLat, $destinationLng, $distance);
            if ($stmt->execute()) {
            } else {
            }
        }
        
        return $distance;
    } else {
        // Gestione errore API
        $status = $data['status'] ?? 'Sconosciuto';
        $element_status = isset($data['rows'][0]['elements'][0]['status']) ? 
                         $data['rows'][0]['elements'][0]['status'] : 'Sconosciuto';
        
        
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
    
    
    
    return $estimatedRoadDistance;
}

function updateAddressZoneMap($address_cache_id, $lat, $lng) {
    global $conn;
    $conn->query("DELETE FROM address_cache_zone_map WHERE address_cache_id = $address_cache_id");
    $sql = "SELECT id, latitude, longitude, radius_km FROM cp_zones";
    $zones = $conn->query($sql);
    $zone_ids_trovate = [];
    if ($zones) {
        while ($z = $zones->fetch_assoc()) {
            $dist = calculateDistance([$lat, $lng], [$z['latitude'], $z['longitude']]);
            if ($dist <= $z['radius_km']) {
                $stmt = $conn->prepare("INSERT INTO address_cache_zone_map (address_cache_id, zone_id) VALUES (?, ?)");
                $stmt->bind_param("ii", $address_cache_id, $z['id']);
                $stmt->execute();
                $zone_ids_trovate[] = $z['id'];
            }
        }
    }

    // PATCH: aggiorna tutti gli appuntamenti con l'indirizzo associato a questo address_cache_id
    $sql_addr = "SELECT address FROM address_cache WHERE id = ? LIMIT 1";
    $stmt_addr = $conn->prepare($sql_addr);
    $stmt_addr->bind_param("i", $address_cache_id);
    $stmt_addr->execute();
    $res_addr = $stmt_addr->get_result();
    if ($row_addr = $res_addr->fetch_assoc()) {
        $address = $row_addr['address'];
        $zone_id_to_set = 0;
        if (!empty($zone_ids_trovate)) {
            $zone_id_to_set = $zone_ids_trovate[0];
        }
        $upd_stmt = $conn->prepare("UPDATE cp_appointments SET zone_id = ? WHERE address = ?");
        $upd_stmt->bind_param("is", $zone_id_to_set, $address);
        $upd_stmt->execute();
        $upd_stmt->close();
    }
    $stmt_addr->close();
}

function precalculateDistancesForDate($date) {
    global $conn;
    $stats = [
        'total_appointments' => 0,
        'pairs_processed' => 0,
        'calculated' => 0,
        'from_cache' => 0,
        'errors' => 0
    ];
    
    
    // Ottieni tutti gli appuntamenti per la data specificata
    $sql = "SELECT id, address FROM cp_appointments WHERE appointment_date = ? AND address != ''";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $appointments = [];
    while ($row = $result->fetch_assoc()) {
        $coords = getCoordinatesForAppointment($row['id'], $row['address']);
        if ($coords) {
            $appointments[] = [
                'id' => $row['id'],
                'address' => $row['address'],
                'lat' => $coords['lat'],
                'lng' => $coords['lng']
            ];
        }
    }
    
    $count = count($appointments);
    $stats['total_appointments'] = $count;
    
    // Calcola tutte le distanze tra coppie di appuntamenti
    for ($i = 0; $i < $count; $i++) {
        for ($j = $i + 1; $j < $count; $j++) {
            $stats['pairs_processed']++;
            
            $app1 = $appointments[$i];
            $app2 = $appointments[$j];
            
            // Verifica se già in cache
            $sql = "SELECT COUNT(*) as count FROM distance_cache 
                   WHERE (ABS(origin_lat - ?) < 0.000001 AND ABS(origin_lng - ?) < 0.000001 
                         AND ABS(dest_lat - ?) < 0.000001 AND ABS(dest_lng - ?) < 0.000001)
                   OR (ABS(origin_lat - ?) < 0.000001 AND ABS(origin_lng - ?) < 0.000001 
                       AND ABS(dest_lat - ?) < 0.000001 AND ABS(dest_lng - ?) < 0.000001)";
            
            $cache_stmt = $conn->prepare($sql);
            $cache_stmt->bind_param("dddddddd", 
                $app1['lat'], $app1['lng'], $app2['lat'], $app2['lng'],
                $app2['lat'], $app2['lng'], $app1['lat'], $app1['lng']
            );
            $cache_stmt->execute();
            $cache_row = $cache_stmt->get_result()->fetch_assoc();
            
            if ($cache_row['count'] > 0) {
                $stats['from_cache']++;
                continue;
            }
            
            // Calcola e salva la distanza
            $distance = calculateRoadDistance($app1['lat'], $app1['lng'], $app2['lat'], $app2['lng']);
            if ($distance !== false) {
                $stats['calculated']++;
            } else {
                $stats['errors']++;
            }
            
            // Breve pausa per rispettare limiti API
            usleep(200000); // 200ms
        }
    }
    
    return $stats;
}

// Esegui un aggiornamento automatico per la data odierna e futura
$auto_update_date = isset($_GET['preload_dates']) ? $_GET['preload_dates'] : false;
if ($auto_update_date) {
    $date = date('Y-m-d');
    precalculateDistancesForDate($date);
    
    // Opzionalmente, calcola anche per domani
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    precalculateDistancesForDate($tomorrow);
    
    echo json_encode(['status' => 'success', 'message' => "Precalcolo distanze completato per {$date} e {$tomorrow}"]);
    exit;
}
/**
 * Funzione per trovare appuntamenti vicini entro il raggio specificato.
 * Marca gli appuntamenti con un motivo di esclusione se non sono validi come riferimenti.
 * Gli appuntamenti vengono restituiti tutti, ma quelli con 'excluded_reason' non vuoto
 * non dovrebbero essere usati per generare slot adiacenti dalla logica chiamante.
 *
 * @param float $user_latitude Latitudine dell'utente
 * @param float $user_longitude Longitudine dell'utente
 * @param float $radius_km Raggio in km (default 3)
 * @return array Array di appuntamenti, ognuno con 'excluded_reason' popolato se non idoneo come riferimento.
 */
function findNearbyAppointments($user_latitude, $user_longitude, $radius_km = 3) {
    global $conn;
    $appointments_evaluated = []; 
    $debug_info_collection = []; 
    $today = date('Y-m-d');
    $now = date('H:i:s');

    // Ottieni per ogni zona gli ultimi appuntamenti della giornata
    $last_app_ids = [];
    $sql_zones = "SELECT DISTINCT zone_id FROM cp_appointments WHERE appointment_date >= ?";
    $stmt_zones = $conn->prepare($sql_zones);
    $stmt_zones->bind_param("s", $today);
    $stmt_zones->execute();
    $res_zones = $stmt_zones->get_result();
    while ($row = $res_zones->fetch_assoc()) {
        $zid = $row['zone_id'];
        if ($zid != 0) {
            $sql_last = "SELECT id FROM cp_appointments WHERE zone_id = ? AND appointment_date = ? ORDER BY appointment_time DESC LIMIT 1";
            $stmt_last = $conn->prepare($sql_last);
            $stmt_last->bind_param("is", $zid, $today);
            $stmt_last->execute();
            $res_last = $stmt_last->get_result();
            if ($row_last = $res_last->fetch_assoc()) {
                $last_app_ids[] = $row_last['id'];
            }
            $stmt_last->close();
        }
    }
    $stmt_zones->close();

    $sql = "SELECT * FROM cp_appointments WHERE appointment_date >= ? ORDER BY appointment_date, appointment_time";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    $stmt->bind_param("s", $today);
    $stmt->execute();
    $result = $stmt->get_result();
    $total_appointments = $result->num_rows;

    while ($db_row = $result->fetch_assoc()) {
        $current_appointment = $db_row;
        $appointment_id = $current_appointment['id'];
        $zone_id = $current_appointment['zone_id'];
        $app_time = $current_appointment['appointment_time'];
        $app_date = $current_appointment['appointment_date'];
        $address = trim($current_appointment['address']);
        $current_appointment['excluded_reason'] = '';

        // Orario passato (solo per oggi)
        if ($app_date == $today && strtotime($app_time) < strtotime($now)) {
            $current_appointment['excluded_reason'] = 'Rif. appuntamento: Orario già passato oggi.';
            $appointments_evaluated[] = $current_appointment;
            continue;
        }

        // MODIFICA: Validità slot usando TUTTE le zone per il giorno della settimana specifico
        $app_day_of_week = date('l', strtotime($app_date));
        $day_limits_all_zones = getDaySpecificTimeLimits($app_day_of_week, true); // true = cerca in tutte le zone
        
        if ($day_limits_all_zones && $day_limits_all_zones['min_time'] && $day_limits_all_zones['max_time']) {
            if (strtotime($app_time) < strtotime($day_limits_all_zones['min_time']) || strtotime($app_time) > strtotime($day_limits_all_zones['max_time'])) {
                $current_appointment['excluded_reason'] = "Rif. appuntamento: Orario {$app_time} fuori dagli slot operativi per {$app_day_of_week} (range globale: {$day_limits_all_zones['min_time']} - {$day_limits_all_zones['max_time']}).";
                $appointments_evaluated[] = $current_appointment;
                continue;
            }
        } else {
            $current_appointment['excluded_reason'] = "Rif. appuntamento: Nessun slot configurato per {$app_day_of_week} in tutte le zone.";
            $appointments_evaluated[] = $current_appointment;
            continue;
        }

        if (empty($address)) {
            $current_appointment['excluded_reason'] = 'Rif. appuntamento: Indirizzo mancante.';
            $appointments_evaluated[] = $current_appointment;
            continue;
        }

        $coordinates = getCoordinatesForAppointment($appointment_id, $address);
        if (!$coordinates) {
            $current_appointment['excluded_reason'] = 'Rif. appuntamento: Impossibile recuperare coordinate.';
            $appointments_evaluated[] = $current_appointment;
            continue;
        }
        $current_appointment['latitude'] = $coordinates['lat'];
        $current_appointment['longitude'] = $coordinates['lng'];

        $distance = calculateRoadDistance($user_latitude, $user_longitude, $coordinates['lat'], $coordinates['lng']);
        if ($distance === false) {
            $current_appointment['excluded_reason'] = 'Rif. appuntamento: Errore API calcolo distanza.';
            $appointments_evaluated[] = $current_appointment;
            continue;
        }
        $current_appointment['distance'] = $distance;

        // --- ECCEZIONE: se è l'ultimo appuntamento della giornata nella sua zona, NON escludere per distanza ---
        if ($distance > $radius_km && !in_array($appointment_id, $last_app_ids)) {
            $current_appointment['excluded_reason'] = "Rif. appuntamento: Distanza dal luogo di ricerca (" . number_format($distance, 1) . " km) supera il raggio impostato (" . $radius_km . " km)";
        }
        $appointments_evaluated[] = $current_appointment;
    }

    // Ordinamento per data e ora
    usort($appointments_evaluated, function($a, $b) {
        $adate = $a['appointment_date'] . ' ' . $a['appointment_time'];
        $bdate = $b['appointment_date'] . ' ' . $b['appointment_time'];
        return strcmp($adate, $bdate);
    });

    return $appointments_evaluated;
}

// Funzione per ottenere il nome del giorno della settimana in italiano
function giornoSettimana($data) {
    $giorni = ['Domenica', 'Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato'];
    $ts = strtotime($data);
    return $giorni[date('w', $ts)];
}

// Per la tabella dettagli appuntamenti
function displayAppointmentDetails($reference_appointments, $all_calculated_adjacent_slots = []) {
    global $conn; 

    echo "<div class='container' style='text-align: center; margin-bottom: 20px;'>";
    echo "<h3>Dettagli degli appuntamenti di riferimento considerati:</h3>";
    echo "<style>
        .appointment-details-table {
            margin: 0 auto; width: 100%; max-width: 1200px; font-size: 13px;
            border-collapse: collapse; table-layout: auto;
        }
        .appointment-details-table th,
        .appointment-details-table td {
            border: 1px solid #ddd; padding: 8px; text-align: left; word-wrap: break-word;
        }
        .appointment-details-table th { background-color: #f2f2f2; font-weight: bold; }
        .appointment-details-table tr:nth-child(even) { background-color: #f9f9f9; }
        .col-id { width: 5%; } .col-zone-id { width: 7%; } .col-date { width: 12%; }
        .col-time { width: 10%; } .col-distance { width: 10%; } .col-limits { width: 26%; }
        .col-reason { width: 30%; }
        .highlight-no-adjacent-slots td { color: #007bff; }
        .blue-text { color: #007bff; }
        .slot-extra-warning { border:2px solid red; background: #fff6f6; }
    </style>";

    echo "<table class='pure-table pure-table-bordered appointment-details-table'>";
    echo "<thead><tr>
        <th class='col-id'>ID</th> <th class='col-zone-id'>Zona ID</th> <th class='col-date'>Data</th>
        <th class='col-time'>Ora</th> <th class='col-distance'>Distanza Utente</th>
        <th class='col-limits'>Limiti Slot Zona (Inizio Min-Max)</th>
        <th class='col-reason'>Stato Riferimento / Slot Adiacenti</th>
    </tr></thead>";
    echo "<tbody>";

    if (empty($reference_appointments)) {
        echo "<tr><td colspan='7' style='text-align:center;'>Nessun appuntamento di riferimento da analizzare.</td></tr>";
    } else {
        foreach ($reference_appointments as $ref_app) {
            $row_class = '';
            $display_reason = '';

            if (!empty($ref_app['excluded_reason'])) {
                $display_reason = htmlspecialchars($ref_app['excluded_reason']);
            } else {
                $related_slots_for_this_ref = array_filter($all_calculated_adjacent_slots, function($slot) use ($ref_app) {
                    return isset($slot['related_appointment']['id']) && $slot['related_appointment']['id'] == $ref_app['id'];
                });

                $bookable_adjacent_found = false;
                $extra_slot_html = "";

                if (empty($related_slots_for_this_ref)) {
                    // Come sopra, gestione slot mancanti
                    $zone_id = isset($ref_app['zone_id']) ? $ref_app['zone_id'] : 0;
                    if ($zone_id == 0) {
                        $display_reason = "<span class='blue-text'>Non utilizzabile: zona non definita (ID zona = 0)</span>";
                    } else {
                        // Query slot zona
                        $slots_sql = "SELECT COUNT(*) as count FROM cp_slots WHERE zone_id = ?";
                        $slots_stmt = $conn->prepare($slots_sql);
                        $slots_stmt->bind_param("i", $zone_id);
                        $slots_stmt->execute();
                        $slots_result = $slots_stmt->get_result();
                        $slots_row = $slots_result->fetch_assoc();
                        if ($slots_row['count'] == 0) {
                            $display_reason = "<span class='blue-text'>Non utilizzabile: nessun orario operativo configurato per la zona {$zone_id}</span>";
                        } else {
                            $display_reason = "<span class='blue-text'>Non utilizzabile: verifica limiti orari della zona {$zone_id}</span>";
                        }
                        $slots_stmt->close();
                    }
                    $row_class = 'highlight-no-adjacent-slots'; 
                } else {
                    // SLOT ADIACENTI VALUTATI
                    $motivi_esclusione = [];
                    foreach ($related_slots_for_this_ref as $adj_slot) {
                        // Se è uno slot extra (tipo after_extra), mostra la cornice rossa
                        if (isset($adj_slot['type']) && $adj_slot['type'] === 'after_extra' && isset($adj_slot['extra_warning'])) {
                            $extra_slot_html .= "<div class='slot-extra-warning' style='padding:10px; margin-bottom:8px;'>";
                            $extra_slot_html .= "<b style='color:red;'>OPPORTUNITÀ EXTRA: {$adj_slot['extra_warning']}</b><br>";
                            $extra_slot_html .= "Data: ".htmlspecialchars($adj_slot['date'])." Ora: ".htmlspecialchars($adj_slot['time']);
                            $extra_slot_html .= "</div>";
                        } else if (isset($adj_slot['excluded']) && !$adj_slot['excluded']) {
                            $bookable_adjacent_found = true;
                        } else if (isset($adj_slot['excluded_reason']) && !empty($adj_slot['excluded_reason'])) {
                            $type = isset($adj_slot['type']) ? ucfirst($adj_slot['type']) : 'Slot';
                            $time = isset($adj_slot['time']) ? date('H:i', strtotime($adj_slot['time'])) : '';
                            $reason = $adj_slot['excluded_reason'];
                            $motivi_esclusione[] = "{$type} ({$time}): {$reason}";
                        }
                    }
                    if ($bookable_adjacent_found) {
                        $display_reason = 'Rif. valido, slot adiacenti disponibili.';
                    } else {
                        if (!empty($motivi_esclusione)) {
                            $display_reason = "<span class='blue-text'>Motivi esclusione degli slot adiacenti:</span><br>";
                            $display_reason .= "<span class='blue-text'>" . implode("<br>", $motivi_esclusione) . "</span>";
                            $row_class = 'highlight-no-adjacent-slots';
                        } else {
                            $display_reason = "<span class='blue-text'>Slot adiacenti non disponibili (motivo non specificato)</span>";
                            $row_class = 'highlight-no-adjacent-slots';
                        }
                    }
                }

                // Se presenti, aggiungi subito la visualizzazione degli slot extra
                if ($extra_slot_html) {
                    $display_reason = $extra_slot_html . $display_reason;
                }
            }

            $zone_id = $ref_app['zone_id'] ?? ($ref_app['related_appointment']['zone_id'] ?? 0);
            $display_limits_text = "";
            if ($zone_id != 0) {
                $slots_limit_sql = "SELECT MIN(time) as earliest_slot, MAX(time) as latest_slot FROM cp_slots WHERE zone_id = ?";
                $slots_limit_stmt = $conn->prepare($slots_limit_sql);
                if ($slots_limit_stmt) {
                    $slots_limit_stmt->bind_param("i", $zone_id);
                    $slots_limit_stmt->execute();
                    $slots_limit_result = $slots_limit_stmt->get_result();
                    if ($slots_row = $slots_limit_result->fetch_assoc()) {
                        if ($slots_row['earliest_slot'] !== null && $slots_row['latest_slot'] !== null) {
                            $display_limits_text = htmlspecialchars("Slot da {$slots_row['earliest_slot']} a {$slots_row['latest_slot']}");
                        } else { $display_limits_text = htmlspecialchars("Default (Nessuno slot per Zona {$zone_id})"); }
                    } else { $display_limits_text = htmlspecialchars("Errore query limiti slot"); }
                    $slots_limit_stmt->close();
                } else { $display_limits_text = htmlspecialchars("Errore prep. query limiti slot"); }
            } else { $display_limits_text = htmlspecialchars("N/A (Zona ID 0)"); }

            echo "<tr class='{$row_class}'>";
            echo "<td class='col-id'>" . (isset($ref_app['id']) ? htmlspecialchars($ref_app['id']) : 'N/D') . "</td>";
            echo "<td class='col-zone-id'>" . htmlspecialchars($zone_id) . "</td>";
            echo "<td class='col-date'>" . (isset($ref_app['appointment_date']) ? htmlspecialchars($ref_app['appointment_date']) : 'N/D') . "</td>";
            echo "<td class='col-time'>" . (isset($ref_app['appointment_time']) ? htmlspecialchars($ref_app['appointment_time']) : 'N/D') . "</td>";
            echo "<td class='col-distance'>" . (isset($ref_app['distance']) ? htmlspecialchars(number_format($ref_app['distance'], 2)) . " km" : 'N/D') . "</td>";
            echo "<td class='col-limits'>" . $display_limits_text . "</td>";
            echo "<td class='col-reason'>" . $display_reason . "</td>";
            echo "</tr>";
        }
    }

    echo "</tbody></table>";
    echo "</div><hr>";
}


// Funzione per ottenere il nome del giorno della settimana in italiano
/*function giornoSettimana($data) {
    $giorni = ['Domenica', 'Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato'];
    $ts = strtotime($data);
    return $giorni[date('w', $ts)];
}*/

// Negli slot proposti: aggiungi il giorno della settimana prima della data (es: "Mercoledì 2025-05-21")
// Supponiamo che la stampa degli slot proposti sia tipo:
function displayProposedSlots($appointments) {
    // Solo slot validi (excluded_reason vuoto)
    foreach ($appointments as $a) {
        if (!empty($a['excluded_reason'])) continue;
        $giorno = giornoSettimana($a['appointment_date']);
        echo "<div class='slot'>";
        echo "<b>{$giorno} {$a['appointment_date']}</b> — {$a['appointment_time']}";
        if (isset($a['distance'])) {
            echo " (" . number_format($a['distance'],2) . " km)";
        }
        echo "</div>";
    }
}

// Funzione per ottenere coordinate da un indirizzo
/**
 * Funzione migliorata per recuperare le coordinate di un indirizzo
 * PRIORITÀ: 1. Cerca per appointment_id nella address_cache
 *           2. Cerca per indirizzo nella address_cache
 *           3. Solo se non trovato, chiedi a Google Maps
 */
function getCoordinatesForAppointment($appointment_id, $address = null) {
    global $conn;
    
    if (empty($appointment_id)) {
        return null;
    }
    
    
    // 1. Prima cerca nella cache usando l'ID appuntamento
    $sql = "SELECT latitude, longitude FROM address_cache WHERE appointment_id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $appointment_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            return ['lat' => $row['latitude'], 'lng' => $row['longitude']];
        }
    }
    
    // Se non abbiamo l'indirizzo, dobbiamo recuperarlo
    if (empty($address)) {
        $sql = "SELECT address FROM cp_appointments WHERE id = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $appointment_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $address = $row['address'];
            } else {
                return null;
            }
        }
    }
    
    // 2. Cerca nella cache usando l'indirizzo
    if (!empty($address)) {
        $sql = "SELECT latitude, longitude FROM address_cache WHERE address = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("s", $address);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                // Trovate coordinate, aggiorna la cache con appointment_id
                
                // Aggiorna la cache con il legame appointment_id -> address
                $updateSql = "INSERT INTO address_cache (appointment_id, address, latitude, longitude) 
                             VALUES (?, ?, ?, ?) 
                             ON DUPLICATE KEY UPDATE address = VALUES(address), latitude = VALUES(latitude), longitude = VALUES(longitude)";
                $updateStmt = $conn->prepare($updateSql);
                if ($updateStmt) {
                    $updateStmt->bind_param("isdd", $appointment_id, $address, $row['latitude'], $row['longitude']);
                    $updateStmt->execute();
                }
                
                return ['lat' => $row['latitude'], 'lng' => $row['longitude']];
            }
        }
    }
    
    // 3. Solo se non troviamo in cache, chiedi a Google Maps
    return getCoordinatesFromAddress($address, $appointment_id);
}

/**
 * Funzione per calcolare la distanza stradale usando esclusivamente l'API di Google
 * con caching ottimizzato per evitare chiamate ripetute per le stesse coordinate
 *//*
function calculateRoadDistance($originLat, $originLng, $destinationLat, $destinationLng) {
    global $conn;

    // Verificare che le coordinate siano numeri validi
    if (!is_numeric($originLat) || !is_numeric($originLng) || 
        !is_numeric($destinationLat) || !is_numeric($destinationLng)) {
        return false;
    }

    // Normalizzazione dei valori a float con 6 decimali di precisione
    $originLat = round((float)$originLat, 6);
    $originLng = round((float)$originLng, 6);
    $destinationLat = round((float)$destinationLat, 6);
    $destinationLng = round((float)$destinationLng, 6);

    // Verifica se è un calcolo di distanza dallo stesso punto
    if (abs($originLat - $destinationLat) < 0.0000001 && abs($originLng - $destinationLng) < 0.0000001) {
        return 0;
    }

    // Verifica se la distanza è già in cache (in entrambe le direzioni)
    $sql = "SELECT distance FROM distance_cache 
            WHERE (ABS(origin_lat - ?) < 0.000001 AND ABS(origin_lng - ?) < 0.000001 
                 AND ABS(dest_lat - ?) < 0.000001 AND ABS(dest_lng - ?) < 0.000001)
            OR (ABS(origin_lat - ?) < 0.000001 AND ABS(origin_lng - ?) < 0.000001 
                AND ABS(dest_lat - ?) < 0.000001 AND ABS(dest_lng - ?) < 0.000001)";
    
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        return false;
    }
    
    $stmt->bind_param("dddddddd", 
                      $originLat, $originLng, $destinationLat, $destinationLng,
                      $destinationLat, $destinationLng, $originLat, $originLng);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return (float)$row['distance'];
    }

    // Non trovato in cache, chiama l'API di Google Maps
    $apiKey = '';
    $sql = "SELECT value FROM config WHERE name = 'GOOGLE_MAPS_API_KEY'";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $apiKey = $row['value'];
    } else {
        return false;
    }

    $origins = $originLat . "," . $originLng;
    $destinations = $destinationLat . "," . $destinationLng;

    $url = "https://maps.googleapis.com/maps/api/distancematrix/json?origins=" . urlencode($origins) . 
           "&destinations=" . urlencode($destinations) . "&key=" . $apiKey;


    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15); // Timeout di 15 secondi per assicurare una risposta

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($response === false) {
        $error_message = curl_error($ch);
        curl_close($ch);
        return false;
    }

    curl_close($ch);
    $data = json_decode($response, true);

    if ($data['status'] == 'OK' && isset($data['rows'][0]['elements'][0]['status']) && 
        $data['rows'][0]['elements'][0]['status'] == 'OK' && 
        isset($data['rows'][0]['elements'][0]['distance']['value'])) {
        
        $distance = $data['rows'][0]['elements'][0]['distance']['value'] / 1000; // converti da metri a km
        
        // Salva in cache per uso futuro
        $sql = "INSERT INTO distance_cache (origin_lat, origin_lng, dest_lat, dest_lng, distance) 
                VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("ddddd", $originLat, $originLng, $destinationLat, $destinationLng, $distance);
            if ($stmt->execute()) {
            } else {
            }
        }
        
        return $distance;
    } else {
        // Gestione errore API
        $status = $data['status'] ?? 'Sconosciuto';
        $element_status = isset($data['rows'][0]['elements'][0]['status']) ? 
                         $data['rows'][0]['elements'][0]['status'] : 'Sconosciuto';
        
        
        return false;
    }
}
*/

// Function to get zones from coordinates
function getZonesFromCoordinates($latitude, $longitude) {
    global $conn;
    $sql = "SELECT id, name, latitude, longitude, radius_km FROM cp_zones";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new Exception("Database prepare failed: " . mysqli_error($conn));
    }

    if (!$stmt->execute()) {
        throw new Exception("Database query failed: " . mysqli_error($conn));
    }

    $zones = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    return $zones;
}

/**
 * Verifica slot disponibili prima/dopo un appuntamento di riferimento (RefApp),
 * calcolando la distanza di viaggio per l'operatore per il nuovo slot.
 *
 * @param array $ref_appointmentData Dati dell'appuntamento di riferimento.
 * @param float $newUser_latitude Latitudine del NUOVO appuntamento da inserire.
 * @param float $newUser_longitude Longitudine del NUOVO appuntamento da inserire.
 * @param int $max_operator_hop_km Distanza massima consentita tra due appuntamenti consecutivi dell'operatore.
 * @param int $buffer_minutes Durata dello slot/buffer.
 * @return array Array di slot, ognuno con 'actual_travel_distance_for_new_slot'.
 */
function checkAvailableSlotsNearRef_v2($ref_appointmentData, $newUser_latitude, $newUser_longitude, $max_operator_hop_km, $buffer_minutes = 60) {
    global $conn;
    $available_slots_info = [];

    $zone_id_ref = isset($ref_appointmentData['zone_id']) ? (int)$ref_appointmentData['zone_id'] : 0;
    $ref_app_id = isset($ref_appointmentData['id']) ? (int)$ref_appointmentData['id'] : null;
    $ref_app_date_str = isset($ref_appointmentData['appointment_date']) ? $ref_appointmentData['appointment_date'] : null;
    $ref_app_time_str = isset($ref_appointmentData['appointment_time']) ? $ref_appointmentData['appointment_time'] : null;
    // Coordinate dell'appuntamento di riferimento (DEVONO essere presenti)
    $ref_app_lat = isset($ref_appointmentData['latitude']) ? (float)$ref_appointmentData['latitude'] : null;
    $ref_app_lng = isset($ref_appointmentData['longitude']) ? (float)$ref_appointmentData['longitude'] : null;

    if (!$ref_app_id || !$ref_app_date_str || !$ref_app_time_str || $zone_id_ref == 0 || $ref_app_lat === null || $ref_app_lng === null) {
        return [];
    }

    $duration_slot_minutes = (int)$buffer_minutes;

    // Recupera limiti slot della zona del RefApp (come nella tua funzione originale)
    $zone_actual_min_slot_time = null; $zone_actual_max_slot_time = null;
    $slots_config_sql = "SELECT MIN(time) as earliest_slot, MAX(time) as latest_slot FROM cp_slots WHERE zone_id = ?";
    $slots_config_stmt = $conn->prepare($slots_config_sql);
    if ($slots_config_stmt) {
        $slots_config_stmt->bind_param("i", $zone_id_ref);
        $slots_config_stmt->execute();
        $slots_config_result = $slots_config_stmt->get_result();
        if ($slots_config_row = $slots_config_result->fetch_assoc()) {
            $zone_actual_min_slot_time = $slots_config_row['earliest_slot'];
            $zone_actual_max_slot_time = $slots_config_row['latest_slot'];
        }
        $slots_config_stmt->close();
    }
    if (!$zone_actual_min_slot_time || !$zone_actual_max_slot_time) {
        return [];
    }

    $ref_app_datetime = new DateTime($ref_app_date_str . ' ' . $ref_app_time_str);

    // Ottieni appuntamenti del giorno NELLA STESSA ZONA del RefApp per controlli di sequenza operatore
    $daily_appointments_sql = "SELECT id, appointment_time, address, latitude, longitude FROM cp_appointments WHERE zone_id = ? AND appointment_date = ? ORDER BY appointment_time";
    $daily_stmt = $conn->prepare($daily_appointments_sql);
    $prev_app_in_sequence = null; // Appuntamento operatore PRIMA del RefApp
    $next_app_in_sequence = null; // Appuntamento operatore DOPO il RefApp

    if ($daily_stmt) {
        $daily_stmt->bind_param("is", $zone_id_ref, $ref_app_date_str);
        $daily_stmt->execute();
        $daily_result = $daily_stmt->get_result();
        $appointments_for_day_in_zone = $daily_result->fetch_all(MYSQLI_ASSOC);
        $daily_stmt->close();

        $current_ref_app_index = -1;
        foreach ($appointments_for_day_in_zone as $i => $app_in_day) {
            if ($app_in_day['id'] == $ref_app_id) {
                $current_ref_app_index = $i;
                break;
            }
        }
        if ($current_ref_app_index > 0) {
            $prev_app_in_sequence = $appointments_for_day_in_zone[$current_ref_app_index - 1];
        }
        if ($current_ref_app_index !== -1 && $current_ref_app_index < count($appointments_for_day_in_zone) - 1) {
            $next_app_in_sequence = $appointments_for_day_in_zone[$current_ref_app_index + 1];
        }
    }

    // --- SLOT PRIMA del RefApp (NuovoApp -> RefApp) ---
    $proposed_before_slot_dt = clone $ref_app_datetime;
    $proposed_before_slot_dt->modify('-' . $duration_slot_minutes . ' minutes');
    $proposed_before_date_str = $proposed_before_slot_dt->format('Y-m-d');
    $proposed_before_time_str = $proposed_before_slot_dt->format('H:i:s');
    
    $exclude_before = false;
    $before_reason = "";
    $actual_travel_for_before_slot = false;

    // Check: Rispetto ai limiti di slot per il giorno della settimana del slot proposto
    $proposed_before_day_of_week = date('l', strtotime($proposed_before_date_str));
    $before_day_limits = getDaySpecificTimeLimits($proposed_before_day_of_week);
    
    if ($before_day_limits && $before_day_limits['min_time'] && $before_day_limits['max_time']) {
        if (strtotime($proposed_before_time_str) < strtotime($before_day_limits['min_time'])) {
            $exclude_before = true; 
            $before_reason = "Prima del min slot per {$proposed_before_day_of_week} ({$before_day_limits['min_time']}).";
        }
    } else {
        $exclude_before = true; 
        $before_reason = "Impossibile determinare limiti slot per {$proposed_before_day_of_week}";
    }

    if (!$exclude_before) { // Verifica occupazione slot (funzione isTimeSlotAvailable o simile)
        // Assicurati che isSlotAvailable (da utils_appointment.php) sia adatta o crea una versione
        // che controlli solo se lo slot DATA+ORA è già in cp_appointments.
        // Per semplicità, qui un controllo diretto:
        $check_booked_sql = "SELECT COUNT(*) as count FROM cp_appointments WHERE appointment_date = ? AND appointment_time = ?";
        $chk_stmt = $conn->prepare($check_booked_sql);
        $chk_stmt->bind_param("ss", $proposed_before_date_str, $proposed_before_time_str);
        $chk_stmt->execute();
        if ($chk_stmt->get_result()->fetch_assoc()['count'] > 0) {
            $exclude_before = true; $before_reason = "Slot {$proposed_before_time_str} già occupato.";
        }
        $chk_stmt->close();
    }
    
    // Controllo distanza per l'operatore: PrevInSequence -> NuovoApp (a $proposed_before_time_str)
    if (!$exclude_before && $prev_app_in_sequence) {
        if (!empty($prev_app_in_sequence['latitude']) && !empty($prev_app_in_sequence['longitude'])) {
            $dist_prev_to_new = calculateRoadDistance(
                (float)$prev_app_in_sequence['latitude'], (float)$prev_app_in_sequence['longitude'],
                $newUser_latitude, $newUser_longitude // Coords del NUOVO appuntamento
            );
            if ($dist_prev_to_new === false || $dist_prev_to_new < 0 || $dist_prev_to_new > $max_operator_hop_km) {
                $exclude_before = true; $before_reason = "Viaggio da AppPrecOperatore a NuovoApp ({$dist_prev_to_new}km) > {$max_operator_hop_km}km.";
            }
        } else { $exclude_before = true; $before_reason = "Coord mancanti AppPrecOperatore."; }
    }

    // Distanza viaggio specifico per questo slot: NuovoApp -> RefApp
    if (!$exclude_before) {
        $actual_travel_for_before_slot = calculateRoadDistance(
            $newUser_latitude, $newUser_longitude,
            $ref_app_lat, $ref_app_lng
        );
        if ($actual_travel_for_before_slot === false || $actual_travel_for_before_slot < 0 || $actual_travel_for_before_slot > $max_operator_hop_km) {
             $exclude_before = true; $before_reason = "Viaggio NuovoApp a RefApp ({$actual_travel_for_before_slot}km) > {$max_operator_hop_km}km.";
        }
    }
    
    $available_slots_info[] = [
        'date' => $proposed_before_date_str, 'time' => $proposed_before_time_str, 'type' => 'before',
        'related_appointment_details' => $ref_appointmentData, // App. di riferimento
        'excluded' => $exclude_before, 'excluded_reason' => $before_reason,
        'actual_travel_distance_for_new_slot' => $actual_travel_for_before_slot
    ];

    // --- SLOT DOPO il RefApp (RefApp -> NuovoApp) ---
    $proposed_after_slot_dt = clone $ref_app_datetime;
    $proposed_after_slot_dt->modify('+' . $duration_slot_minutes . ' minutes');
    $proposed_after_date_str = $proposed_after_slot_dt->format('Y-m-d');
    $proposed_after_time_str = $proposed_after_slot_dt->format('H:i:s');

    $exclude_after = false;
    $after_reason = "";
    $actual_travel_for_after_slot = false;

    // Check: Rispetto ai limiti di slot per il giorno della settimana del slot proposto  
    $proposed_after_day_of_week = date('l', strtotime($proposed_after_date_str));
    $after_day_limits = getDaySpecificTimeLimits($proposed_after_day_of_week);
    
    if ($after_day_limits && $after_day_limits['min_time'] && $after_day_limits['max_time']) {
        if (strtotime($proposed_after_time_str) > strtotime($after_day_limits['max_time'])) {
            $exclude_after = true; 
            $after_reason = "Dopo il max slot per {$proposed_after_day_of_week} ({$after_day_limits['max_time']}).";
        }
    } else {
        $exclude_after = true; 
        $after_reason = "Impossibile determinare limiti slot per {$proposed_after_day_of_week}";
    }

    if (!$exclude_after) { // Verifica occupazione slot
        $check_booked_sql = "SELECT COUNT(*) as count FROM cp_appointments WHERE appointment_date = ? AND appointment_time = ?";
        $chk_stmt = $conn->prepare($check_booked_sql);
        $chk_stmt->bind_param("ss", $proposed_after_date_str, $proposed_after_time_str);
        $chk_stmt->execute();
        if ($chk_stmt->get_result()->fetch_assoc()['count'] > 0) {
            $exclude_after = true; $after_reason = "Slot {$proposed_after_time_str} già occupato.";
        }
        $chk_stmt->close();
    }
    
    // Controllo distanza per l'operatore: NuovoApp (a $proposed_after_time_str) -> NextInSequence
    if (!$exclude_after && $next_app_in_sequence) {
        if (!empty($next_app_in_sequence['latitude']) && !empty($next_app_in_sequence['longitude'])) {
            $dist_new_to_next = calculateRoadDistance(
                $newUser_latitude, $newUser_longitude, // Coords del NUOVO appuntamento
                (float)$next_app_in_sequence['latitude'], (float)$next_app_in_sequence['longitude']
            );
            if ($dist_new_to_next === false || $dist_new_to_next < 0 || $dist_new_to_next > $max_operator_hop_km) {
                $exclude_after = true; $after_reason = "Viaggio da NuovoApp a AppSuccOperatore ({$dist_new_to_next}km) > {$max_operator_hop_km}km.";
            }
        } else { $exclude_after = true; $after_reason = "Coord mancanti AppSuccOperatore."; }
    }

    // Distanza viaggio specifico per questo slot: RefApp -> NuovoApp
    if (!$exclude_after) {
        $actual_travel_for_after_slot = calculateRoadDistance(
            $ref_app_lat, $ref_app_lng,
            $newUser_latitude, $newUser_longitude
        );
         if ($actual_travel_for_after_slot === false || $actual_travel_for_after_slot < 0 || $actual_travel_for_after_slot > $max_operator_hop_km) {
             $exclude_after = true; $after_reason = "Viaggio RefApp a NuovoApp ({$actual_travel_for_after_slot}km) > {$max_operator_hop_km}km.";
        }
    }

    $available_slots_info[] = [
        'date' => $proposed_after_date_str, 'time' => $proposed_after_time_str, 'type' => 'after',
        'related_appointment_details' => $ref_appointmentData,
        'excluded' => $exclude_after, 'excluded_reason' => $after_reason,
        'actual_travel_distance_for_new_slot' => $actual_travel_for_after_slot
    ];
    
    return $available_slots_info;
}

/**
 * Funzione per verificare slot disponibili prima/dopo un appuntamento esistente.
 * Rispetta i limiti MIN e MAX degli slot configurati in cp_slots per la zona,
 * e integra i controlli di distanza e occupazione.
 * CORREZIONE: Aggiunge controllo sovrapposizione temporale di 90 minuti
 *
 * @param array $appointmentData Dati dell'appuntamento di riferimento
 * @param int $buffer_minutes Durata dello slot/buffer (default 60)
 * @return array Array di slot disponibili (o esclusi con motivo)
 */
/**
 * Helper function to get time limits for a specific day of the week from all zones
 */
/**
 * Helper function to get time limits for a specific day of the week from all zones or a specific zone
 */
function getDaySpecificTimeLimits($day_of_week, $all_zones = false) {
    global $conn;
    
    if ($all_zones) {
        // Cerca in TUTTE le zone per trovare il range più ampio possibile per quel giorno
        $slots_config_sql = "SELECT MIN(time) as earliest_slot, MAX(time) as latest_slot FROM cp_slots WHERE day = ?";
        $slots_config_stmt = $conn->prepare($slots_config_sql);
        
        if ($slots_config_stmt) {
            $slots_config_stmt->bind_param("s", $day_of_week);
            $slots_config_stmt->execute();
            $slots_config_result = $slots_config_stmt->get_result();
            if ($slots_config_row = $slots_config_result->fetch_assoc()) {
                $slots_config_stmt->close();
                return [
                    'min_time' => $slots_config_row['earliest_slot'],
                    'max_time' => $slots_config_row['latest_slot']
                ];
            }
            $slots_config_stmt->close();
        }
    } else {
        // Comportamento originale - cerca solo nelle zone specifiche
        $slots_config_sql = "SELECT MIN(time) as earliest_slot, MAX(time) as latest_slot FROM cp_slots WHERE day = ?";
        $slots_config_stmt = $conn->prepare($slots_config_sql);
        
        if ($slots_config_stmt) {
            $slots_config_stmt->bind_param("s", $day_of_week);
            $slots_config_stmt->execute();
            $slots_config_result = $slots_config_stmt->get_result();
            if ($slots_config_row = $slots_config_result->fetch_assoc()) {
                $slots_config_stmt->close();
                return [
                    'min_time' => $slots_config_row['earliest_slot'],
                    'max_time' => $slots_config_row['latest_slot']
                ];
            }
            $slots_config_stmt->close();
        }
    }
    
    return null;
}

/**
 * Verifica se un nuovo slot può essere inserito senza sovrapposizioni
 */
function canInsertSlot($proposed_time_str, $proposed_date_str, $duration_minutes, $all_appointments) {
    $proposed_start = strtotime($proposed_date_str . ' ' . $proposed_time_str);
    $proposed_end = $proposed_start + ($duration_minutes * 60);
    
    foreach ($all_appointments as $existing_app) {
        $existing_start = strtotime($proposed_date_str . ' ' . $existing_app['appointment_time']);
        $existing_end = $existing_start + ($duration_minutes * 60);
        
        // Verifica sovrapposizione REALE:
        // Due slot si sovrappongono se uno inizia prima che l'altro finisca
        // NO sovrapposizione se: nuovo_fine <= esistente_inizio OR nuovo_inizio >= esistente_fine
        // SOVRAPPOSIZIONE se: nuovo_inizio < esistente_fine AND nuovo_fine > esistente_inizio
        
        if ($proposed_start < $existing_end && $proposed_end > $existing_start) {
            // C'è sovrapposizione
            return false;
        }
    }
    return true;
}

function checkAvailableSlotsNearAppointment($appointmentData, $buffer_minutes = 60) {
    global $conn;
    $available_slots = [];

    // Dati dell'appuntamento di riferimento
    $zone_id = isset($appointmentData['zone_id']) ? $appointmentData['zone_id'] : 0;
    $appointment_id_ref = isset($appointmentData['id']) ? $appointmentData['id'] : null;
    $appointment_date_str_ref = isset($appointmentData['appointment_date']) ? $appointmentData['appointment_date'] : null;
    $appointment_time_str_ref = isset($appointmentData['appointment_time']) ? $appointmentData['appointment_time'] : null;
    $appointment_address_ref = isset($appointmentData['address']) ? $appointmentData['address'] : null;
    $latitude = isset($appointmentData['latitude']) ? $appointmentData['latitude'] : null;
    $longitude = isset($appointmentData['longitude']) ? $appointmentData['longitude'] : null;

    if (!$appointment_id_ref || !$appointment_date_str_ref || !$appointment_time_str_ref || !$appointment_address_ref) {
        return [];
    }
    
    $duration_slot_minutes = (int)$buffer_minutes;

    $appointment_datetime_ref = new DateTime($appointment_date_str_ref . ' ' . $appointment_time_str_ref);

    // Ottieni TUTTI gli appuntamenti del giorno (non solo della zona) per verificare sovrapposizioni
    $all_daily_appointments_sql = "SELECT id, appointment_time, address, zone_id FROM cp_appointments WHERE appointment_date = ? ORDER BY appointment_time";
    $all_daily_stmt = $conn->prepare($all_daily_appointments_sql);
    $all_appointments_for_day = [];

    if ($all_daily_stmt) {
        $all_daily_stmt->bind_param("s", $appointment_date_str_ref);
        $all_daily_stmt->execute();
        $all_daily_result = $all_daily_stmt->get_result();
        $all_appointments_for_day = $all_daily_result->fetch_all(MYSQLI_ASSOC);
        $all_daily_stmt->close();
    }

    // Ottieni appuntamenti della stessa zona per calcoli di distanza
    $daily_appointments_sql = "SELECT id, appointment_time, address, latitude, longitude FROM cp_appointments WHERE zone_id = ? AND appointment_date = ? ORDER BY appointment_time";
    $daily_stmt = $conn->prepare($daily_appointments_sql);
    $prev_appointment_details_for_distance = null;
    $next_appointment_details_for_distance = null;
    $last_appointment_of_day = null;

    if ($daily_stmt) {
        $daily_stmt->bind_param("is", $zone_id, $appointment_date_str_ref);
        $daily_stmt->execute();
        $daily_result = $daily_stmt->get_result();
        $appointments_for_day_in_zone = $daily_result->fetch_all(MYSQLI_ASSOC);
        $daily_stmt->close();

        $current_appointment_index_in_day = -1;
        foreach ($appointments_for_day_in_zone as $i => $app_in_day) {
            if ($app_in_day['id'] == $appointment_id_ref) {
                $current_appointment_index_in_day = $i;
                break;
            }
        }

        if ($current_appointment_index_in_day > 0) {
            $prev_appointment_details_for_distance = $appointments_for_day_in_zone[$current_appointment_index_in_day - 1];
        }
        if ($current_appointment_index_in_day !== -1 && $current_appointment_index_in_day < count($appointments_for_day_in_zone) - 1) {
            $next_appointment_details_for_distance = $appointments_for_day_in_zone[$current_appointment_index_in_day + 1];
        }
        // Trova il vero ultimo appuntamento della giornata (in base all'orario)
        $last_appointment_of_day = end($appointments_for_day_in_zone);
        reset($appointments_for_day_in_zone);
    }

    // --- CONTROLLO SLOT PRIMA ---
    $proposed_before_slot_dt = clone $appointment_datetime_ref;
    $proposed_before_slot_dt->modify('-' . $duration_slot_minutes . ' minutes');
    $proposed_before_date_str = $proposed_before_slot_dt->format('Y-m-d');
    $proposed_before_time_str = $proposed_before_slot_dt->format('H:i:s');
    
    $exclude_before = false;
    $before_slot_excluded_reason = "";
    $debug_info_before = ['evaluated_slot' => $proposed_before_date_str . ' ' . $proposed_before_time_str];

    // Check: Rispetto ai limiti di slot per il giorno della settimana del slot proposto
    $proposed_before_day_of_week = date('l', strtotime($proposed_before_date_str));
    $before_day_limits = getDaySpecificTimeLimits($proposed_before_day_of_week, true);
    
    if ($before_day_limits && $before_day_limits['min_time'] && $before_day_limits['max_time']) {
        if (strtotime($proposed_before_time_str) < strtotime($before_day_limits['min_time'])) {
            $exclude_before = true;
            $before_slot_excluded_reason = "Slot proposto {$proposed_before_time_str} è prima del primo slot operativo per {$proposed_before_day_of_week} ({$before_day_limits['min_time']})";
        }
    } else {
        $exclude_before = true;
        $before_slot_excluded_reason = "Impossibile determinare limiti slot per {$proposed_before_day_of_week}";
    }

    // CONTROLLO SOVRAPPOSIZIONE CON DURATA SLOT (60 MINUTI)
    if (!$exclude_before) {
        if (!canInsertSlot($proposed_before_time_str, $proposed_before_date_str, $duration_slot_minutes, $all_appointments_for_day)) {
            $exclude_before = true;
            
            // Trova quali appuntamenti causano il conflitto per messaggio dettagliato
            $conflicting_times = [];
            $proposed_start = strtotime($proposed_before_date_str . ' ' . $proposed_before_time_str);
            $proposed_end = $proposed_start + ($duration_slot_minutes * 60);
            
            foreach ($all_appointments_for_day as $existing_app) {
                $existing_start = strtotime($proposed_before_date_str . ' ' . $existing_app['appointment_time']);
                $existing_end = $existing_start + ($duration_slot_minutes * 60);
                
                if (($proposed_start >= $existing_start && $proposed_start < $existing_end) ||
                    ($proposed_end > $existing_start && $proposed_end <= $existing_end) ||
                    ($proposed_start <= $existing_start && $proposed_end >= $existing_end) ||
                    (abs($proposed_start - $existing_end) < 60) ||
                    (abs($existing_start - $proposed_end) < 60)) {
                    $conflicting_times[] = date('H:i', strtotime($existing_app['appointment_time']));
                }
            }
            
            $before_slot_excluded_reason = "Slot {$proposed_before_time_str} non ha spazio sufficiente (60 min) rispetto ad appuntamenti esistenti: " . implode(', ', array_unique($conflicting_times));
        }
    }

    // Check distanza dal precedente (LOGICA ORIGINALE MANTENUTA)
    if (!$exclude_before && $prev_appointment_details_for_distance && !empty($prev_appointment_details_for_distance['address'])) {
        $coords_ref_for_prev_dist = getCoordinatesFromAddress($appointment_address_ref, $appointment_id_ref);
        $coords_prev_for_dist = getCoordinatesFromAddress($prev_appointment_details_for_distance['address'], $prev_appointment_details_for_distance['id']);

        if ($coords_ref_for_prev_dist && $coords_prev_for_dist) {
            $distance_from_prev = calculateRoadDistance(
                $coords_prev_for_dist['lat'], $coords_prev_for_dist['lng'],
                $coords_ref_for_prev_dist['lat'], $coords_ref_for_prev_dist['lng']
            );
            $debug_info_before['distance_from_prev_app'] = [
                'prev_app_id' => $prev_appointment_details_for_distance['id'],
                'prev_address' => $prev_appointment_details_for_distance['address'],
                'distance_km' => $distance_from_prev
            ];
            if ($distance_from_prev === false || $distance_from_prev < 0) {
                $exclude_before = true;
                $before_slot_excluded_reason = "Errore calcolo distanza da app. precedente ({$prev_appointment_details_for_distance['id']}).";
            } elseif ($distance_from_prev > 3) {
                $exclude_before = true;
                $before_slot_excluded_reason = "Distanza da app. precedente ({$prev_appointment_details_for_distance['id']}: " . number_format($distance_from_prev,1) . " km) > 3 km";
            }
        } else {
            $exclude_before = true;
            $before_slot_excluded_reason = "Impossibile ottenere coordinate per calcolo distanza da app. precedente ({$prev_appointment_details_for_distance['id']}).";
            $debug_info_before['coord_error_prev'] = $before_slot_excluded_reason;
        }
    }
    
    $available_slots[] = [
        'date' => $proposed_before_date_str,
        'time' => $proposed_before_time_str,
        'type' => 'before',
        'related_appointment' => $appointmentData,
        'excluded' => $exclude_before,
        'excluded_reason' => $before_slot_excluded_reason,
        'debug_info' => $debug_info_before
    ];

    // --- CONTROLLO SLOT DOPO ---
    $proposed_after_slot_dt = clone $appointment_datetime_ref;
    $proposed_after_slot_dt->modify('+' . $duration_slot_minutes . ' minutes');
    $proposed_after_date_str = $proposed_after_slot_dt->format('Y-m-d');
    $proposed_after_time_str = $proposed_after_slot_dt->format('H:i:s');

    $exclude_after = false;
    $after_slot_excluded_reason = "";
    $debug_info_after = ['evaluated_slot' => $proposed_after_date_str . ' ' . $proposed_after_time_str];

    // Check: Rispetto ai limiti di slot per il giorno della settimana del slot proposto
    $proposed_after_day_of_week = date('l', strtotime($proposed_after_date_str));
    $after_day_limits = getDaySpecificTimeLimits($proposed_after_day_of_week, true);
    
    if ($after_day_limits && $after_day_limits['min_time'] && $after_day_limits['max_time']) {
        if (strtotime($proposed_after_time_str) > strtotime($after_day_limits['max_time'])) {
            // SLOT EXTRA: solo se entro 3km dall'ultimo della giornata (LOGICA ORIGINALE MANTENUTA)
            if ($last_appointment_of_day && !empty($last_appointment_of_day['latitude']) && !empty($last_appointment_of_day['longitude']) && $latitude && $longitude) {
                $distanza = calculateRoadDistance(
                    $latitude, $longitude,
                    $last_appointment_of_day['latitude'], $last_appointment_of_day['longitude']
                );
                if ($distanza !== false && $distanza <= 3) {
                    $available_slots[] = [
                        'date' => $proposed_after_date_str,
                        'time' => $proposed_after_time_str,
                        'type' => 'after_extra',
                        'related_appointment' => $last_appointment_of_day,
                        'excluded' => false,
                        'excluded_reason' => '',
                        'debug_info' => $debug_info_after,
                        'extra_warning' => "Slot fuori orario per {$proposed_after_day_of_week}: proposto perché l'indirizzo è entro 3km dall'ultimo appuntamento della giornata (ID: {$last_appointment_of_day['id']}, zona: $zone_id). Distanza: " . number_format($distanza, 1) . "km"
                    ];
                } else {
                    $exclude_after = true;
                    $after_slot_excluded_reason = "Slot fuori orario per {$proposed_after_day_of_week} e troppo distante dall'ultimo appuntamento della giornata";
                }
            } else {
                $exclude_after = true;
                $after_slot_excluded_reason = "Slot fuori orario per {$proposed_after_day_of_week} e non è possibile determinare la distanza con l'ultimo appuntamento";
            }
        }
    } else {
        $exclude_after = true;
        $after_slot_excluded_reason = "Impossibile determinare limiti slot per {$proposed_after_day_of_week}";
    }
    
    if (!$exclude_after) {
        // Orario regolare zona

        // CONTROLLO SOVRAPPOSIZIONE CON DURATA SLOT (60 MINUTI)
        if (!canInsertSlot($proposed_after_time_str, $proposed_after_date_str, $duration_slot_minutes, $all_appointments_for_day)) {
            $exclude_after = true;
            
            // Trova quali appuntamenti causano il conflitto per messaggio dettagliato
            $conflicting_times = [];
            $proposed_start = strtotime($proposed_after_date_str . ' ' . $proposed_after_time_str);
            $proposed_end = $proposed_start + ($duration_slot_minutes * 60);
            
            foreach ($all_appointments_for_day as $existing_app) {
                $existing_start = strtotime($proposed_after_date_str . ' ' . $existing_app['appointment_time']);
                $existing_end = $existing_start + ($duration_slot_minutes * 60);
                
                if (($proposed_start >= $existing_start && $proposed_start < $existing_end) ||
                    ($proposed_end > $existing_start && $proposed_end <= $existing_end) ||
                    ($proposed_start <= $existing_start && $proposed_end >= $existing_end) ||
                    (abs($proposed_start - $existing_end) < 60) ||
                    (abs($existing_start - $proposed_end) < 60)) {
                    $conflicting_times[] = date('H:i', strtotime($existing_app['appointment_time']));
                }
            }
            
            $after_slot_excluded_reason = "Slot {$proposed_after_time_str} non ha spazio sufficiente (60 min) rispetto ad appuntamenti esistenti: " . implode(', ', array_unique($conflicting_times));
        }

        // Check distanza dal successivo (LOGICA ORIGINALE MANTENUTA)
        if (!$exclude_after && $next_appointment_details_for_distance && !empty($next_appointment_details_for_distance['address'])) {
            $coords_ref_for_next_dist = getCoordinatesFromAddress($appointment_address_ref, $appointment_id_ref);
            $coords_next_for_dist = getCoordinatesFromAddress($next_appointment_details_for_distance['address'], $next_appointment_details_for_distance['id']);

            if ($coords_ref_for_next_dist && $coords_next_for_dist) {
                $distance_to_next = calculateRoadDistance(
                    $coords_ref_for_next_dist['lat'], $coords_ref_for_next_dist['lng'],
                    $coords_next_for_dist['lat'], $coords_next_for_dist['lng']
                );
                $debug_info_after['distance_to_next_app'] = [
                    'next_app_id' => $next_appointment_details_for_distance['id'],
                    'next_address' => $next_appointment_details_for_distance['address'],
                    'distance_km' => $distance_to_next
                ];
                if ($distance_to_next === false || $distance_to_next < 0) {
                    $exclude_after = true;
                    $after_slot_excluded_reason = "Errore calcolo distanza da app. successivo ({$next_appointment_details_for_distance['id']}).";
                } elseif ($distance_to_next > 3) {
                    $exclude_after = true;
                    $after_slot_excluded_reason = "Distanza da app. successivo ({$next_appointment_details_for_distance['id']}: " . number_format($distance_to_next,1) . " km) > 3 km";
                }
            } else {
                $exclude_after = true;
                $after_slot_excluded_reason = "Impossibile ottenere coordinate per calcolo distanza da app. successivo ({$next_appointment_details_for_distance['id']}).";
                $debug_info_after['coord_error_next'] = $after_slot_excluded_reason;
            }
        }

        if (!$exclude_after) {
            $available_slots[] = [
                'date' => $proposed_after_date_str,
                'time' => $proposed_after_time_str,
                'type' => 'after',
                'related_appointment' => $appointmentData,
                'excluded' => $exclude_after,
                'excluded_reason' => $after_slot_excluded_reason,
                'debug_info' => $debug_info_after
            ];
        }
    }
    return $available_slots;
}



/**
 * Trova slot in giornate di altre zone dove esistono già appuntamenti 
 * e almeno uno è entro $radius_km dal nuovo indirizzo.
 * Ritorna un array di slot: data, orario, zona, motivazione.
 *
 * @param float $new_lat
 * @param float $new_lng
 * @param int $radius_km
 * @param int $buffer_minutes
 * @return array
 */
function findOptimizedMixedDaySlots($new_lat, $new_lng, $radius_km = 3, $buffer_minutes = 60) {
    global $conn;
    $results = [];
    $today = date('Y-m-d');

    // Trova tutte le giornate future con appuntamenti
    $sql_dates = "SELECT DISTINCT appointment_date FROM cp_appointments WHERE appointment_date >= ? ORDER BY appointment_date ASC";
    $stmt_dates = $conn->prepare($sql_dates);
    $stmt_dates->bind_param("s", $today);
    $stmt_dates->execute();
    $res_dates = $stmt_dates->get_result();
    $date_list = [];
    while ($row = $res_dates->fetch_assoc()) {
        $date_list[] = $row['appointment_date'];
    }
    $stmt_dates->close();

    foreach ($date_list as $thedate) {
        // Prendi tutti gli appuntamenti di quella giornata (tutte zone)
        $sql = "SELECT id, appointment_time, address, zone_id FROM cp_appointments WHERE appointment_date = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $thedate);
        $stmt->execute();
        $res = $stmt->get_result();
        $apps = [];
        while ($a = $res->fetch_assoc()) {
            $coords = getCoordinatesFromAddress($a['address'], $a['id']);
            if (!$coords) continue;
            $a['latitude'] = $coords['lat'];
            $a['longitude'] = $coords['lng'];
            $apps[] = $a;
        }
        $stmt->close();
        if (empty($apps)) continue;

        // Trova slot liberi in tutte le zone di questi appuntamenti
        $zone_ids = array_unique(array_column($apps, 'zone_id'));
        foreach ($zone_ids as $zone_id) {
            // Prendi slot operativi della zona per quel giorno della settimana
            $day_of_week = date('N', strtotime($thedate));
            $sql_slot = "SELECT time FROM cp_slots WHERE zone_id = ? AND day = ?";
            $stmt_slot = $conn->prepare($sql_slot);
            $stmt_slot->bind_param("is", $zone_id, $day_of_week);
            $stmt_slot->execute();
            $res_slot = $stmt_slot->get_result();
            $zone_slots = [];
            while ($row = $res_slot->fetch_assoc()) $zone_slots[] = $row['time'];
            $stmt_slot->close();
            if (empty($zone_slots)) continue;

            // Prendi orari già occupati in questa zona e data
            $occupied = [];
            foreach ($apps as $a) {
                if ($a['zone_id'] == $zone_id) $occupied[] = $a['appointment_time'];
            }

            // Per ogni slot libero, controlla compatibilità logistica
            foreach ($zone_slots as $slot_time) {
                if (in_array($slot_time, $occupied)) continue; // slot occupato
                // Il nuovo indirizzo sarebbe entro il raggio da almeno uno degli appuntamenti di quella giornata?
                foreach ($apps as $a) {
                    $dist = calculateRoadDistance($new_lat, $new_lng, $a['latitude'], $a['longitude']);
                    if ($dist === false || $dist > $radius_km) continue;
                    // Controlla anche buffer temporale
                    $conflict = false;
                    foreach ($apps as $b) {
                        if ($b['zone_id'] != $zone_id) continue;
                        $dt1 = strtotime($thedate.' '.$slot_time);
                        $dt2 = strtotime($thedate.' '.$b['appointment_time']);
                        if (abs($dt1 - $dt2) < $buffer_minutes*60) $conflict = true;
                    }
                    if ($conflict) continue;
                    // Verifica che non sia slot unavailable
                    $slot_end = date('H:i:s', strtotime($slot_time) + $buffer_minutes*60);
                    $availability = isSlotAvailable($thedate, $slot_time, $slot_end, $zone_id);
                    if (!$availability['available']) continue;
                    // Proponi lo slot!
                    $results[] = [
                        'date' => $thedate,
                        'time' => $slot_time,
                        'zone_id' => $zone_id,
                        'motivation' => "Ottimizzato: slot vicino a un appuntamento già presente ($dist km)",
                        'distance' => $dist
                    ];
                    break; // basta che uno sia vicino
                }
            }
        }
    }
    // Ordina per data e ora
    usort($results, function($a, $b) {
        $dtA = strtotime($a['date'].' '.$a['time']);
        $dtB = strtotime($b['date'].' '.$b['time']);
        return $dtA <=> $dtB;
    });
    return $results;
}



        // Function to get slots for a specific zone
function getSlotsForZone($zoneId) {
    global $conn;
    $sql = "SELECT day, time FROM cp_slots WHERE zone_id = ?";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new Exception("Database prepare failed for slots: " . mysqli_error($conn));
    }

    $stmt->bind_param("i", $zoneId);

    if (!$stmt->execute()) {
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
        return false; // In caso di errore, consideriamo lo slot come non disponibile per sicurezza
    }
    
    $checkStmt->bind_param("ss", $date, $time);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $row = $checkResult->fetch_assoc();
    $isOccupied = ($row['count'] > 0);
    $checkStmt->close();
    
    if ($isOccupied) {
        return false;
    }
    
    // Verifica prima se lo slot rientra negli unavailable slots
    // Calcola l'orario di fine esplicitamente
    $endTime = date('H:i:s', strtotime($time . " +{$duration} minutes"));
    $availability = isSlotAvailable($date, $time, $endTime, $zone_id);
    
    if (!$availability['available']) {
        return false;
    }
    
    // Verifica prima gli appuntamenti nella stessa zona
    $sql1 = "SELECT COUNT(*) FROM cp_appointments 
            WHERE zone_id = ? AND appointment_date = ? AND (
                (appointment_time <= ? AND ADDTIME(appointment_time, '01:00:00') > ?) OR 
                (appointment_time >= ? AND appointment_time < ?)
            )";
    
    $stmt1 = $conn->prepare($sql1);
    $count1 = 0; 
    if (!$stmt1) {
        return false;
    }
    
    $stmt1->bind_param("isssss", $zone_id, $date, $time, $start_datetime, $time, $end_datetime);
    $stmt1->execute();
    $stmt1->bind_result($count1);
    $stmt1->fetch();
    $stmt1->close();
    
    if ($count1 > 0) {
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
        return false;
    }
    
    $stmt2->bind_param("sissss", $date, $zone_id, $time, $start_datetime, $time, $end_datetime);
    $stmt2->execute();
    $result2 = $stmt2->get_result();
    
    // Verifica se qualcuno di questi appuntamenti è entro 7km dal punto corrente
    // Nota: questo richiederebbe di conoscere le coordinate dell'indirizzo corrente
    // Per adesso, consideriamo tutti gli appuntamenti temporalmente sovrapposti come non disponibili
    if ($result2->num_rows > 0) {
        return false;
    }
    
    return true;
}
    

/**
 * Trova la prima zona che contiene le coordinate specificate.
 * @param float $latitude
 * @param float $longitude
 * @return array|null Dati della zona o null se non trovata.
 */
function getZoneForCoordinates($latitude, $longitude) {
    global $conn;
    $sql = "SELECT id, name, latitude AS zone_lat, longitude AS zone_lng, radius_km FROM cp_zones";
    $result = $conn->query($sql);
    $user_coords = [$latitude, $longitude];

    if ($result && $result->num_rows > 0) {
        while ($zone = $result->fetch_assoc()) {
            $zone_coords = [(float)$zone['zone_lat'], (float)$zone['zone_lng']];
            // calculateDistance è la tua funzione esistente per la distanza euclidea + correzione
            $distance_to_center = calculateDistance($user_coords, $zone_coords);
            if ($distance_to_center <= (float)$zone['radius_km']) {
                $zone['distance_from_user_address'] = $distance_to_center; // Aggiungiamo per info
                return $zone; // Restituisce la prima zona trovata
            }
        }
    }
    return null;
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
function isAppointmentAvailable($zoneId, $appointmentDate, $appointmentTime, $address, $latitude, $longitude, $buffer_minutes = 60) {
    global $conn;
    
    // Verifica innanzitutto la disponibilità dello slot rispetto agli unavailable slots
    $endTime = date('H:i:s', strtotime($appointmentTime . " +1 hour"));
    $availability = isSlotAvailable($appointmentDate, $appointmentTime, $endTime, $zoneId);
    if (!$availability['available']) {
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
            
        }
    }
        // STEP 2: Ottieni tutti gli appuntamenti per la data specificata
        $sql = "SELECT id, appointment_time, address 
        FROM cp_appointments 
        WHERE zone_id = ? AND appointment_date = ? 
        ORDER BY appointment_time";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    return false;
}

$stmt->bind_param("is", $zoneId, $appointmentDate);
if (!$stmt->execute()) {
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

// STEP 4: Controlla che ci sia spazio sufficiente tra prev e next per inserire lo slot

$slotStart = new DateTime($appointmentDate . ' ' . $appointmentTime);
$slotEnd = clone $slotStart;
$slotEnd->modify("+{$slotDuration} minutes");

// Controllo rispetto all'appuntamento precedente
if ($prevAppointment) {
    $prevDateTime = new DateTime($appointmentDate . ' ' . $prevAppointment['appointment_time']);
    $prevEndTime = clone $prevDateTime;
    $prevEndTime->modify("+{$slotDuration} minutes");

    // Lo slot proposto deve iniziare DOPO la fine del precedente
    if ($slotStart < $prevEndTime) {
        return false;
    }
}

// Controllo rispetto all'appuntamento successivo
if ($nextAppointment) {
    $nextDateTime = new DateTime($appointmentDate . ' ' . $nextAppointment['appointment_time']);

    // Lo slot proposto deve finire PRIMA dell'inizio del successivo
    if ($slotEnd > $nextDateTime) {
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
            return false;
        }
        
        if ($distance > 3) {
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
            return false;
        }
        
        if ($distance > 3) {
            return false;
        }
    }
}

// Se tutti i controlli sono passati, l'appuntamento è disponibile
return true;
}
// Funzione per ottenere coordinate da un indirizzo
function getCoordinatesFromAddress($address, $appointment_id = null) {
    global $conn;

    // Log dell'operazione

    // Controlla se abbiamo già le coordinate per questo indirizzo
    $sql = "SELECT id, latitude, longitude FROM address_cache WHERE address = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("s", $address);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $address_cache_id = $row['id'];
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
            // PATCH: aggiorna la mappatura zona
            if ($address_cache_id) {
                updateAddressZoneMap($address_cache_id, $row['latitude'], $row['longitude']);
            }
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
        return null;
    }

    if (empty($apiKey)) {
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
        curl_close($ch);
        return null;
    }

    curl_close($ch);

    $data = json_decode($response, true);

    if ($data['status'] == 'OK') {
        $lat = $data['results'][0]['geometry']['location']['lat'];
        $lng = $data['results'][0]['geometry']['location']['lng'];

        // Salva nella cache
        $address_cache_id = null;
        if ($appointment_id) {
            $sql = "INSERT INTO address_cache (appointment_id, address, latitude, longitude)
                   VALUES (?, ?, ?, ?) 
                   ON DUPLICATE KEY UPDATE address = VALUES(address), latitude = VALUES(latitude), longitude = VALUES(longitude)";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("isdd", $appointment_id, $address, $lat, $lng);
                if (!$stmt->execute()) {
                }
                // Prendi id anche se update
                $id_stmt = $conn->prepare("SELECT id FROM address_cache WHERE address = ? LIMIT 1");
                $id_stmt->bind_param("s", $address);
                $id_stmt->execute();
                $res_id = $id_stmt->get_result();
                if ($row_id = $res_id->fetch_assoc()) $address_cache_id = $row_id['id'];
                $id_stmt->close();
            }
        } else {
            $sql = "INSERT INTO address_cache (address, latitude, longitude)
                   VALUES (?, ?, ?)
                   ON DUPLICATE KEY UPDATE latitude = VALUES(latitude), longitude = VALUES(longitude)";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("sdd", $address, $lat, $lng);
                $stmt->execute();
                // Prendi id anche se update
                $id_stmt = $conn->prepare("SELECT id FROM address_cache WHERE address = ? LIMIT 1");
                $id_stmt->bind_param("s", $address);
                $id_stmt->execute();
                $res_id = $id_stmt->get_result();
                if ($row_id = $res_id->fetch_assoc()) $address_cache_id = $row_id['id'];
                $id_stmt->close();
            }
        }
        // PATCH: aggiorna la mappatura zona
        if ($address_cache_id) {
            updateAddressZoneMap($address_cache_id, $lat, $lng);
        }

        return ['lat' => $lat, 'lng' => $lng];
    } else {
        return null;
    }
}

// Helper function to get coordinates from address
function getAddressCoordinates($address, $appointment_id = null) {
// Questa funzione può utilizzare la cache o chiamare getCoordinatesFromAddress
// Qui riutilizziamo la funzione esistente
return getCoordinatesFromAddress($address, $appointment_id);
}

/**
 * NUOVA FUNZIONE: Variante di getNextAppointmentDatesForZone che rispetta una data massima.
 * Trova date e orari disponibili per una zona, fino a una data specifica.
 *
 * @param array $slots_config Configurazione degli slot per la zona.
 * @param int $zoneId ID della zona.
 * @param float $user_latitude Latitudine utente.
 * @param float $user_longitude Longitudine utente.
 * @param int $max_operator_hop_km Max distanza tra appuntamenti operatore (attualmente non usata attivamente in questa funzione per filtrare slot di zona).
 * @param string $max_target_date_str Data massima (Y-m-d) per la ricerca.
 * @param int $weeks_to_search_fallback Numero di settimane nel futuro da cercare come fallback se $max_target_date_str non è restrittiva.
 * @return array Date con i relativi orari disponibili.
 */
function getNextAppointmentDatesForZone_Bounded($slots_config, $zoneId, $user_latitude, $user_longitude, $max_operator_hop_km, $max_target_date_str, $weeks_to_search_fallback = 4) {
    global $conn; // Assicurati che $conn sia accessibile
    $available_dates_with_times = [];
    $current_date_obj = new DateTime(); // Data di inizio ricerca è oggi

    $max_date_obj = new DateTime($max_target_date_str);
    
    // Calcola il numero di giorni da oggi fino a max_target_date_str
    // Se max_target_date_str è oggi o nel passato, $days_to_scan sarà 0 o negativo.
    $days_to_scan = 0;
    if ($current_date_obj <= $max_date_obj) {
        $interval = $current_date_obj->diff($max_date_obj);
        $days_to_scan = $interval->days;
    }
    
    // Limita la ricerca al massimo a N settimane nel futuro (come fallback) o fino alla data massima
    $days_limit_from_fallback = $weeks_to_search_fallback * 7;
    // +1 per includere max_target_date_str se è futura
    $actual_days_to_check = min($days_to_scan + 1, $days_limit_from_fallback);
    if ($max_date_obj < $current_date_obj) { // Se la data massima è passata, non cercare affatto.
        $actual_days_to_check = 0;
    }


    $map_day_to_number = [
        'Monday' => 1, 'Lunedì' => 1,
        'Tuesday' => 2, 'Martedì' => 2,
        'Wednesday' => 3, 'Mercoledì' => 3,
        'Thursday' => 4, 'Giovedì' => 4,
        'Friday' => 5, 'Venerdì' => 5,
        'Saturday' => 6, 'Sabato' => 6,
        'Sunday' => 7, 'Domenica' => 7,
    ];

    for ($i = 0; $i < $actual_days_to_check; $i++) {
        $check_date_obj = (clone $current_date_obj)->modify("+$i days");
        $check_date_str = $check_date_obj->format('Y-m-d');

        // Controllo esplicito per non superare la data target (anche se $actual_days_to_check dovrebbe già gestirlo)
        if ($check_date_str > $max_target_date_str) {
            break;
        }

        $day_of_week_iso = (int)$check_date_obj->format('N');

        $date_availability = isSlotAvailable($check_date_str, null, null, $zoneId);
        if (!$date_availability['available']) {
            continue;
        }

        $slots_for_this_day_type = [];
        foreach ($slots_config as $slot_cfg) {
            $cfg_day_iso = $map_day_to_number[$slot_cfg['day']] ?? -1;
            if ($cfg_day_iso == $day_of_week_iso) {
                $slots_for_this_day_type[] = $slot_cfg['time'];
            }
        }
        sort($slots_for_this_day_type);

        if (empty($slots_for_this_day_type)) continue;

        $valid_times_for_date = [];
        foreach ($slots_for_this_day_type as $slot_time_str) {
            if ($check_date_str == date('Y-m-d') && strtotime($slot_time_str) < time()) {
                continue;
            }

            $slot_start_time = $slot_time_str;
            $slot_end_time = date('H:i:s', strtotime($slot_start_time . " +1 hour")); // Assumendo slot di 1 ora
            $slot_specific_availability = isSlotAvailable($check_date_str, $slot_start_time, $slot_end_time, $zoneId);

            if (!$slot_specific_availability['available']) {
                continue;
            }

            $check_booked_sql = "SELECT COUNT(*) as count FROM cp_appointments WHERE appointment_date = ? AND appointment_time = ?";
            $chk_stmt = $conn->prepare($check_booked_sql);
            if ($chk_stmt) {
                $chk_stmt->bind_param("ss", $check_date_str, $slot_time_str);
                $chk_stmt->execute();
                $is_occupied = ($chk_stmt->get_result()->fetch_assoc()['count'] > 0);
                $chk_stmt->close();

                if (!$is_occupied) {
                    $valid_times_for_date[] = $slot_time_str;
                }
            } else {
            }
        }

        if (!empty($valid_times_for_date)) {
            $available_dates_with_times[$check_date_str] = $valid_times_for_date;
        }
    }
    ksort($available_dates_with_times);
    return $available_dates_with_times;
}


// Funzione helper per creare l'oggetto slot (puoi posizionarla dove preferisci, prima del suo utilizzo)
function creaSlotPropostoDaDatiZona($date_str, $time_str, $zona_data, $user_lat, $user_lng, $is_confinante = false, $distanza_utente_centro_zona = 0) {
    $priority_score = ($is_confinante ? 2000 : 1000) + (float)$distanza_utente_centro_zona;

    $related_details = [
        'id' => null, // Non è un appuntamento esistente
        'address' => 'N/A - Slot di Zona',
        'zone_id' => $zona_data['id'],
        'name' => $zona_data['name'] ?? "Zona ID {$zona_data['id']}",
        'latitude' => ($zona_data['zone_lat'] ?? $zona_data['latitude'] ?? 0), // Adatta a seconda di come sono nominati i campi in $zona_data
        'longitude' => ($zona_data['zone_lng'] ?? $zona_data['longitude'] ?? 0),
        'is_neighboring_zone' => $is_confinante,
        'is_main_zone' => !$is_confinante
    ];

    $slot_details_prop = [
        'date' => $date_str,
        'time' => $time_str,
        'type' => $is_confinante ? 'neighbor_zone_slot' : 'main_zone_slot',
        'related_appointment' => $related_details, // Contiene i dati della zona
        'excluded' => false,
        'excluded_reason' => '',
        'debug_info' => [],
        'note_zona_confinante' => $is_confinante ? "Slot disponibile dalla zona confinante {$related_details['name']}" : ""
    ];
    
    return [
        'slot_details' => $slot_details_prop,
        'priority_score' => $priority_score,
        'travel_distance' => (float)$distanza_utente_centro_zona, // Distanza utente dal centro della zona
        'source' => $is_confinante ? 'neighbor_zone_logic' : 'main_zone_logic'
    ];
}


/**
 * Trova date e orari disponibili per una zona, considerando gli slot configurati e gli appuntamenti esistenti.
 *
 * @param array $slots_config Configurazione degli slot per la zona (da cp_slots).
 * @param int $zoneId ID della zona.
 * @param float $user_latitude Latitudine utente (per futuri controlli di prossimità opzionali).
 * @param float $user_longitude Longitudine utente.
 * @param int $max_operator_hop_km Max distanza tra appuntamenti operatore.
 * @param int $weeks_to_search Numero di settimane nel futuro da cercare (default 4).
 * @return array Date con i relativi orari disponibili.
 */
function getNextAppointmentDatesForZone($slots_config, $zoneId, $user_latitude, $user_longitude, $max_operator_hop_km, $weeks_to_search = 4) {
    global $conn;
    $available_dates_with_times = [];
    $current_date_obj = new DateTime();
    $days_to_check = $weeks_to_search * 7;

    $map_day_to_number = [ // Per mappare i nomi dei giorni della settimana (come in cp_slots) a numeri ISO (1=Lunedì...7=Domenica)
        'Monday' => 1, 'Lunedì' => 1,
        'Tuesday' => 2, 'Martedì' => 2,
        'Wednesday' => 3, 'Mercoledì' => 3,
        'Thursday' => 4, 'Giovedì' => 4,
        'Friday' => 5, 'Venerdì' => 5,
        'Saturday' => 6, 'Sabato' => 6,
        'Sunday' => 7, 'Domenica' => 7,
    ];

    for ($i = 0; $i < $days_to_check; $i++) {
        $check_date_obj = (clone $current_date_obj)->modify("+$i days");
        $check_date_str = $check_date_obj->format('Y-m-d');
        $day_of_week_iso = (int)$check_date_obj->format('N'); // 1 (Mon) to 7 (Sun)

        // Verifica blocchi totali per la data o zona (usando isSlotAvailable da utils_appointment.php)
        $date_availability = isSlotAvailable($check_date_str, null, null, $zoneId); // Verifica l'intera giornata
        if (!$date_availability['available']) {
            continue;
        }

        $slots_for_this_day_type = [];
        foreach ($slots_config as $slot_cfg) {
            // Assumendo che $slot_cfg['day'] sia il nome del giorno in inglese o italiano
            $cfg_day_iso = $map_day_to_number[$slot_cfg['day']] ?? -1;
            if ($cfg_day_iso == $day_of_week_iso) {
                $slots_for_this_day_type[] = $slot_cfg['time'];
            }
        }
        sort($slots_for_this_day_type);

        if (empty($slots_for_this_day_type)) continue; // Nessuno slot configurato per questo giorno della settimana

        $valid_times_for_date = [];
        foreach ($slots_for_this_day_type as $slot_time_str) {
            // Salta slot passati se è oggi
            if ($check_date_str == date('Y-m-d') && strtotime($slot_time_str) < time()) {
                continue;
            }

            // Verifica con isSlotAvailable per lo slot specifico (ora e fine ora)
            $slot_start_time = $slot_time_str;
            $slot_end_time = date('H:i:s', strtotime($slot_start_time . " +1 hour")); // Assumendo slot di 1 ora
            $slot_specific_availability = isSlotAvailable($check_date_str, $slot_start_time, $slot_end_time, $zoneId);

            if (!$slot_specific_availability['available']) {
                continue;
            }

            // Verifica se lo slot è GIA' OCCUPATO in cp_appointments (controllo base)
            $check_booked_sql = "SELECT COUNT(*) as count FROM cp_appointments WHERE appointment_date = ? AND appointment_time = ?";
            // Potremmo voler limitare a zone_id o controllare globalmente a seconda delle regole aziendali
            $chk_stmt = $conn->prepare($check_booked_sql);
            $chk_stmt->bind_param("ss", $check_date_str, $slot_time_str);
            $chk_stmt->execute();
            $is_occupied = ($chk_stmt->get_result()->fetch_assoc()['count'] > 0);
            $chk_stmt->close();

            if (!$is_occupied) {
                $valid_times_for_date[] = $slot_time_str;
            }
        }

        if (!empty($valid_times_for_date)) {
            // Qui andrebbe inserita la logica più avanzata di getNext3AppointmentDates (righe 1222-1384)
            // per verificare la prossimità ad altri appuntamenti GIA' ESISTENTI quel giorno,
            // usando $max_operator_hop_km. Per ora, la omettiamo per semplicità.
            // Se quella logica esclude la data, si farebbe: continue;
            $available_dates_with_times[$check_date_str] = $valid_times_for_date;
        }
         if (count($available_dates_with_times) >= ($weeks_to_search * 2) && $weeks_to_search <=2 ) break; // Limita il numero di giorni con risultati per non eccedere.
         if (count($available_dates_with_times) >= 10 && $weeks_to_search > 2 ) break; // Limita a 10 giorni se la ricerca è più lunga
    }
    ksort($available_dates_with_times);
    return $available_dates_with_times;
}

// Modifica la funzione getNext3AppointmentDates per utilizzare le nuove funzioni
function getNext3AppointmentDates($slots, $zoneId, $userLatitude = null, $userLongitude = null) {
    global $conn;
    $next3Days = [];
    $currentDate = new DateTime();
    $iterationCount = 0;

    // Aggiungi log per debug

    while (count($next3Days) < 3 && $iterationCount < 14) {
        // Esamina i giorni successivi (fino a 28 giorni = 4 settimane)
        $checkDayOfWeek = null; // Initialize $checkDayOfWeek
        for ($dayOffset = $iterationCount * 7; $dayOffset < ($iterationCount + 1) * 7; $dayOffset++) {
            $checkDate = clone $currentDate;
            $checkDate->modify("+$dayOffset days");
            $checkDayOfWeek = $checkDate->format('N'); // 1-7 (Lun-Dom)
            $formattedDate = $checkDate->format('Y-m-d');


            // Verifica se questa data è disponibile negli unavailable slots
            // Passa null per gli orari perché stiamo verificando l'intera giornata
            $dateAvailability = isSlotAvailable($formattedDate, null, null, $zoneId);

            if (!$dateAvailability['available']) {
                continue;
            }

            // NUOVA FUNZIONALITÀ: Verifica se esistono appuntamenti per questa data in qualsiasi zona
            $existingAppsSql = "SELECT a.id, a.address FROM cp_appointments a WHERE a.appointment_date = ?";
            $existingStmt = $conn->prepare($existingAppsSql);
            $existingStmt->bind_param("s", $formattedDate);
            $existingStmt->execute();
            $existingResult = $existingStmt->get_result();
            $existingAppCount = $existingResult->num_rows;


            // Se esistono appuntamenti e abbiamo coordinate dell'utente, verifica la distanza
            if ($existingAppCount > 0 && $userLatitude !== null && $userLongitude !== null) {
                if ($existingAppCount == 1) {
                    // Caso: UN SOLO appuntamento in questa data
                    $app = $existingResult->fetch_assoc();
                    $appAddress = $app['address'];
                    $appId = $app['id'];


                    $appCoords = getCoordinatesFromAddress($appAddress, $appId);

                    if ($appCoords) {
                        $distance = calculateRoadDistance(
                            $userLatitude, $userLongitude,
                            $appCoords['lat'], $appCoords['lng']
                        );


                        if ($distance > 3) {
                            continue; // Skip this date
                        } else {
                        }
                    } else {
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


                            if ($distance > 3) {
                                $allWithinRadius = false;
                                break;
                            }
                        } else {
                            $allWithinRadius = false;
                            break;
                        }
                    }
                    if (!$allWithinRadius) {
                        continue; // Skip this date if not all appointments are within radius
                    }
                }
            }  else {
            }

            // Filtra gli slot configurati per questo giorno della settimana per la zona specifica
            if ($checkDayOfWeek !== null){
                $daySlots = array_filter($slots, function($slot) use ($checkDayOfWeek) {
                    $slotDayOfWeek = date('N', strtotime($slot['day']));
                    return $slotDayOfWeek == $checkDayOfWeek;
                });

                if (empty($daySlots)) {
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
                            throw new Exception("Database prepare failed for adding patient: " . mysqli_error($conn));
                        }
                    
                        $stmt->bind_param("ssss", $name, $surname, $phone, $notes);
                    
                        if (!$stmt->execute()) {
                            throw new Exception("Database query failed for adding patient: " . mysqli_error($conn));
                        }
                    
                        return $conn->insert_id;
                    }
                    
                    // Function to add appointment information to the cp_appointments table
                    function addAppointment($zoneId, $patientId, $appointmentDate, $appointmentTime, $address) {
    global $conn;
    $formattedDate = date('Y-m-d', strtotime($appointmentDate)); // Ensure correct format

    // PATCH: Se zoneId non passato o == 0, ricava la zona dall'indirizzo
    if (!$zoneId || $zoneId == 0) {
        $zone_sql = "SELECT zone_id FROM address_cache_zone_map aczm JOIN address_cache ac ON aczm.address_cache_id = ac.id WHERE ac.address = ? LIMIT 1";
        $zone_stmt = $conn->prepare($zone_sql);
        $zone_stmt->bind_param("s", $address);
        $zone_stmt->execute();
        $zone_res = $zone_stmt->get_result();
        if ($zone_row = $zone_res->fetch_assoc()) {
            $zoneId = $zone_row['zone_id'];
        }
        $zone_stmt->close();
    }

    $sql = "INSERT INTO cp_appointments (zone_id, patient_id, appointment_date, appointment_time, address) VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new Exception("Database prepare failed for adding appointment: " . mysqli_error($conn));
    }

    $stmt->bind_param("iisss", $zoneId, $patientId, $formattedDate, $appointmentTime, $address);

    if (!$stmt->execute()) {
        throw new Exception("Database query failed for adding appointment: " . mysqli_error($conn));
    }
}
                   
                   
                  
// Gestione del POST per la ricerca di appuntamenti
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['address'])) {
        batchUpdateAddressCache($conn);
        
    // Validazione iniziale delle coordinate (come nel tuo originale adattato)
    if (!isset($_POST['latitude']) || !isset($_POST['longitude']) || empty($_POST['latitude']) || empty($_POST['longitude'])) {
        if (ob_get_level() > 0) ob_end_clean();
        echo '<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8"><title>Errore</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body>';
        echo '<div class="container mt-5"><div class="alert alert-danger"><h3><i class="bi bi-exclamation-triangle-fill"></i> Errore</h3><p>Impossibile procedere senza coordinate. Torna indietro e seleziona un indirizzo.</p><p><a href="javascript:history.back()" class="btn btn-primary mt-2">Indietro</a></p></div></div></body></html>';
        exit;
    }

    header('Content-Type: text/html; charset=UTF-8');

    // Recupero variabili POST
    $address_utente = trim($_POST['address']);
    $latitude_utente = (float)$_POST['latitude'];
    $longitude_utente = (float)$_POST['longitude'];
    // PATCH: Aggiorna zone_id per tutti gli appuntamenti con questo indirizzo
$sql_zone = "SELECT aczm.zone_id FROM address_cache_zone_map aczm 
             JOIN address_cache ac ON aczm.address_cache_id = ac.id 
             WHERE ac.address = ? LIMIT 1";
$stmt_zone = $conn->prepare($sql_zone);
$stmt_zone->bind_param("s", $address_utente);
$stmt_zone->execute();
$res_zone = $stmt_zone->get_result();
if ($row_zone = $res_zone->fetch_assoc()) {
    $zone_id_update = $row_zone['zone_id'];
} else {
    $zone_id_update = 0;
}
$upd_stmt = $conn->prepare("UPDATE cp_appointments SET zone_id = ? WHERE address = ?");
$upd_stmt->bind_param("is", $zone_id_update, $address_utente);
$upd_stmt->execute();
$upd_stmt->close();
$stmt_zone->close();
    $display_radius_km = isset($_POST['display_radius']) ? (int)$_POST['display_radius'] : 3; 
    if ($display_radius_km <= 0) $display_radius_km = 3;
    $name_utente = isset($_POST['name']) ? trim($_POST['name']) : ($name ?? ''); // $name da GET originale
    $surname_utente = isset($_POST['surname']) ? trim($_POST['surname']) : ($surname ?? ''); // $surname da GET originale
    $phone_utente = isset($_POST['phone']) ? trim($_POST['phone']) : ($phone ?? ''); // $phone da GET originale

    // Assicurati che MAX_OPERATOR_HOP_KM sia definita (dovrebbe essere all'inizio del file)
    if (!defined('MAX_OPERATOR_HOP_KM')) {
        define('MAX_OPERATOR_HOP_KM', 15); // Fallback se non definita prima
    }

    $log_prefix_main = "RICERCA POST (FLUSSO CORRETTO): UserAddr='{$address_utente}', UserLat={$latitude_utente}, UserLng={$longitude_utente}, DisplayRadius={$display_radius_km}km | ";

    try {
        // --- BLOCCO AVVISO APPUNTAMENTO ESISTENTE SULLO STESSO INDIRIZZO (DAL TUO ORIGINALE) ---
        // (Questo blocco è identico a quello che ti ho fornito nell'ultima risposta,
        //  che a sua volta era basato sul tuo originale. Va da riga ~1466 a ~1568 dell'originale)
        $existingAppointmentForAddress = null;
        $today_date_for_check = date('Y-m-d'); // Usa un nome variabile diverso da $today se $today è usato dopo
        $checkAddressSql = "SELECT a.id, a.appointment_date, a.appointment_time, p.name AS patient_name, p.surname AS patient_surname, z.name AS zone_name, a.address AS appointment_address
                            FROM cp_appointments a JOIN cp_patients p ON a.patient_id = p.id LEFT JOIN cp_zones z ON a.zone_id = z.id
                            WHERE a.address = ? AND a.appointment_date >= ? ORDER BY a.appointment_date, a.appointment_time LIMIT 1";
        $checkAddressStmt = $conn->prepare($checkAddressSql);
        $proceedSearchAnyway_div_open = false; // Flag per gestire la chiusura del div
        if ($checkAddressStmt) {
            $checkAddressStmt->bind_param("ss", $address_utente, $today_date_for_check);
            $checkAddressStmt->execute();
            $existingResult = $checkAddressStmt->get_result();
            if ($existingAppointmentForAddress = $existingResult->fetch_assoc()) {
                $formatted_date = date("d/m/Y", strtotime($existingAppointmentForAddress['appointment_date']));
                $formatted_time = date("H:i", strtotime($existingAppointmentForAddress['appointment_time']));
                $patient_full_name = htmlspecialchars($existingAppointmentForAddress['patient_name'] . " " . $existingAppointmentForAddress['patient_surname']);
                $zone_display = $existingAppointmentForAddress['zone_name'] ? htmlspecialchars($existingAppointmentForAddress['zone_name']) : "N/D";
                echo "<div class='container mt-4'><div class='alert alert-warning' role='alert'>";
                echo "<h4 class='alert-heading'><i class='bi bi-exclamation-triangle-fill'></i> Attenzione: Appuntamento Esistente</h4>";
                echo "<p>Risulta già un appuntamento per l'indirizzo <strong>" . htmlspecialchars($address_utente) . "</strong> a nome di <strong>{$patient_full_name}</strong> per il <strong>{$formatted_date}</strong> alle <strong>{$formatted_time}</strong> (Zona: {$zone_display}).</p><hr>";
                echo "<p class='mb-0'>Procedere con la ricerca di un nuovo slot o gestire l'appuntamento esistente?</p>";
                echo "<div class='mt-3'><button onclick='document.getElementById(\"proceedSearchAnyway\").style.display=\"block\"; this.parentElement.style.display=\"none\";' class='btn btn-primary me-2'>Cerca Nuovi Slot Comunque</button></div></div>";
                echo "<div id='proceedSearchAnyway' style='display:none;'>"; // Questo div conterrà i risultati se si procede
                $proceedSearchAnyway_div_open = true; 
            }
            $checkAddressStmt->close();
        } else { }
        
        // Output header della ricerca (dentro o fuori dal div 'proceedSearchAnyway')
      // Output header della ricerca (dentro o fuori dal div 'proceedSearchAnyway')
echo "<div class='container mt-4' style='text-align:left;'>"; 
echo "<div class='card mb-4'><div class='card-body'>";
echo "<h2 class='card-title text-center mb-3'>Risultati Ricerca per: <span style='color:green; font-weight:bold;'>{$address_utente}</span></h2>";

// Aggiunta: Ottieni informazioni sulle zone dell'indirizzo
$zona_principale = getZoneForCoordinates($latitude_utente, $longitude_utente);
$zone_info_text = "";

if ($zona_principale) {
    $zona_info_text = "Zona principale: <strong>{$zona_principale['name']} (ID: {$zona_principale['id']})</strong>";
    
    // Cerca anche eventuali zone confinanti entro un raggio ravvicinato (1-2 km)
    $raggio_vicino = 2; // km
    $altre_zone_vicine = [];
    
    $sql_zone_vicine = "SELECT id, name, latitude AS zone_lat, longitude AS zone_lng, radius_km FROM cp_zones WHERE id != ?";
    $stmt_zone_vicine = $conn->prepare($sql_zone_vicine);
    
    if ($stmt_zone_vicine) {
        $stmt_zone_vicine->bind_param("i", $zona_principale['id']);
        $stmt_zone_vicine->execute();
        $result_zone_vicine = $stmt_zone_vicine->get_result();
        
        while ($altra_zona = $result_zone_vicine->fetch_assoc()) {
            $distanza = calculateDistance(
                [$latitude_utente, $longitude_utente],
                [(float)$altra_zona['zone_lat'], (float)$altra_zona['zone_lng']]
            );
            
            // Se l'indirizzo è vicino al confine di un'altra zona
            if ($distanza <= $raggio_vicino + (float)$altra_zona['radius_km']) {
                $altra_zona['distance'] = $distanza;
                $altre_zone_vicine[] = $altra_zona;
            }
        }
        
        if (!empty($altre_zone_vicine)) {
            $zona_info_text .= "<br>Zone confinanti: ";
            $zone_names = [];
            
            foreach ($altre_zone_vicine as $zona_vicina) {
                $dist_km = number_format($zona_vicina['distance'], 1);
                $zone_names[] = "<strong>{$zona_vicina['name']} (ID: {$zona_vicina['id']}, {$dist_km} km)</strong>";
            }
            
            $zona_info_text .= implode(", ", $zone_names);
        }
        
        $stmt_zone_vicine->close();
    }
} else {
    $zona_info_text = "Indirizzo non appartiene a nessuna zona configurata";
}

echo "<p class='text-center'>{$zona_info_text}</p>";
echo "<p class='text-center'>Coordinate: Lat {$latitude_utente}, Lng {$longitude_utente}. Raggio Visualizzazione: <strong>{$display_radius_km} km</strong></p>";
echo "</div></div><hr>";
        // --- FINE BLOCCO AVVISO APPUNTAMENTO ESISTENTE ---

        $slots_proposti_con_priorita = []; 
        $tutti_gli_slot_adiacenti_per_tabella = []; // Per displayAppointmentDetails

        // -------- FASE A: Slot Adiacenti ad Appuntamenti Esistenti --------
        $appuntamenti_riferimento = findNearbyAppointments($latitude_utente, $longitude_utente, $display_radius_km); // USA FUNZIONE ORIGINALE

        if (!empty($appuntamenti_riferimento)) {
            foreach ($appuntamenti_riferimento as $ref_app) {
                $ref_app_id_log = $ref_app['id'] ?? 'N/D';
                // Se findNearbyAppointments ha escluso il riferimento, lo aggiungiamo per la tabella ma non generiamo slot.
                if (!empty($ref_app['excluded_reason'])) {
                    $tutti_gli_slot_adiacenti_per_tabella[] = ['type' => 'N/A_REF_EXCLUDED', 'related_appointment' => $ref_app, 'excluded' => true, 'excluded_reason' => "Rif. escluso da findNearby: " . $ref_app['excluded_reason'], 'debug_info' => ['reason' => $ref_app['excluded_reason']]];
                    continue;
                }
               if (!isset($ref_app['latitude']) || !isset($ref_app['longitude']) || $ref_app['latitude'] === '' || $ref_app['longitude'] === '') {
    $missing_data_reason = "Dati rif. incompleti (lat/lng) per ID {$ref_app_id_log}.";
    $tutti_gli_slot_adiacenti_per_tabella[] = ['type' => 'N/A_REF_INVALID_DATA', 'related_appointment' => $ref_app, 'excluded' => true, 'excluded_reason' => $missing_data_reason, 'debug_info' => []];
    continue;
}
// Non escludere per zona mancante!

                $slot_adiacenti_da_originale = checkAvailableSlotsNearAppointment($ref_app); // USA FUNZIONE ORIGINALE

                foreach ($slot_adiacenti_da_originale as $slot_calc_orig) {
                    $slot_per_tabella = $slot_calc_orig; // Copia per modificarla senza alterare l'originale se necessario
                    $slot_per_tabella['related_appointment'] = $ref_app; // Assicura che related_appointment sia presente
                    
                    $actual_travel_for_this_slot = false;
                    if (!$slot_per_tabella['excluded']) { // Solo se valido secondo la logica originale
                        if ($slot_per_tabella['type'] == 'before') {
                            $actual_travel_for_this_slot = calculateRoadDistance($latitude_utente, $longitude_utente, (float)$ref_app['latitude'], (float)$ref_app['longitude']);
                        } elseif ($slot_per_tabella['type'] == 'after') {
                            $actual_travel_for_this_slot = calculateRoadDistance((float)$ref_app['latitude'], (float)$ref_app['longitude'], $latitude_utente, $longitude_utente);
                        }
                        // Applica filtro MAX_OPERATOR_HOP_KM al viaggio specifico NuovoUtente<->RefApp
                        if ($actual_travel_for_this_slot === false || $actual_travel_for_this_slot < 0 || $actual_travel_for_this_slot > MAX_OPERATOR_HOP_KM) {
                            $slot_per_tabella['excluded'] = true;
                            $current_reason = $slot_per_tabella['excluded_reason'] ?? '';
                            $slot_per_tabella['excluded_reason'] = ($current_reason ? $current_reason." | " : "") . "Viaggio NuovoApp<->RefApp (" . number_format($actual_travel_for_this_slot,1) . "km) > " . MAX_OPERATOR_HOP_KM . "km.";
                            if (!isset($slot_per_tabella['debug_info'])) $slot_per_tabella['debug_info'] = [];
                            $slot_per_tabella['debug_info']['hop_km_new_ref_fail'] = true;
                        }
                    }
                    $slot_per_tabella['actual_travel_distance_for_new_slot'] = $actual_travel_for_this_slot; // Per priorità
                    $tutti_gli_slot_adiacenti_per_tabella[] = $slot_per_tabella;

                    if (!$slot_per_tabella['excluded']) {
                        if ($actual_travel_for_this_slot !== false && $actual_travel_for_this_slot >= 0 && $actual_travel_for_this_slot <= $display_radius_km) {
                            $slots_proposti_con_priorita[] = [
                                'slot_details'      => $slot_per_tabella,
                                'priority_score'    => (float)$actual_travel_for_this_slot,
                                'travel_distance'   => (float)$actual_travel_for_this_slot,
                                'source'            => 'adjacent_to_existing'
                            ];
                        }
                    }
                }
            }
        }

// --- INIZIO LOGICA SLOT EXTRA UNIVERSALE (solo distanza 3km) ---

$today = date('Y-m-d');
// Ottieni tutte le date future con appuntamenti
$dates_sql = "SELECT DISTINCT appointment_date FROM cp_appointments WHERE appointment_date >= ?";
$stmt_dates = $conn->prepare($dates_sql);
$stmt_dates->bind_param("s", $today);
$stmt_dates->execute();
$res_dates = $stmt_dates->get_result();
$date_list = [];
while ($row = $res_dates->fetch_assoc()) {
    $date_list[] = $row['appointment_date'];
}
$stmt_dates->close();

foreach ($date_list as $thedate) {
    // Trova l'ultimo appuntamento della giornata (indipendentemente dalla zona)
    $sql_last = "SELECT * FROM cp_appointments WHERE appointment_date = ? ORDER BY appointment_time DESC LIMIT 1";
    $stmt_last = $conn->prepare($sql_last);
    $stmt_last->bind_param("s", $thedate);
    $stmt_last->execute();
    $res_last = $stmt_last->get_result();
    if ($last_app = $res_last->fetch_assoc()) {
        // Trova la zona effettiva dell'appuntamento (serve per orario massimo)
        $zone_id = $last_app['zone_id'];
        $coords = getCoordinatesForAppointment($last_app['id'], $last_app['address']);
        if ($coords) {
            $distanza = calculateRoadDistance($latitude_utente, $longitude_utente, $coords['lat'], $coords['lng']);
            if ($distanza !== false && $distanza <= 3) {
                // Recupera orario massimo della zona di quell'appuntamento
                $sql_slot = "SELECT MAX(time) as latest_slot FROM cp_slots WHERE zone_id = ?";
                $stmt_slot = $conn->prepare($sql_slot);
                $stmt_slot->bind_param("i", $zone_id);
                $stmt_slot->execute();
                $res_slot = $stmt_slot->get_result();
                $slot_row = $res_slot->fetch_assoc();
                $zone_max_slot_time = $slot_row ? $slot_row['latest_slot'] : null;
                $stmt_slot->close();
                if ($zone_max_slot_time) {
                    // Calcola lo slot extra (1 ora dopo l'ultimo slot)
                    $orario_extra = date('H:i:s', strtotime($zone_max_slot_time) + 3600);
                    // Verifica che non esista già un appuntamento a quell'orario
                    $sql_check = "SELECT COUNT(*) as count FROM cp_appointments WHERE appointment_date = ? AND appointment_time = ?";
                    $stmt_check = $conn->prepare($sql_check);
                    $stmt_check->bind_param("ss", $thedate, $orario_extra);
                    $stmt_check->execute();
                    $res_check = $stmt_check->get_result();
                    $row_check = $res_check->fetch_assoc();
                    $stmt_check->close();
                    if ($row_check['count'] == 0) {
                        // Proponi slot extra!
                        $slot_extra = [
                            'date' => $thedate,
                            'time' => $orario_extra,
                            'type' => 'after_extra',
                            'related_appointment' => array_merge($last_app, ['zone_id' => $zone_id]), // assicura che zone_id sia sempre presente
                            'excluded' => false,
                            'excluded_reason' => '',
                            'debug_info' => [],
                            'extra_warning' => "Slot fuori orario: proposto perché l'indirizzo è entro 3km (stradali) dall’ultimo appuntamento della giornata (ID: {$last_app['id']}, zona: $zone_id)"
                        ];
                        $slots_proposti_con_priorita[] = [
                            'slot_details' => $slot_extra,
                            'priority_score' => 10000 + $distanza,
                            'travel_distance' => $distanza,
                            'source' => 'extra_after_last'
                        ];
                        $tutti_gli_slot_adiacenti_per_tabella[] = $slot_extra;
                    }
                }
            }
        }
    }
    $stmt_last->close();
}
// --- FINE LOGICA SLOT EXTRA ---


        // -------- CHIAMATA A displayAppointmentDetails (ORIGINALE) --------
        if (function_exists('displayAppointmentDetails')) {
            if (!empty($appuntamenti_riferimento)) {
                echo <<<HTML
<div class="container mt-3">
    <button class="btn btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#toggleTabellaApp" aria-expanded="false" aria-controls="toggleTabellaApp">
        Visualizza i processi
    </button>
    <div class="collapse mt-3" id="toggleTabellaApp">
        <div class="card card-body">
HTML;

displayAppointmentDetails($appuntamenti_riferimento, $tutti_gli_slot_adiacenti_per_tabella);

echo <<<HTML
        </div>
    </div>
</div>
HTML; // USA FUNZIONE ORIGINALE
            } else {
                 echo "<div class='alert alert-info mt-3'>Nessun appuntamento di riferimento trovato nelle vicinanze per generare slot adiacenti.</div>";
            }
        } else {
            echo "<div class='alert alert-danger mt-3'>Errore: funzione display non disponibile.</div>";
        }

/**
 * Funzione di supporto per estrarre i range orari dai slot configurati
 */
function extractTimeRanges($slots_config) {
    $time_ranges = [];
    foreach ($slots_config as $slot) {
        $day = $slot['day'];
        $time = $slot['time'];
        
        if (!isset($time_ranges[$day])) {
            $time_ranges[$day] = ['min' => $time, 'max' => $time];
        } else {
            if (strtotime($time) < strtotime($time_ranges[$day]['min'])) {
                $time_ranges[$day]['min'] = $time;
            }
            if (strtotime($time) > strtotime($time_ranges[$day]['max'])) {
                $time_ranges[$day]['max'] = $time;
            }
        }
    }
    return $time_ranges;
}

/**
 * Funzione di supporto per verificare se una zona ha una fascia oraria più ampia
 */
function hasWiderTimeRange($zona_conf_ranges, $zona_utente_ranges) {
    foreach ($zona_conf_ranges as $day => $conf_range) {
        // Se questo giorno esiste anche nella zona utente
        if (isset($zona_utente_ranges[$day])) {
            $utente_range = $zona_utente_ranges[$day];
            
            // Controlla se la zona confinante ha un orario di inizio precedente
            if (strtotime($conf_range['min']) < strtotime($utente_range['min'])) {
                return true;
            }
            
            // O se ha un orario di fine successivo
            if (strtotime($conf_range['max']) > strtotime($utente_range['max'])) {
                return true;
            }
        } else {
            // Se questo giorno non esiste nella zona utente, allora ha una fascia più ampia
            return true;
        }
    }
    
    return false;
}
// Nella FASE B dove viene determinata la zona dell'utente e vengono cercati gli slot disponibili

$zona_utente = getZoneForCoordinates($latitude_utente, $longitude_utente); // Funzione helper già presente

// NUOVO: Cerca anche zone confinanti entro un raggio ragionevole (es. 3km)
$zone_confinanti = [];
$raggio_confine = 3; // km - raggio entro cui considerare un'altra zona come confinante
$sql_zone_confinanti = "SELECT id, name, latitude AS zone_lat, longitude AS zone_lng, radius_km FROM cp_zones WHERE id != ?";
$stmt_zone_conf = $conn->prepare($sql_zone_confinanti);

if ($stmt_zone_conf) {
    $stmt_zone_conf->bind_param("i", $zona_utente['id']);
    $stmt_zone_conf->execute();
    $result_zone_conf = $stmt_zone_conf->get_result();
    
    while ($altra_zona = $result_zone_conf->fetch_assoc()) {
        // Calcola distanza tra l'utente e il centro dell'altra zona
        $distanza_zona = calculateDistance(
            [$latitude_utente, $longitude_utente],
            [(float)$altra_zona['zone_lat'], (float)$altra_zona['zone_lng']]
        );
        
        // Se l'utente è abbastanza vicino al confine dell'altra zona
        if ($distanza_zona <= $raggio_confine + (float)$altra_zona['radius_km']) {
            $altra_zona['distance_from_user'] = $distanza_zona;
            $zone_confinanti[] = $altra_zona;
        }
    }
    $stmt_zone_conf->close();
}

// Raccogli tutti gli slot di tutte le zone rilevanti (zona utente + zone confinanti)
$tutte_zone_rilevanti = [$zona_utente['id']];
$tutti_slots_config = [];
$orari_zona_utente = []; // Memorizza gli orari della zona dell'utente per confronto

if ($zona_utente) {
    $slots_config_zona_utente = getSlotsForZone($zona_utente['id']); // Funzione ORIGINALE
    
    if (!empty($slots_config_zona_utente)) {
        $tutti_slots_config = $slots_config_zona_utente;
        $orari_zona_utente = extractTimeRanges($slots_config_zona_utente);
    } else { 
    }
    
    // Ora aggiungi slot delle zone confinanti se hanno orari più ampi
    foreach ($zone_confinanti as $zona_conf) {
        $slots_config_zona_conf = getSlotsForZone($zona_conf['id']);
        
        if (!empty($slots_config_zona_conf)) {
            $orari_zona_conf = extractTimeRanges($slots_config_zona_conf);
            
            // Verifica se la zona confinante ha una fascia oraria più ampia
            if (hasWiderTimeRange($orari_zona_conf, $orari_zona_utente)) {
                $tutte_zone_rilevanti[] = $zona_conf['id'];
                
                // Aggiungi gli slot della zona confinante
                foreach ($slots_config_zona_conf as $slot_conf) {
                    // Aggiungi al totale solo se non esiste già uno slot identico
                    $slot_exists = false;
                    foreach ($tutti_slots_config as $existing_slot) {
                        if ($existing_slot['day'] == $slot_conf['day'] && $existing_slot['time'] == $slot_conf['time']) {
                            $slot_exists = true;
                            break;
                        }
                    }
                    if (!$slot_exists) {
                        $tutti_slots_config[] = $slot_conf;
                        // Aggiungi un marcatore per indicare che questo slot proviene da una zona confinante
                        $last_index = count($tutti_slots_config) - 1;
                        $tutti_slots_config[$last_index]['from_neighboring_zone'] = true;
                        $tutti_slots_config[$last_index]['original_zone_id'] = $zona_conf['id'];
                    }
                }
            } else {
            }
        }
    }
    
    // Ora usa tutti gli slot raccolti per generare date disponibili
    if (!empty($tutti_slots_config)) {
        
        // Passa la zona dell'utente, ma con tutti gli slot combinati
        // Verifica se la zona utente ha configurazione di slot
$slots_config_zona_utente = getSlotsForZone($zona_utente['id']);
if (empty($slots_config_zona_utente)) {
    // Usa tutti gli slot in questo caso
    $slots_config_zona_utente = $tutti_slots_config;
}

// Cerca per un periodo più lungo (20 settimane)
$date_disponibili_zona_da_originale = getNextAppointmentDatesForZone(
    $slots_config_zona_utente, $zona_utente['id'], $latitude_utente, $longitude_utente, 
    MAX_OPERATOR_HOP_KM, 20 // 20 settimane per garantire risultati
);


        
        foreach ($date_disponibili_zona_da_originale as $date_str_orig => $times_arr_orig) {
            foreach ($times_arr_orig as $time_str_orig) {
                // Determina da quale zona proviene questo slot
                $zona_source_id = $zona_utente['id'];
                $zona_source_name = $zona_utente['name'] ?? 'Sconosciuta';
                
                // Controlla se lo slot proviene da una zona confinante
                foreach ($tutti_slots_config as $slot_cfg) {
                    // Controlla se questo è lo slot corrente e viene da una zona confinante
                    $slot_time = date('H:i:s', strtotime($time_str_orig));
                    if (isset($slot_cfg['from_neighboring_zone']) && 
                        $slot_cfg['time'] == $slot_time &&
                        isset($slot_cfg['original_zone_id'])) {
                        
                        $zona_source_id = $slot_cfg['original_zone_id'];
                        
                        // Trova il nome della zona confinante
                        foreach ($zone_confinanti as $zc) {
                            if ($zc['id'] == $zona_source_id) {
                                $zona_source_name = $zc['name'] ?? 'Confinante';
                                break;
                            }
                        }
                        break;
                    }
                }
                
                // Crea i dettagli dello slot, includendo l'informazione sulla zona di origine
                $related_details_zona_prop = [
                    'id' => null, 
                    'address' => 'N/A - Slot di Zona', 
                    'zone_id' => $zona_source_id,  // Usa la zona da cui proviene lo slot 
                    'name' => $zona_source_name,  // Nome della zona
                    'latitude' => ($zona_utente['zone_lat'] ?? 0),
                    'longitude' => ($zona_utente['zone_lng'] ?? 0),
                    'is_neighboring_zone' => ($zona_source_id != $zona_utente['id'])  // Flag per slot di zona confinante
                ];
                
                $slot_details_prop = [
                    'date' => $date_str_orig, 
                    'time' => $time_str_orig, 
                    'type' => 'zone_based', 
                    'related_appointment' => $related_details_zona_prop, 
                    'excluded' => false,
                    'excluded_reason' => '', 
                    'debug_info' => []
                ];
                
                // Aggiungi una nota se lo slot proviene da una zona confinante
                if ($zona_source_id != $zona_utente['id']) {
                    $slot_details_prop['note_zona_confinante'] = "Slot disponibile dalla zona confinante {$zona_source_name} (ID: {$zona_source_id})";
                }
                
                $slots_proposti_con_priorita[] = [
                    'slot_details' => $slot_details_prop, 
                    'priority_score' => 1000 + (float)($zona_utente['distance_from_user_address'] ?? 0), 
                    'travel_distance' => (float)($zona_utente['distance_from_user_address'] ?? 0),
                    'source' => 'zone_logic'
                ];
            }
        }
    }
} else { 
}




// -------- FASE C: Ordinamento e Visualizzazione Finale degli Slot Selezionabili --------
echo "<div class='container mt-3' style='text-align:left;'>"; 
if (!empty($slots_proposti_con_priorita)) {
    // Dividi gli slot in due categorie: adiacenti ad appuntamenti esistenti e slot di zona
    $slots_adiacenti = [];
    $slots_by_zone_and_date = []; // Struttura: [zone_id][date] = array di slot
    
    // Per il debug
    
 foreach ($slots_proposti_con_priorita as $item) {
    if ($item['slot_details']['excluded']) {
        continue;
    }
    // PATCH: includi anche gli slot extra tra gli adiacenti selezionabili!
    if ($item['source'] == 'adjacent_to_existing' || $item['source'] == 'extra_after_last') {
    $slots_adiacenti[] = $item;
} else {
        // Slot di zona - raggruppa per zona e data
        $date = $item['slot_details']['date'];
        $zone_id = $item['slot_details']['related_appointment']['zone_id'] ?? 0;
        $zone_name = $item['slot_details']['related_appointment']['name'] ?? "Zona $zone_id";
        if (!isset($slots_by_zone_and_date[$zone_id])) {
            $slots_by_zone_and_date[$zone_id] = [
                'zone_name' => $zone_name,
                'dates' => []
            ];
        }
        if (!isset($slots_by_zone_and_date[$zone_id]['dates'][$date])) {
            $slots_by_zone_and_date[$zone_id]['dates'][$date] = [];
        }
        $slots_by_zone_and_date[$zone_id]['dates'][$date][] = $item;
    }
}
    
    // Mantieni l'ordinamento originale per priorità/distanza per gli slot adiacenti
   usort($slots_adiacenti, function($a, $b) use ($appuntamenti_riferimento, $latitude_utente, $longitude_utente) {
    $find_prev = function($slot, $apps) {
        $target_date = $slot['date'];
        $target_time = $slot['time'];
        $before = null;
        foreach ($apps as $app) {
            if (!empty($app['excluded_reason'])) continue;
            if ($app['appointment_date'] == $target_date && $app['appointment_time'] < $target_time) {
                if (!$before || $app['appointment_time'] > $before['appointment_time']) {
                    $before = $app;
                }
            }
        }
        return $before;
    };
    $find_next = function($slot, $apps) {
        $target_date = $slot['date'];
        $target_time = $slot['time'];
        $after = null;
        foreach ($apps as $app) {
            if (!empty($app['excluded_reason'])) continue;
            if ($app['appointment_date'] == $target_date && $app['appointment_time'] > $target_time) {
                if (!$after || $app['appointment_time'] < $after['appointment_time']) {
                    $after = $app;
                }
            }
        }
        return $after;
    };

    $slotA = $a['slot_details'];
    $slotB = $b['slot_details'];

    // --- SLOT A ---
    $prevA = $find_prev($slotA, $appuntamenti_riferimento);
    $nextA = $find_next($slotA, $appuntamenti_riferimento);
    $distA = 9999;
    if ($prevA && $nextA) {
        $distA_prev = calculateRoadDistance($latitude_utente, $longitude_utente, $prevA['latitude'], $prevA['longitude']);
        $distA_next = calculateRoadDistance($latitude_utente, $longitude_utente, $nextA['latitude'], $nextA['longitude']);
        $distA = min($distA_prev, $distA_next);
    } elseif ($prevA) {
        $distA = calculateRoadDistance($latitude_utente, $longitude_utente, $prevA['latitude'], $prevA['longitude']);
    } elseif ($nextA) {
        $distA = calculateRoadDistance($latitude_utente, $longitude_utente, $nextA['latitude'], $nextA['longitude']);
    }

    // --- SLOT B ---
    $prevB = $find_prev($slotB, $appuntamenti_riferimento);
    $nextB = $find_next($slotB, $appuntamenti_riferimento);
    $distB = 9999;
    if ($prevB && $nextB) {
        $distB_prev = calculateRoadDistance($latitude_utente, $longitude_utente, $prevB['latitude'], $prevB['longitude']);
        $distB_next = calculateRoadDistance($latitude_utente, $longitude_utente, $nextB['latitude'], $nextB['longitude']);
        $distB = min($distB_prev, $distB_next);
    } elseif ($prevB) {
        $distB = calculateRoadDistance($latitude_utente, $longitude_utente, $prevB['latitude'], $prevB['longitude']);
    } elseif ($nextB) {
        $distB = calculateRoadDistance($latitude_utente, $longitude_utente, $nextB['latitude'], $nextB['longitude']);
    }

    // Ordina per distanza MINIMA
    if ($distA != $distB) return $distA <=> $distB;

    // Se distanza uguale, ordina per data/ora
    $dtA = strtotime(($slotA['date'] ?? '') . ' ' . ($slotA['time'] ?? ''));
    $dtB = strtotime(($slotB['date'] ?? '') . ' ' . ($slotB['time'] ?? ''));
    return $dtA <=> $dtB;
});
    
    function format_distance_value($distance, $is_used, $radius) {
    $formatted = number_format($distance, 1) . " km";
    $in_red = ($distance > $radius); // rosso solo se maggiore STRETTO
    if ($is_used && $in_red)   return "<b><span style='color:red;'>$formatted</span></b>";
    if ($is_used)              return "<b>$formatted</b>";
    if ($in_red)               return "<span style='color:red;'>$formatted</span>";
    return $formatted;
}
    
    
    // 1. Prima mostra gli slot adiacenti con la visualizzazione originale dettagliata
    if (!empty($slots_adiacenti)) {
        echo "<h3 class='text-center mb-3 mt-4'>Slot disponibili vicino ad appuntamenti esistenti</h3>";
        $count_displayed_sel = 0;
        
        // Ordinamento dinamico degli slot adiacenti in base alle distanze da appuntamenti precedenti/successivi
usort($slots_adiacenti, function($a, $b) use ($appuntamenti_riferimento) {
    $slotA = $a['slot_details'];
    $slotB = $b['slot_details'];

    // Helper per trovare l'appuntamento precedente/successivo nel giorno
    $find_prev = function($slot, $apps) {
        $target_date = $slot['date'];
        $target_time = $slot['time'];
        $before = null;
        foreach ($apps as $app) {
            if (!empty($app['excluded_reason'])) continue;
            if ($app['appointment_date'] == $target_date && $app['appointment_time'] < $target_time) {
                if (!$before || $app['appointment_time'] > $before['appointment_time']) {
                    $before = $app;
                }
            }
        }
        return $before;
    };
    $find_next = function($slot, $apps) {
        $target_date = $slot['date'];
        $target_time = $slot['time'];
        $after = null;
        foreach ($apps as $app) {
            if (!empty($app['excluded_reason'])) continue;
            if ($app['appointment_date'] == $target_date && $app['appointment_time'] > $target_time) {
                if (!$after || $app['appointment_time'] < $after['appointment_time']) {
                    $after = $app;
                }
            }
        }
        return $after;
    };

    // Calcola distanza da prev e next per entrambi gli slot
    $distA = 9999; $distB = 9999;

    $prevA = $find_prev($slotA, $appuntamenti_riferimento);
    $nextA = $find_next($slotA, $appuntamenti_riferimento);
    $prevB = $find_prev($slotB, $appuntamenti_riferimento);
    $nextB = $find_next($slotB, $appuntamenti_riferimento);

    // Funzione di priorità:
    // 1. Se esiste prev, ordina per distanza crescente da prev (slot più vicino viene prima)
    // 2. Se non esiste prev ma esiste next, ordina per distanza crescente da next
    // 3. Se entrambi, usa solo prev
    // Usa la funzione calculateRoadDistance per il calcolo

    global $latitude_utente, $longitude_utente;

    if ($prevA) {
        $distA = calculateRoadDistance($latitude_utente, $longitude_utente, $prevA['latitude'], $prevA['longitude']);
    } elseif ($nextA) {
        $distA = calculateRoadDistance($latitude_utente, $longitude_utente, $nextA['latitude'], $nextA['longitude']);
    }
    if ($prevB) {
        $distB = calculateRoadDistance($latitude_utente, $longitude_utente, $prevB['latitude'], $prevB['longitude']);
    } elseif ($nextB) {
        $distB = calculateRoadDistance($latitude_utente, $longitude_utente, $nextB['latitude'], $nextB['longitude']);
    }

    // Ordina per distanza crescente (slot più vicino sopra)
    if ($distA != $distB) return $distA <=> $distB;
    // Altrimenti ordina per data/ora
    $dtA = strtotime(($slotA['date'] ?? '') . ' ' . ($slotA['time'] ?? ''));
    $dtB = strtotime(($slotB['date'] ?? '') . ' ' . ($slotB['time'] ?? ''));
    return $dtA <=> $dtB;
});
        
        
  if (!empty($slots_adiacenti)) {
    echo "<h3 class='text-center mb-3 mt-4'>Slot EXTRA ORARIO possibili</h3>";
    $count_displayed_sel = 0;

    foreach ($slots_adiacenti as $item) {
        $slot = $item['slot_details'];
        $slot_date_fmt = date('d/m/Y', strtotime($slot['date']));
        $giorni = ['Domenica', 'Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato'];
        $giorno_nome = $giorni[date('w', strtotime($slot['date']))];
        $slot_time_fmt = date('H:i', strtotime($slot['time']));
        $related_sel = $slot['related_appointment'];
        $zone_id_book = $related_sel['zone_id'] ?? 'N/D_Zone';
        $ref_time_sel = isset($related_sel['appointment_time']) ? date('H:i', strtotime($related_sel['appointment_time'])) : 'N/D';

        // Descrizione tipo slot
        $type_desc_sel = ($slot['type'] == 'before')
            ? "Prima app. {$ref_time_sel} in"
            : (($slot['type'] == 'after' || $slot['type'] == 'after_extra')
                ? "Dopo app. {$ref_time_sel} in"
                : "Slot speciale");

        // Evidenziazione rossa per gli slot extra
        $card_class = 'card mb-3 shadow-sm';
        if ($slot['type'] == 'after_extra' || isset($slot['extra_warning'])) {
            $card_class .= ' border-danger';
        }

        echo "<div class='$card_class'><div class='card-body'>";
        echo "<h4 class='card-title'>{$giorno_nome} {$slot_date_fmt} ore {$slot_time_fmt}</h4>";

        // Messaggio rosso di warning per gli slot extra
        if (isset($slot['extra_warning'])) {
            echo "<div class='alert alert-danger fw-bold mb-2'>{$slot['extra_warning']}</div>";
        }

        echo "<p class='card-text'><strong>Proposto perché {$type_desc_sel}</strong>: " . htmlspecialchars($related_sel['address'] ?? 'N/D') . "<br>";

        // Distanze di viaggio
        echo "<span style='color:#28a745;font-weight:bold;'>Distanze di viaggio:</span><br>";

      // Appuntamenti stessi giorno per il calcolo distanze
$app_date = $slot['date'] ?? date('Y-m-d');
$app_time = $slot['time'];

// Recupera TUTTI gli appuntamenti fissati in quel giorno, senza filtri di distanza o zona
$same_day_appointments = [];
$sql = "SELECT * FROM cp_appointments WHERE appointment_date = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $app_date);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    // Recupera anche lat/lng per ogni appuntamento se mancante
    if (empty($row['latitude']) || empty($row['longitude'])) {
        $coords = getCoordinatesForAppointment($row['id'], $row['address']);
        if ($coords) {
            $row['latitude'] = $coords['lat'];
            $row['longitude'] = $coords['lng'];
        }
    }
    $same_day_appointments[] = $row;
}
$stmt->close();

// Ordina per orario crescente
usort($same_day_appointments, function($a, $b) {
    return strtotime($a['appointment_time']) - strtotime($b['appointment_time']);
});

$app_time_ts = strtotime($app_time);
$closest_before = null;
$closest_after = null;
foreach ($same_day_appointments as $app) {
    $app_ts = strtotime($app['appointment_time']);
    if ($app_ts < $app_time_ts) {
        if (!$closest_before || strtotime($app['appointment_time']) > strtotime($closest_before['appointment_time'])) {
            $closest_before = $app;
        }
    } else if ($app_ts > $app_time_ts) {
        if (!$closest_after || strtotime($app['appointment_time']) < strtotime($closest_after['appointment_time'])) {
            $closest_after = $app;
        }
    }
}

        // Calcola distanze
        $dist_prev = $closest_before ? calculateRoadDistance(
            $latitude_utente, $longitude_utente,
            $closest_before['latitude'], $closest_before['longitude']
        ) : null;
        $dist_next = $closest_after ? calculateRoadDistance(
            $latitude_utente, $longitude_utente,
            $closest_after['latitude'], $closest_after['longitude']
        ) : null;

        if ($dist_prev !== null) {
            $ora_prev = $closest_before ? date('H:i', strtotime($closest_before['appointment_time'])) : $ref_time_sel;
            echo "<span style='color:#28a745;'> - Distanza dal precedente ({$ora_prev}): " . number_format($dist_prev, 2) . " km</span><br>";
        }
        if ($dist_next !== null) {
            $ora_next = $closest_after ? date('H:i', strtotime($closest_after['appointment_time'])) : $ref_time_sel;
            echo "<span style='color:#28a745;'> - Distanza dal successivo ({$ora_next}): " . number_format($dist_next, 2) . " km</span><br>";
        }

        echo "<small class='text-muted'>Zona Slot: " . htmlspecialchars($zone_id_book);
        if (isset($slot['related_appointment']['is_neighboring_zone']) && $slot['related_appointment']['is_neighboring_zone']) {
            echo " <span class='badge bg-info'>Zona confinante</span>";
        }
        echo "</small></p>";

        // Bottoni
        $unique_sfx = preg_replace('/[^a-zA-Z0-9]/','',$slot['date'].$slot['time'].$zone_id_book).rand(100,999);
        $coll_id = "ag_coll_".$unique_sfx; $cont_id = "ag_cont_".$unique_sfx;
        echo "<button class='btn btn-sm btn-outline-info mt-1 me-2' type='button' data-bs-toggle='collapse' data-bs-target='#{$coll_id}' aria-expanded='false'><i class='bi bi-calendar3'></i> Vedi agenda</button>";
        $nameE=urlencode($name_utente); $surE=urlencode($surname_utente); $phE=urlencode($phone_utente); $addrE=urlencode($address_utente);
        $book_url = "book_appointment.php?zone_id=".urlencode($zone_id_book)."&date=".urlencode($slot['date'])."&time=".urlencode($slot_time_fmt)."&address=".$addrE."&latitude=".urlencode($latitude_utente)."&longitude=".urlencode($longitude_utente)."&name={$nameE}&surname={$surE}&phone={$phE}";
        echo "<a href='{$book_url}' class='btn btn-success mt-1 fw-bold'><i class='bi bi-check-circle'></i> Seleziona</a>";

        // Collapsible agenda
        echo "<div class='collapse mt-2' id='{$coll_id}'><div class='card card-body bg-light' id='{$cont_id}'><div class='text-center'><div class='spinner-border spinner-border-sm'></div> Caricamento...</div></div></div>";
        echo "<script>
            document.addEventListener('DOMContentLoaded', function() {
                var collapseEl = document.getElementById('{$coll_id}');
                var contentEl = document.getElementById('{$cont_id}');
                if (collapseEl && contentEl) {
                    collapseEl.addEventListener('shown.bs.collapse', function() {
                        fetch('get_appointments_modal.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'appointment_date={$slot['date']}'
                        })
                        .then(function(response) { if (!response.ok) throw new Error('Errore di rete'); return response.text(); })
                        .then(function(html) { contentEl.innerHTML = html; })
                        .catch(function(error) { contentEl.innerHTML = '<div class=\"alert alert-danger\"><p>Si è verificato un errore: ' + error.message + '</p></div>'; });
                    });
                }
            });
        </script>";

        echo "</div></div>";
        $count_displayed_sel++;
    }

    if ($count_displayed_sel == 0) {
        echo "<div class='alert alert-warning text-center mt-3'>Nessuno slot adiacente disponibile.</div>";
    }
    echo "<hr class='my-4'>";
}
        
        if ($count_displayed_sel == 0) {
            echo "<div class='alert alert-warning text-center mt-3'>Nessuno slot adiacente disponibile.</div>";
        }
        
        echo "<hr class='my-4'>";
    }
    
    
    
    // --- SLOT GIORNATE MISTE OTTIMIZZATE (proposte automatiche) ---
$mixed_slots = findOptimizedMixedDaySlots($latitude_utente, $longitude_utente,  $display_radius_km, 60);
if (!empty($mixed_slots)) {
    echo "<h3 class='mb-3 mt-4 text-center text-success'>Slot ottimizzati in giornate miste (altre zone)</h3>";
    foreach ($mixed_slots as $s) {
        $giorno_nome = giornoSettimana($s['date']);
        $time_fmt = date('H:i', strtotime($s['time']));
        echo "<div class='card mb-2 border-success'><div class='card-body'>";
        echo "<b>{$giorno_nome} {$s['date']} ore {$time_fmt}</b> (Zona ID: {$s['zone_id']})<br>";
        echo "<span class='badge bg-success'>{$s['motivation']}</span> ";
        echo "<span class='ms-2'><i class='bi bi-geo-alt'></i> Distanza: <b>" . number_format($s['distance'],1) . " km</b></span><br>";
        // bottone prenota, simile agli altri slot
        $book_url = "book_appointment.php?zone_id={$s['zone_id']}&date={$s['date']}&time={$s['time']}&address=".urlencode($address_utente)."&latitude={$latitude_utente}&longitude={$longitude_utente}&name=".urlencode($name_utente)."&surname=".urlencode($surname_utente)."&phone=".urlencode($phone_utente);
        echo "<a href='{$book_url}' class='btn btn-success mt-2 fw-bold'><i class='bi bi-check-circle'></i> Seleziona</a>";
        echo "</div></div>";
    }
}
    
    
    
    
    
// 2. Poi mostra gli slot raggruppati per zona
echo "<h3 class='text-center mb-3 mt-4'>Date disponibili per zona di appartenenza</h3>";

$zona_principale_id = isset($zona_utente['id']) ? $zona_utente['id'] : null;
$zona_principale_name = isset($zona_utente['name']) ? $zona_utente['name'] : 'Zona principale';
$latitude_utente = isset($latitude_utente) ? $latitude_utente : null;
$longitude_utente = isset($longitude_utente) ? $longitude_utente : null;

if ($zona_principale_id) {
    // Usa la funzione collaudata per trovare le prossime 3 date con slot disponibili
    $slots_config_principale = getSlotsForZone($zona_principale_id);
    $next3Days = getNext3AppointmentDates($slots_config_principale, $zona_principale_id, $latitude_utente, $longitude_utente);

    if (!empty($next3Days)) {
        // Intestazione: fondo bianco, scritta nera, nome zona in verde
        echo "<h4 class='mb-3' style='color:#222;'>Date disponibili nella tua zona principale: <span style='color:#1B8B35; font-weight:bold;'>{$zona_principale_name}</span></h4>";
        foreach ($next3Days as $date => $availableSlots) {
            $date_fmt = date('d/m/Y', strtotime($date));
            $giorni = ['Domenica', 'Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato'];
            $giorno_nome = $giorni[date('w', strtotime($date))];
            echo "<div class='card mb-3'>";
            echo "  <div class='card-header' style='background:#fff; color:#222; font-size:1.1em; font-weight:bold;'>";
            echo "    <span><i class='bi bi-calendar3-event me-2'></i>{$giorno_nome} {$date_fmt}</span>";
            echo "  </div>";
            echo "  <div class='card-body'>";
            echo "    <div class='d-flex flex-wrap'>";
            foreach ($availableSlots as $slot_time) {
                $slot_time_fmt = date('H:i', strtotime($slot_time));
                $nameE = urlencode($name_utente); 
                $surE = urlencode($surname_utente); 
                $phE = urlencode($phone_utente); 
                $addrE = urlencode($address_utente);
                $book_url = "book_appointment.php?zone_id={$zona_principale_id}&date={$date}&time={$slot_time}&address={$addrE}&latitude={$latitude_utente}&longitude={$longitude_utente}&name={$nameE}&surname={$surE}&phone={$phE}";
                echo "<a href='{$book_url}' class='btn btn-success m-1 fw-bold'>{$slot_time_fmt}</a> ";
            }
            echo "    </div>";
            echo "  </div>";
            echo "</div>";
        }
        // Memorizza ultima data per filtrare le zone confinanti
        $ultima_data_principale = array_key_last($next3Days) ? array_keys($next3Days)[2] : null;
    } else {
        $ultima_data_principale = null;
        echo "<div class='alert alert-info'>Nessuna data con almeno uno slot disponibile nella tua zona principale (ID: {$zona_principale_id}) nei prossimi 6 mesi.</div>";
    }
} else {
    $ultima_data_principale = null;
    echo "<div class='alert alert-warning'>Zona principale non valorizzata.</div>";
}

// ----------- ZONE CONFINANTI -----------
if (!empty($zone_confinanti)) {
    echo "<h3 class='mb-4 mt-5 text-center'>ZONE DINAMICHE<br>Date disponibili nelle zone confinanti</h3><h4 class='text-center'>Scegliendo una di queste date consentirai alla zona di creare itinerari composti fra due zone confinanti</h4>";
    foreach ($zone_confinanti as $zona_conf) {
        $zone_id_confinante = isset($zona_conf['id']) ? $zona_conf['id'] : null;
        $zone_name_confinante = isset($zona_conf['name']) ? $zona_conf['name'] : 'Zona confinante';
        if ($zone_id_confinante) {
            $slots_config_conf = getSlotsForZone($zone_id_confinante);
            // Trova le prossime 3 date, eventualmente limita la data massima a $ultima_data_principale
            $next3DaysConf = getNext3AppointmentDates($slots_config_conf, $zone_id_confinante, $latitude_utente, $longitude_utente);

            // Se vuoi filtrare per non andare oltre la data massima della zona principale:
            if ($ultima_data_principale) {
                $next3DaysConf = array_filter($next3DaysConf, function($date) use ($ultima_data_principale) {
                    return $date <= $ultima_data_principale;
                }, ARRAY_FILTER_USE_KEY);
            }

            if (!empty($next3DaysConf)) {
                echo "<div class='card mb-4'>";
                // INTESTAZIONE MODIFICATA: fondo grigio, testo nero, solo nome zona
                echo "<div class='card-header' style='background:#f3f3f3; color:#222; font-size:1.1em; font-weight:bold;'>";
                echo "{$zone_name_confinante}";
                echo "</div><div class='card-body'>";
                foreach ($next3DaysConf as $date => $availableSlots) {
                    $date_fmt = date('d/m/Y', strtotime($date));
                    $giorni = ['Domenica', 'Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato'];
                    $giorno_nome = $giorni[date('w', strtotime($date))];
                    echo "<h5 class='mb-3'>{$giorno_nome} {$date_fmt}</h5>";
                    foreach ($availableSlots as $slot_time) {
                        $slot_time_fmt = date('H:i', strtotime($slot_time));
                        $nameE = urlencode($name_utente); 
                        $surE = urlencode($surname_utente); 
                        $phE = urlencode($phone_utente); 
                        $addrE = urlencode($address_utente);
                        $book_url = "book_appointment.php?zone_id={$zone_id_confinante}&date={$date}&time={$slot_time}&address={$addrE}&latitude={$latitude_utente}&longitude={$longitude_utente}&name={$nameE}&surname={$surE}&phone={$phE}";
                        echo "<a href='{$book_url}' class='btn btn-outline-success m-1 fw-bold'>{$slot_time_fmt}</a> ";
                    }
                }
                echo "</div></div>";
            }
        }
    }
} else {
    echo "<div class='alert alert-info text-center mt-3'>Nessuno slot di zona disponibile.</div>";
}
    
    
    
    if (empty($slots_adiacenti) && empty($slots_by_zone_and_date)) {
        echo "<div class='alert alert-warning text-center mt-3'>Nessuno slot selezionabile valido. Prova ad aumentare il raggio.</div>";
    }
} else {
    echo "<div class='alert alert-info text-center mt-3'><strong>Nessuno slot disponibile trovato.</strong><br>Prova ad aumentare il raggio o controlla più tardi.</div>";
}
echo "</div>"; 

if ($proceedSearchAnyway_div_open) echo "</div>"; // Chiude #proceedSearchAnyway
echo "</div>"; // Chiude il container principale iniziato dopo il try
} 
catch (Exception $e) {
        if (ob_get_level() > 0) ob_end_clean(); 
        echo '<!DOCTYPE html><html lang="it">...mostra errore critico HTML...</html>'; // Sostituisci con il tuo HTML di errore
    }
    exit; 
    }
// Fine del blocco POST principale

        
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
        <div class="col-12 col-md-8 col-lg-6">
            <form id="addressForm" method="POST" action="combined_address_calculate_v2.php" class="mb-4">
                <div class="mb-3">
                    <label for="address" class="form-label fw-bold">Indirizzo:</label>
                    <input type="text" id="address" name="address" class="form-control" required value="<?php echo htmlspecialchars($address); ?>">
                </div>
                <div class="mb-3">
                    <label for="latitude" class="form-label fw-bold">Latitudine:</label>
                    <input type="text" id="latitude" name="latitude" class="form-control" readonly value="<?php echo htmlspecialchars($prefill_lat); ?>">
                </div>
                <div class="mb-3">
                    <label for="longitude" class="form-label fw-bold">Longitudine:</label>
                    <input type="text" id="longitude" name="longitude" class="form-control" readonly value="<?php echo htmlspecialchars($prefill_lng); ?>">
                </div>
                <!-- NUOVO CAMPO PER IL RAGGIO DI VISUALIZZAZIONE -->
                <div class="mb-3">
                    <label for="display_radius" class="form-label fw-bold">Raggio Ricerca/Visualizzazione (km):</label>
                    <input type="number" id="display_radius" name="display_radius" class="form-control" value="3" min="1" max="50">
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
            <form method="POST" action="combined_address_calculate_v2.php" class="pure-form pure-form-stacked">
                <input type="hidden" id="zone_id" name="zone_id">
                <input type="hidden" id="date" name="date">
                <input type="hidden" id="time" name="time">
                                <div style="display: flex; flex-wrap: wrap; gap: 15px;">
                    <div style="flex: 1; min-width: 250px;">
                        <label for="form_name">Nome:</label>
                        <input type="text" id="form_name" name="name" style="width: 100%;" required value="<?php echo htmlspecialchars($name); ?>">
                    </div>
                    <div style="flex: 1; min-width: 250px;">
                        <label for="form_surname">Cognome:</label>
                        <input type="text" id="form_surname" name="surname" style="width: 100%;" required value="<?php echo htmlspecialchars($surname); ?>">
                    </div>
                </div>
                
                <div style="display: flex; flex-wrap: wrap; gap: 15px; margin-top: 15px;">
                    <div style="flex: 1; min-width: 250px;">
                        <label for="form_phone">Telefono:</label>
                       <input type="text" id="form_phone" name="phone" style="width: 100%;" required value="<?php echo htmlspecialchars($phone); ?>">
                    </div>
                    <div style="flex: 1; min-width: 250px;">
                        <label for="form_address">Indirizzo:</label>
                        <input type="text" id="form_address" name="address" readonly style="width: 100%;" value="<?php echo htmlspecialchars($address); ?>">
                    </div>
                </div>
                <input type="hidden" id="form_latitude" name="latitude" value="<?php echo htmlspecialchars($prefill_lat); ?>">
                <input type="hidden" id="form_longitude" name="longitude" value="<?php echo htmlspecialchars($prefill_lng); ?>">
                
                <div style="margin-top: 15px;">
                    <label for="notes">Note:</label>
                    <textarea id="notes" name="notes" rows="4" style="width: 100%;"><?php echo isset($_POST['notes']) ? htmlspecialchars($_POST['notes']) : ''; ?></textarea>
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
