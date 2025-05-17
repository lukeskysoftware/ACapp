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
 * Funzione per trovare appuntamenti vicini entro il raggio specificato.
 * Marca gli appuntamenti con un motivo di esclusione se non sono validi come riferimenti.
 * Gli appuntamenti vengono restituiti tutti, ma quelli con 'excluded_reason' non vuoto
 * non dovrebbero essere usati per generare slot adiacenti dalla logica chiamante.
 *
 * @param float $user_latitude Latitudine dell'utente
 * @param float $user_longitude Longitudine dell'utente
 * @param float $radius_km Raggio in km (default 7)
 * @return array Array di appuntamenti, ognuno con 'excluded_reason' popolato se non idoneo come riferimento.
 */
function findNearbyAppointments(float $user_latitude, float $user_longitude, float $radius_km = 7) {
    global $conn; // Necessario per getCoordinatesFromAddress e altre funzioni DB
    $nearby_appointments = [];
    $current_time = date('H:i:s'); 
    $current_date = date('Y-m-d'); 
    $log_prefix_fna_revised = "findNearbyAppointments_REVISED (UserLat:{$user_latitude}, UserLng:{$user_longitude}, Radius:{$radius_km}km): ";
    error_log($log_prefix_fna_revised . "Inizio.");

    // SQL per selezionare appuntamenti futuri e rilevanti.
    // NON calcola più la distanza Haversine qui, lo faremo in PHP dopo aver ottenuto le coordinate.
    $sql = "SELECT 
                a.id, 
                a.patient_id,
                a.appointment_date, 
                a.appointment_time, 
                a.address, 
                -- Non selezioniamo a.latitude, a.longitude perché potrebbero non esistere o essere inaffidabili qui
                a.status,
                a.zone_id,
                CONCAT(p.name, ' ', p.surname) AS patient_name,
                z.name as zone_name
            FROM 
                cp_appointments a
            JOIN 
                cp_patients p ON a.patient_id = p.id
            LEFT JOIN
                cp_zones z ON a.zone_id = z.id
            WHERE 
                a.status NOT IN ('Cancelled', 'Completed', 'No Show')
                AND STR_TO_DATE(CONCAT(a.appointment_date, ' ', a.appointment_time), '%Y-%m-%d %H:%i:%s') >= STR_TO_DATE(CONCAT(?, ' ', ?), '%Y-%m-%d %H:%i:%s')
            ORDER BY 
                a.appointment_date ASC, a.appointment_time ASC";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log($log_prefix_fna_revised . "ERRORE SQL preparazione statement: " . $conn->error);
        return $nearby_appointments;
    }
    $stmt->bind_param("ss", $current_date, $current_time); // Filtra per appuntamenti da ora in poi
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        error_log($log_prefix_fna_revised . "Trovate {$result->num_rows} righe dalla query SQL iniziale (prima di ottenere coordinate e filtrare per distanza).");
        while ($row = $result->fetch_assoc()) {
            $app_id_for_log = $row['id'] ?? 'N/D';
            $log_prefix_row_revised = $log_prefix_fna_revised . "AppID {$app_id_for_log} ('{$row['address']}'): ";
            $row['excluded_reason'] = null;
            $row['latitude'] = null;  // Inizializza per l'array $row
            $row['longitude'] = null; // Inizializza per l'array $row
            $row['distance'] = null;  // Inizializza per l'array $row

            // 1. Ottieni coordinate per l'indirizzo dell'appuntamento
            if (empty($row['address'])) {
                $row['excluded_reason'] = "Indirizzo appuntamento mancante.";
                error_log($log_prefix_row_revised . $row['excluded_reason']);
                $nearby_appointments[] = $row; // Aggiungi per mostrare l'esclusione nella tabella
                continue;
            }

            $coords_app = getCoordinatesFromAddress($row['address']); // USA LA TUA FUNZIONE ESISTENTE
            if (!$coords_app) {
                // getCoordinatesFromAddress già logga l'errore di geocodifica
                $row['excluded_reason'] = "Geocodifica fallita per indirizzo appuntamento.";
                error_log($log_prefix_row_revised . $row['excluded_reason'] . " (Log da getCoordinatesFromAddress dovrebbe precedere questo).");
                // Log originale che hai visto:
                error_log("findNearbyAppointments: App. ID " . $app_id_for_log . " escluso (geocodifica fallita).");
                $nearby_appointments[] = $row;
                continue;
            }
            $row['latitude'] = $coords_app['latitude'];
            $row['longitude'] = $coords_app['longitude'];
            error_log($log_prefix_row_revised . "Coordinate ottenute per appuntamento: Lat=" . $row['latitude'] . ", Lng=" . $row['longitude']);

            // 2. Calcola distanza Haversine in PHP (come primo filtro)
            $haversine_dist_km = haversineGreatCircleDistance(
                $user_latitude, $user_longitude,
                (float)$row['latitude'], (float)$row['longitude']
            );
            error_log($log_prefix_row_revised . "Distanza Haversine calcolata in PHP: " . var_export($haversine_dist_km, true) . " km.");

            if ($haversine_dist_km === false || $haversine_dist_km > $radius_km) {
                $row['excluded_reason'] = "Rif. appuntamento: Distanza Haversine (" . number_format($haversine_dist_km, 1) . " km) supera raggio (" . $radius_km . " km).";
                $row['distance'] = is_numeric($haversine_dist_km) ? (float)$haversine_dist_km : null; // Salva comunque la distanza per la tabella
                error_log($log_prefix_row_revised . $row['excluded_reason']);
                $nearby_appointments[] = $row;
                continue;
            }

            // 3. Se entro il raggio Haversine, tenta di ottenere la distanza stradale
            $final_calculated_distance = $haversine_dist_km; // Fallback iniziale
            error_log($log_prefix_row_revised . "Entro raggio Haversine. Tento calculateRoadDistance.");

            $road_distance_from_api = calculateRoadDistance($user_latitude, $user_longitude, (float)$row['latitude'], (float)$row['longitude']);
            error_log($log_prefix_row_revised . "calculateRoadDistance API result: " . var_export($road_distance_from_api, true));

            if ($road_distance_from_api !== false && is_numeric($road_distance_from_api) && $road_distance_from_api >= 0) {
                $final_calculated_distance = (float)$road_distance_from_api;
                error_log($log_prefix_row_revised . "Usata distanza API: {$final_calculated_distance} km.");
            } else {
                error_log($log_prefix_row_revised . "API fallita o risultato non valido. Usata distanza Haversine PHP: {$final_calculated_distance} km.");
            }
            $row['distance'] = $final_calculated_distance;

            // 4. Applica la TUA logica di esclusione originale (da riga 264 a 298 del tuo file GitHub attuale)
            //    Ora abbiamo $row['distance'] popolato correttamente.
            $appointment_datetime_str = $row['appointment_date'] . ' ' . $row['appointment_time'];
            if (strtotime($appointment_datetime_str) < strtotime("$current_date $current_time")) { // Già filtrato da SQL, ma doppio check
                 $row['excluded_reason'] = "Rif. appuntamento: Orario già passato oggi.";
            }

            // Il filtro sulla distanza $radius_km è già stato applicato con Haversine.
            // Se vuoi applicarlo ANCHE alla distanza stradale (potrebbe essere leggermente diversa):
            if (!$row['excluded_reason'] && $row['distance'] !== null && $row['distance'] > $radius_km) {
                 $row['excluded_reason'] = "Rif. appuntamento: Distanza stradale (" . number_format($row['distance'], 1) . " km) supera raggio (" . $radius_km . " km).";
            }
            
            if (!$row['excluded_reason'] && isset($row['zone_id']) && $row['zone_id']) {
                if (function_exists('isSlotAvailable')) {
                    $slotAvailableCheck = isSlotAvailable($row['appointment_date'], $row['appointment_time'], null, $row['zone_id']);
                    if (!$slotAvailableCheck['available']) {
                        error_log("findNearbyAppointments: App. ID " . $app_id_for_log . " escluso (fuori slot zona). Motivo da isSlotAvailable: " . ($slotAvailableCheck['reason'] ?? 'N/D'));
                        $row['excluded_reason'] = "Rif. appuntamento: Slot non disponibile o fuori orario per la sua zona (" . ($slotAvailableCheck['reason'] ?? 'Controllo fallito') . ").";
                    }
                } else {
                     error_log($log_prefix_fna_revised . "ATTENZIONE CRITICA: Funzione isSlotAvailable non trovata per App. ID " . $app_id_for_log);
                }
            }
            // Nota: la logica di geocodifica if (empty($row['latitude']) || empty($row['longitude'])) non serve più qui
            // perché l'abbiamo fatta all'inizio per ottenere $coords_app. Se fallisce lì, l'appuntamento è già escluso.

            // Log finale per il riferimento
            if ($row['excluded_reason']) {
                 error_log($log_prefix_row_revised . "Riferimento escluso definitivamente. Motivo: " . $row['excluded_reason'] . ". Distanza registrata: " . var_export($row['distance'], true));
            } else if ($row['distance'] === null) { // Dovrebbe essere raro ora
                error_log($log_prefix_row_revised . "ATTENZIONE FINALE: Distanza per riferimento è NULL (N/D) ma il riferimento è VALIDO. Questo è inatteso.");
            } else {
                 error_log($log_prefix_row_revised . "Riferimento VALIDO aggiunto. Distanza: " . $row['distance'] . " km.");
            }
            $nearby_appointments[] = $row;
        }
    } else {
        error_log($log_prefix_fna_revised . "Nessuna riga restituita dalla query SQL iniziale (appuntamenti futuri).");
    }
    $stmt->close();
    
    // Ordina per distanza finale (opzionale, ma utile se il DB non lo fa più in modo affidabile)
    // usort($nearby_appointments, function($a, $b) {
    //     $dist_a = $a['distance'] ?? PHP_INT_MAX;
    //     $dist_b = $b['distance'] ?? PHP_INT_MAX;
    //     if ($dist_a == $dist_b) return 0;
    //     return ($dist_a < $dist_b) ? -1 : 1;
    // });

    error_log($log_prefix_fna_revised . "Funzione completata. Restituiti " . count($nearby_appointments) . " appuntamenti processati.");
    return $nearby_appointments;
}

// Funzione Haversine (necessaria se non già definita globalmente o inclusa)
// Assicurati che questa funzione sia definita nel tuo file o in un file incluso.
if (!function_exists('haversineGreatCircleDistance')) {
    function haversineGreatCircleDistance(
        $latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo, $earthRadius = 6371
    ) {
        if ($latitudeFrom === null || $longitudeFrom === null || $latitudeTo === null || $longitudeTo === null) {
            return false; // Non possiamo calcolare se mancano coordinate
        }
        // convert from degrees to radians
        $latFrom = deg2rad($latitudeFrom);
        $lonFrom = deg2rad($longitudeFrom);
        $latTo = deg2rad($latitudeTo);
        $lonTo = deg2rad($longitudeTo);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) +
            cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));
        return $angle * $earthRadius;
    }
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
        /* ... stili CSS esistenti ... */
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
        .highlight-no-adjacent-slots td { color: #007bff; /* Blu per evidenziare */ }
        .blue-text { color: #007bff; /* Blu per evidenziare il testo specifico */ }
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
                // Il riferimento stesso è escluso da findNearbyAppointments
                $display_reason = htmlspecialchars($ref_app['excluded_reason']);
            } else {
                // Il riferimento è valido, controlliamo gli slot adiacenti da $all_calculated_adjacent_slots
                $related_slots_for_this_ref = array_filter($all_calculated_adjacent_slots, function($slot) use ($ref_app) {
                    return isset($slot['related_appointment']['id']) && $slot['related_appointment']['id'] == $ref_app['id'];
                });

                $bookable_adjacent_found = false;
                
                if (empty($related_slots_for_this_ref)) {
                    // Se non sono stati valutati slot adiacenti
                    $zone_id = isset($ref_app['zone_id']) ? $ref_app['zone_id'] : 0;
                    
                    if ($zone_id == 0) {
                        $display_reason = "<span class='blue-text'>Non utilizzabile: zona non definita (ID zona = 0)</span>";
                    } else {
                        // Verifica se esistono slot per questa zona
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
                    }
                    
                    $row_class = 'highlight-no-adjacent-slots'; 
                } else {
                    // Ci sono slot adiacenti valutati
                    $motivi_esclusione = [];
                    
                    foreach ($related_slots_for_this_ref as $adj_slot) {
                        if (isset($adj_slot['excluded']) && !$adj_slot['excluded']) {
                            $bookable_adjacent_found = true;
                            break;
                        } else if (isset($adj_slot['excluded_reason']) && !empty($adj_slot['excluded_reason'])) {
                            // Formato: "Tipo (HH:MM): Motivo"
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
            }

            // --- Logica per visualizzare i limiti della zona (invariata dalla risposta precedente) ---
            $zone_id = isset($ref_app['zone_id']) ? $ref_app['zone_id'] : 0;
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
            // --- Fine logica limiti zona ---

            echo "<tr class='{$row_class}'>"; // Applica classe se necessario
            echo "<td class='col-id'>" . (isset($ref_app['id']) ? htmlspecialchars($ref_app['id']) : 'N/D') . "</td>";
            echo "<td class='col-zone-id'>" . htmlspecialchars($zone_id) . "</td>";
            echo "<td class='col-date'>" . (isset($ref_app['appointment_date']) ? htmlspecialchars($ref_app['appointment_date']) : 'N/D') . "</td>";
            echo "<td class='col-time'>" . (isset($ref_app['appointment_time']) ? htmlspecialchars($ref_app['appointment_time']) : 'N/D') . "</td>";
            echo "<td class='col-distance'>" . (isset($ref_app['distance']) ? htmlspecialchars(number_format($ref_app['distance'], 2)) . " km" : 'N/D') . "</td>";
            echo "<td class='col-limits'>" . $display_limits_text . "</td>";
            echo "<td class='col-reason'>" . $display_reason . "</td>"; // Usa $display_reason aggiornato
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
        error_log("checkAvailableSlotsNearRef_v2: Dati appuntamento di riferimento incompleti o non validi (ID: {$ref_app_id}, Zona: {$zone_id_ref}).");
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
        error_log("checkAvailableSlotsNearRef_v2: Impossibile determinare limiti slot per zona {$zone_id_ref} per RefApp ID {$ref_app_id}.");
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

    if (strtotime($proposed_before_time_str) < strtotime($zone_actual_min_slot_time)) {
        $exclude_before = true; $before_reason = "Prima del min slot zona ({$zone_actual_min_slot_time}).";
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

    if (strtotime($proposed_after_time_str) > strtotime($zone_actual_max_slot_time)) {
        $exclude_after = true; $after_reason = "Dopo il max slot zona ({$zone_actual_max_slot_time}).";
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
 *
 * @param array $appointmentData Dati dell'appuntamento di riferimento
 * @param int $buffer_minutes Durata dello slot/buffer (default 60)
 * @return array Array di slot disponibili (o esclusi con motivo)
 */
function checkAvailableSlotsNearAppointment($appointmentData, $buffer_minutes = 60) {
    global $conn;
    $available_slots = [];

    // Dati dell'appuntamento di riferimento
    $zone_id = isset($appointmentData['zone_id']) ? $appointmentData['zone_id'] : 0;
    $appointment_id_ref = isset($appointmentData['id']) ? $appointmentData['id'] : null;
    $appointment_date_str_ref = isset($appointmentData['appointment_date']) ? $appointmentData['appointment_date'] : null;
    $appointment_time_str_ref = isset($appointmentData['appointment_time']) ? $appointmentData['appointment_time'] : null;
    $appointment_address_ref = isset($appointmentData['address']) ? $appointmentData['address'] : null;

    if (!$appointment_id_ref || !$appointment_date_str_ref || !$appointment_time_str_ref || !$appointment_address_ref) {
        error_log("checkAvailableSlotsNearAppointment: Dati appuntamento di riferimento incompleti.");
        return [];
    }
    
    $duration_slot_minutes = (int)$buffer_minutes;

    // 1. Recupera i limiti degli slot configurati per la zona specifica (MIN e MAX time da cp_slots)
    $zone_actual_min_slot_time = null; // Orario del primo slot disponibile nella zona
    $zone_actual_max_slot_time = null; // Orario di INIZIO dell'ultimo slot disponibile nella zona

    if ($zone_id != 0) {
        $slots_config_sql = "SELECT MIN(time) as earliest_slot, MAX(time) as latest_slot FROM cp_slots WHERE zone_id = ?";
        $slots_config_stmt = $conn->prepare($slots_config_sql);
        if ($slots_config_stmt) {
            $slots_config_stmt->bind_param("i", $zone_id);
            $slots_config_stmt->execute();
            $slots_config_result = $slots_config_stmt->get_result();
            if ($slots_config_row = $slots_config_result->fetch_assoc()) {
                if ($slots_config_row['earliest_slot'] && $slots_config_row['latest_slot']) {
                    $zone_actual_min_slot_time = $slots_config_row['earliest_slot'];
                    $zone_actual_max_slot_time = $slots_config_row['latest_slot'];
                    error_log("checkAvailableSlotsNearAppointment: App Ref ID {$appointment_id_ref}, Zona {$zone_id} -> Limiti slot da cp_slots: Min INIZIO {$zone_actual_min_slot_time}, Max INIZIO {$zone_actual_max_slot_time}");
                }
            }
            $slots_config_stmt->close();
        } else {
            error_log("checkAvailableSlotsNearAppointment: Errore preparazione query limiti slot per zona {$zone_id} da cp_slots: " . $conn->error);
        }
    }

    if (!$zone_actual_min_slot_time || !$zone_actual_max_slot_time) {
        error_log("checkAvailableSlotsNearAppointment: Impossibile determinare limiti slot validi da cp_slots per zona {$zone_id} (o zone_id è 0) per app. ID {$appointment_id_ref}. Nessuno slot adiacente sarà proposto.");
        return [];
    }

    $appointment_datetime_ref = new DateTime($appointment_date_str_ref . ' ' . $appointment_time_str_ref);

    // Ottieni tutti gli appuntamenti del giorno per questa zona per trovare il precedente e il successivo
    // Questo serve per i controlli di distanza.
    $daily_appointments_sql = "SELECT id, appointment_time, address FROM cp_appointments WHERE zone_id = ? AND appointment_date = ? ORDER BY appointment_time";
    $daily_stmt = $conn->prepare($daily_appointments_sql);
    $prev_appointment_details_for_distance = null;
    $next_appointment_details_for_distance = null;

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
    } else {
        error_log("checkAvailableSlotsNearAppointment: Errore preparazione query appuntamenti giornalieri per controlli distanza (zona {$zone_id}, data {$appointment_date_str_ref}): " . $conn->error);
    }


    // --- CONTROLLO SLOT PRIMA ---
    $proposed_before_slot_dt = clone $appointment_datetime_ref;
    $proposed_before_slot_dt->modify('-' . $duration_slot_minutes . ' minutes');
    $proposed_before_date_str = $proposed_before_slot_dt->format('Y-m-d');
    $proposed_before_time_str = $proposed_before_slot_dt->format('H:i:s');
    
    $exclude_before = false;
    $before_slot_excluded_reason = "";
    $debug_info_before = ['evaluated_slot' => $proposed_before_date_str . ' ' . $proposed_before_time_str];

    // Check 1: Rispetto ai limiti di slot della zona
    if (strtotime($proposed_before_time_str) < strtotime($zone_actual_min_slot_time)) {
        $exclude_before = true;
        $before_slot_excluded_reason = "Slot proposto {$proposed_before_time_str} è prima del primo slot operativo della zona ({$zone_actual_min_slot_time})";
    }

    // Check 2: Distanza dall'appuntamento precedente (se esiste e slot ancora valido)
    if (!$exclude_before && $prev_appointment_details_for_distance && !empty($prev_appointment_details_for_distance['address'])) {
        $coords_ref_for_prev_dist = getCoordinatesFromAddress($appointment_address_ref, $appointment_id_ref); // Coords dell'app. di riferimento
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
            if ($distance_from_prev === false || $distance_from_prev < 0) { // Assumendo che -1 sia errore
                $exclude_before = true;
                $before_slot_excluded_reason = "Errore calcolo distanza da app. precedente ({$prev_appointment_details_for_distance['id']}).";
            } elseif ($distance_from_prev > 7) {
                $exclude_before = true;
                $before_slot_excluded_reason = "Distanza da app. precedente ({$prev_appointment_details_for_distance['id']}: " . number_format($distance_from_prev,1) . " km) > 7 km";
            }
        } else {
            $exclude_before = true;
            $before_slot_excluded_reason = "Impossibile ottenere coordinate per calcolo distanza da app. precedente ({$prev_appointment_details_for_distance['id']}).";
            $debug_info_before['coord_error_prev'] = $before_slot_excluded_reason;
        }
    }
    
    // Check 3: Slot già occupato (se ancora valido)
    if (!$exclude_before) {
        $check_booked_sql = "SELECT COUNT(*) as count FROM cp_appointments WHERE appointment_date = ? AND appointment_time = ?";
        // Non si filtra per zone_id qui, assumendo che uno slot orario sia bloccato universalmente se preso.
        $check_booked_stmt = $conn->prepare($check_booked_sql);
        if ($check_booked_stmt) {
            $check_booked_stmt->bind_param("ss", $proposed_before_date_str, $proposed_before_time_str);
            $check_booked_stmt->execute();
            $check_result = $check_booked_stmt->get_result()->fetch_assoc();
            if ($check_result['count'] > 0) {
                $exclude_before = true;
                $before_slot_excluded_reason = "Slot {$proposed_before_time_str} del {$proposed_before_date_str} risulta già occupato";
            }
            $check_booked_stmt->close();
        } else {
            error_log("checkAvailableSlotsNearAppointment: Errore preparazione query verifica occupazione slot 'prima': " . $conn->error);
            $exclude_before = true; // Per sicurezza, se non possiamo verificare
            $before_slot_excluded_reason = "Errore interno verifica disponibilità slot.";
        }
    }
    
    if ($before_slot_excluded_reason && $exclude_before) { // Logga solo se effettivamente escluso
         error_log("checkAvailableSlotsNearAppointment (Slot PRIMA) per App Ref ID {$appointment_id_ref}: ESCLUSO. Motivo: " . $before_slot_excluded_reason);
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

    // Check 1: Rispetto ai limiti di slot della zona
    // $zone_actual_max_slot_time è l'orario di INIZIO dell'ultimo slot.
    // Lo slot proposto ($proposed_after_time_str) deve iniziare non più tardi di $zone_actual_max_slot_time.
    if (strtotime($proposed_after_time_str) > strtotime($zone_actual_max_slot_time)) {
        $exclude_after = true;
        $after_slot_excluded_reason = "Slot proposto {$proposed_after_time_str} inizia dopo l'ultimo slot operativo della zona ({$zone_actual_max_slot_time})";
    }

    // Check 2: Distanza dall'appuntamento successivo (se esiste e slot ancora valido)
    if (!$exclude_after && $next_appointment_details_for_distance && !empty($next_appointment_details_for_distance['address'])) {
        $coords_ref_for_next_dist = getCoordinatesFromAddress($appointment_address_ref, $appointment_id_ref); // Coords dell'app. di riferimento
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
            if ($distance_to_next === false || $distance_to_next < 0) { // Assumendo che -1 sia errore
                $exclude_after = true;
                $after_slot_excluded_reason = "Errore calcolo distanza da app. successivo ({$next_appointment_details_for_distance['id']}).";
            } elseif ($distance_to_next > 7) {
                $exclude_after = true;
                $after_slot_excluded_reason = "Distanza da app. successivo ({$next_appointment_details_for_distance['id']}: " . number_format($distance_to_next,1) . " km) > 7 km";
            }
        } else {
            $exclude_after = true;
            $after_slot_excluded_reason = "Impossibile ottenere coordinate per calcolo distanza da app. successivo ({$next_appointment_details_for_distance['id']}).";
            $debug_info_after['coord_error_next'] = $after_slot_excluded_reason;
        }
    }
    
    // Check 3: Slot già occupato (se ancora valido)
    if (!$exclude_after) {
        $check_booked_sql_after = "SELECT COUNT(*) as count FROM cp_appointments WHERE appointment_date = ? AND appointment_time = ?";
        $check_booked_stmt_after = $conn->prepare($check_booked_sql_after);
        if ($check_booked_stmt_after) {
            $check_booked_stmt_after->bind_param("ss", $proposed_after_date_str, $proposed_after_time_str);
            $check_booked_stmt_after->execute();
            $check_result_after = $check_booked_stmt_after->get_result()->fetch_assoc();
            if ($check_result_after['count'] > 0) {
                $exclude_after = true;
                $after_slot_excluded_reason = "Slot {$proposed_after_time_str} del {$proposed_after_date_str} risulta già occupato";
            }
            $check_booked_stmt_after->close();
        } else {
            error_log("checkAvailableSlotsNearAppointment: Errore preparazione query verifica occupazione slot 'dopo': " . $conn->error);
            $exclude_after = true; // Per sicurezza
            $after_slot_excluded_reason = "Errore interno verifica disponibilità slot.";
        }
    }

    if ($after_slot_excluded_reason && $exclude_after) { // Logga solo se effettivamente escluso
        error_log("checkAvailableSlotsNearAppointment (Slot DOPO) per App Ref ID {$appointment_id_ref}: ESCLUSO. Motivo: " . $after_slot_excluded_reason);
    }
    
    $available_slots[] = [
        'date' => $proposed_after_date_str,
        'time' => $proposed_after_time_str,
        'type' => 'after',
        'related_appointment' => $appointmentData,
        'excluded' => $exclude_after,
        'excluded_reason' => $after_slot_excluded_reason,
        'debug_info' => $debug_info_after
    ];
    
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
 * Trova la prima zona che contiene le coordinate specificate.
 * @param float $latitude
 * @param float $longitude
 * @return array|null Dati della zona o null se non trovata.
 */
if (!function_exists('getZoneForCoordinates')) {
    function getZoneForCoordinates($latitude, $longitude) {
        global $conn;
        $zones_found = [];
        // La query esatta dipende da come definisci l'appartenenza a una zona (es. poligoni, raggio dal centro della zona)
        // Questo è un ESEMPIO SEMPLIFICATO che assume che tu abbia lat/lng del centro della zona e un raggio per la zona.
        // DOVRAI ADATTARLA ALLA TUA LOGICA DI ZONA ESISTENTE.
        // Se usi poligoni, la query sarà una ST_Contains o simile.
        
        // Esempio ipotetico con centro zona + raggio zona (da adattare!)
        $sql = "SELECT id, name, latitude_center, longitude_center, zone_radius_km 
                FROM cp_zones 
                WHERE active = 1"; // Aggiungi altri filtri se necessario
        
        $result = $conn->query($sql);
        if ($result && $result->num_rows > 0) {
            while ($zone_row = $result->fetch_assoc()) {
                if (isset($zone_row['latitude_center']) && isset($zone_row['longitude_center']) && isset($zone_row['zone_radius_km'])) {
                    $distance_to_zone_center = haversineGreatCircleDistance(
                        $latitude, $longitude,
                        (float)$zone_row['latitude_center'], (float)$zone_row['longitude_center']
                    );
                    if ($distance_to_zone_center !== false && $distance_to_zone_center <= (float)$zone_row['zone_radius_km']) {
                        $zones_found[] = [
                            'id' => $zone_row['id'],
                            'name' => $zone_row['name'],
                            'zone_lat' => (float)$zone_row['latitude_center'], // Lat del centro della zona
                            'zone_lng' => (float)$zone_row['longitude_center'],// Lng del centro della zona
                            'distance_from_user_address_to_center' => $distance_to_zone_center
                        ];
                    }
                }
            }
        }
        if (empty($zones_found)) {
            error_log("getZoneForCoordinates: Nessuna zona trovata per Lat: $latitude, Lng: $longitude");
        } else {
            error_log("getZoneForCoordinates: Trovate " . count($zones_found) . " zone per Lat: $latitude, Lng: $longitude");
        }
        return $zones_found; // Restituisce un array di zone
    }
}


/**
 * NUOVA FUNZIONE: get_reference_appointments_for_table
 * Sostituisce findNearbyAppointments o simili.
 * Recupera tutti gli appuntamenti potenziali, arricchiti con coordinate, distanze,
 * e motivi di auto-esclusione del riferimento stesso.
 */
function get_reference_appointments_for_table(float $user_latitude, float $user_longitude) {
    global $conn;
    $processed_appointments = [];
    $current_datetime_php = new DateTime();
    $log_prefix = "get_reference_appointments_for_table (UserLat:{$user_latitude}, Lng:{$user_longitude}): ";
    error_log($log_prefix . "Inizio.");

    $sql = "SELECT 
                a.id, a.patient_id, a.appointment_date, a.appointment_time, a.address, 
                a.status, a.zone_id,
                CONCAT(p.name, ' ', p.surname) AS patient_name,
                z.name as zone_name,
                ac.latitude AS cached_latitude, ac.longitude AS cached_longitude
            FROM cp_appointments a
            JOIN cp_patients p ON a.patient_id = p.id
            LEFT JOIN cp_zones z ON a.zone_id = z.id
            LEFT JOIN cp_address_coordinates_cache ac ON TRIM(LOWER(a.address)) = TRIM(LOWER(ac.address))
            WHERE 
                a.status NOT IN ('Cancelled', 'Completed', 'No Show')
            ORDER BY 
                a.appointment_date ASC, a.appointment_time ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log($log_prefix . "ERRORE SQL preparazione: " . $conn->error);
        return $processed_appointments;
    }
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        error_log($log_prefix . "Trovate {$result->num_rows} righe da SQL (prima di filtri PHP).");
        while ($row = $result->fetch_assoc()) {
            $app_id_log = $row['id'] ?? 'N/D';
            $log_prefix_row = $log_prefix . "AppID {$app_id_log} ('{$row['address']}'): ";
            
            $row['ref_latitude'] = null;
            $row['ref_longitude'] = null;
            $row['geocoding_status'] = 'pending';
            $row['distance_haversine_php'] = null;
            $row['distance_road_api'] = null;
            $row['final_distance_for_display'] = null;
            $row['reference_self_exclusion_reason'] = null;

            try {
                $appointment_datetime_obj = new DateTime($row['appointment_date'] . ' ' . $row['appointment_time']);
                if ($appointment_datetime_obj < $current_datetime_php) {
                    $row['reference_self_exclusion_reason'] = "Appuntamento è passato.";
                    error_log($log_prefix_row . $row['reference_self_exclusion_reason']);
                    $processed_appointments[] = $row; 
                    continue; 
                }
            } catch (Exception $e) {
                $row['reference_self_exclusion_reason'] = "Data/ora appuntamento non valida.";
                error_log($log_prefix_row . $row['reference_self_exclusion_reason'] . " Errore: " . $e->getMessage());
                $processed_appointments[] = $row;
                continue;
            }

            if (!empty($row['cached_latitude']) && !empty($row['cached_longitude']) && is_numeric($row['cached_latitude']) && is_numeric($row['cached_longitude'])) {
                $row['ref_latitude'] = (float)$row['cached_latitude'];
                $row['ref_longitude'] = (float)$row['cached_longitude'];
                $row['geocoding_status'] = 'success_from_db_cache';
            } elseif (empty($row['address'])) {
                $row['geocoding_status'] = 'failed_no_address';
                $row['reference_self_exclusion_reason'] = ($row['reference_self_exclusion_reason'] ? $row['reference_self_exclusion_reason'] . '; ' : '') . "Indirizzo appuntamento mancante.";
            } else {
                if (function_exists('getCoordinatesFromAddress')) {
                    $coords_app = getCoordinatesFromAddress($row['address']);
                    if ($coords_app && isset($coords_app['latitude']) && isset($coords_app['longitude'])) {
                        $row['ref_latitude'] = $coords_app['latitude'];
                        $row['ref_longitude'] = $coords_app['longitude'];
                        $row['geocoding_status'] = 'success_from_api';
                    } else {
                        $row['geocoding_status'] = 'failed_api_error';
                        $row['reference_self_exclusion_reason'] = ($row['reference_self_exclusion_reason'] ? $row['reference_self_exclusion_reason'] . '; ' : '') . "Geocodifica API fallita.";
                    }
                } else {
                    $row['geocoding_status'] = 'failed_function_missing';
                    $row['reference_self_exclusion_reason'] = ($row['reference_self_exclusion_reason'] ? $row['reference_self_exclusion_reason'] . '; ' : '') . "Funzione getCoordinatesFromAddress mancante.";
                }
            }
            error_log($log_prefix_row . "Stato Geocodifica: " . $row['geocoding_status'] . ". Lat: " . ($row['ref_latitude'] ?? 'N/D') . ", Lng: " . ($row['ref_longitude'] ?? 'N/D'));


            if ($row['ref_latitude'] !== null && $row['ref_longitude'] !== null) {
                $row['distance_haversine_php'] = haversineGreatCircleDistance($user_latitude, $user_longitude, $row['ref_latitude'], $row['ref_longitude']);
                error_log($log_prefix_row . "Haversine PHP: " . var_export($row['distance_haversine_php'], true) . " km.");

                if (function_exists('calculateRoadDistance')) {
                    $row['distance_road_api'] = calculateRoadDistance($user_latitude, $user_longitude, $row['ref_latitude'], $row['ref_longitude']);
                    error_log($log_prefix_row . "Road API: " . var_export($row['distance_road_api'], true) . " km.");
                } else { error_log($log_prefix_row . "ERRORE: Funzione calculateRoadDistance mancante!");}
                
                if ($row['distance_road_api'] !== false && is_numeric($row['distance_road_api']) && $row['distance_road_api'] >= 0) {
                    $row['final_distance_for_display'] = (float)$row['distance_road_api'];
                } elseif (is_numeric($row['distance_haversine_php']) && $row['distance_haversine_php'] >=0) {
                    $row['final_distance_for_display'] = (float)$row['distance_haversine_php'];
                }
                error_log($log_prefix_row . "Distanza finale per display: " . var_export($row['final_distance_for_display'], true));
            }
            
            if (!$row['reference_self_exclusion_reason'] && isset($row['zone_id']) && $row['zone_id']) {
                if (function_exists('isSlotAvailable')) {
                    $slotAvailableCheckRef = isSlotAvailable($row['appointment_date'], $row['appointment_time'], null, $row['zone_id']);
                    if (!$slotAvailableCheckRef['available']) {
                        $row['reference_self_exclusion_reason'] = ($row['reference_self_exclusion_reason'] ? $row['reference_self_exclusion_reason'] . '; ' : '') . "Slot del rif. non valido per sua zona (" . ($slotAvailableCheckRef['reason'] ?? 'N/D') . ").";
                    }
                } else { error_log($log_prefix_row . "ERRORE: Funzione isSlotAvailable mancante!"); }
            } elseif (!$row['reference_self_exclusion_reason'] && (empty($row['zone_id']) || !$row['zone_id'])) {
                // Potresti voler marcare questo come un problema se la zona è obbligatoria per un riferimento
                // $row['reference_self_exclusion_reason'] = ($row['reference_self_exclusion_reason'] ? $row['reference_self_exclusion_reason'] . '; ' : '') . "Zona del riferimento non specificata.";
            }
            unset($row['cached_latitude'], $row['cached_longitude']);
            $processed_appointments[] = $row;
        }
    } else {
        error_log($log_prefix . "Nessuna riga da SQL.");
    }
    if ($stmt) $stmt->close();

    usort($processed_appointments, function($a, $b) {
        $dist_a = $a['final_distance_for_display'] ?? PHP_INT_MAX;
        $dist_b = $b['final_distance_for_display'] ?? PHP_INT_MAX;
        if ($dist_a == $dist_b) { // Se la distanza è la stessa, ordina per data/ora
            $datetime_a = strtotime($a['appointment_date'] . ' ' . $a['appointment_time']);
            $datetime_b = strtotime($b['appointment_date'] . ' ' . $b['appointment_time']);
            return $datetime_a <=> $datetime_b;
        }
        return ($dist_a < $dist_b) ? -1 : 1;
    });

    error_log($log_prefix . "Recuperati e processati " . count($processed_appointments) . " appuntamenti.");
    return $processed_appointments;
}


/**
 * NUOVA FUNZIONE: get_zone_slots_for_user_location
 * Cerca slot nelle zone di appartenenza dell'utente, nei prossimi 30 giorni.
 * Restituisce un array di slot formattati per la selezione finale.
 */
function get_zone_slots_for_user_location(float $user_latitude, float $user_longitude, int $display_radius_km_for_zone_slots) {
    global $conn;
    $zone_slots_for_selection = [];
    $log_prefix_zone = "get_zone_slots_for_user_location (UserLat:{$user_latitude}, Lng:{$user_longitude}): ";
    error_log($log_prefix_zone . "Inizio ricerca slot di zona.");

    if (!function_exists('getZoneForCoordinates') || !function_exists('getSlotsForZone') || !function_exists('isSlotAvailable')) {
        error_log($log_prefix_zone . "ERRORE: Funzioni necessarie per slot di zona mancanti (getZoneForCoordinates, getSlotsForZone, isSlotAvailable).");
        return $zone_slots_for_selection;
    }

    $user_zones = getZoneForCoordinates($user_latitude, $user_longitude); // Deve restituire un array di zone
    if (empty($user_zones)) {
        error_log($log_prefix_zone . "Nessuna zona di appartenenza trovata per l'utente.");
        return $zone_slots_for_selection;
    }

    $all_found_zone_slots_by_date = [];
    $days_to_scan_limit = 30; // Cerca nei prossimi 30 giorni

    foreach ($user_zones as $zone) {
        if (!isset($zone['id']) || !isset($zone['name'])) {
            error_log($log_prefix_zone . "Dati zona incompleti: " . print_r($zone, true));
            continue;
        }
        $zone_id = $zone['id'];
        $zone_name = $zone['name'];
        $zone_lat = $zone['zone_lat'] ?? null; // Lat del centro/rappresentativa della zona
        $zone_lng = $zone['zone_lng'] ?? null; // Lng del centro/rappresentativa della zona
        $distance_to_zone_center = $zone['distance_from_user_address_to_center'] ?? null;

        error_log($log_prefix_zone . "Processo zona ID: {$zone_id} ('{$zone_name}'). Distanza dal centro zona: " . var_export($distance_to_zone_center, true) . " km.");

        // Filtra subito se la zona stessa (il suo centro/punto rappresentativo) è oltre il raggio di visualizzazione per gli slot di zona
        if ($distance_to_zone_center !== null && $distance_to_zone_center > $display_radius_km_for_zone_slots) {
            error_log($log_prefix_zone . "Zona ID {$zone_id} ('{$zone_name}') scartata perché il suo centro ({$distance_to_zone_center}km) è oltre il raggio specificato ({$display_radius_km_for_zone_slots}km) per gli slot di zona.");
            continue;
        }

        $slots_config_for_this_zone = getSlotsForZone($zone_id); // Deve restituire la configurazione slot per la zona
        if (empty($slots_config_for_this_zone)) {
            error_log($log_prefix_zone . "Nessuna configurazione slot trovata per zona ID {$zone_id}.");
            continue;
        }

        $current_day_check = new DateTime();
        for ($day_offset = 0; $day_offset < $days_to_scan_limit; $day_offset++) {
            if ($day_offset > 0) {
                $current_day_check->modify('+1 day');
            }
            $date_str_check = $current_day_check->format('Y-m-d');
            $day_of_week_check = strtolower($current_day_check->format('l')); // es. monday

            if (isset($slots_config_for_this_zone[$day_of_week_check])) {
                foreach ($slots_config_for_this_zone[$day_of_week_check] as $time_slot_str) {
                    // Verifica se lo slot è valido usando isSlotAvailable (che controlla anche gli appuntamenti esistenti in quella zona/ora)
                    $slot_check_result = isSlotAvailable($date_str_check, $time_slot_str, null, $zone_id);
                    if ($slot_check_result['available']) {
                        if (!isset($all_found_zone_slots_by_date[$date_str_check])) {
                            $all_found_zone_slots_by_date[$date_str_check] = [];
                        }
                        // Aggiungi lo slot se non già presente per questa data/ora (da diverse zone potrebbe ripetersi)
                        $slot_key_unique = $time_slot_str . "_zone" . $zone_id; 
                        if(!isset($all_found_zone_slots_by_date[$date_str_check][$slot_key_unique])) {
                             $all_found_zone_slots_by_date[$date_str_check][$slot_key_unique] = [
                                'time' => $time_slot_str,
                                'zone_id' => $zone_id,
                                'zone_name' => $zone_name,
                                'zone_lat' => $zone_lat,
                                'zone_lng' => $zone_lng,
                                'distance_to_zone_center' => $distance_to_zone_center
                            ];
                        }
                    }
                }
            }
        }
    } // Fine loop su user_zones

    ksort($all_found_zone_slots_by_date); // Ordina le date

    $unique_dates_with_slots_count = 0;
    foreach ($all_found_zone_slots_by_date as $date_str => $slots_in_date) {
        if ($unique_dates_with_slots_count >= 3) break; // Abbiamo trovato 3 date uniche

        foreach ($slots_in_date as $slot_info) {
            // Prepara 'related_appointment_details' fittizio per coerenza con slot adiacenti
            $fake_related_for_zone = [
                'id' => "Z{$slot_info['zone_id']}",
                'address' => 'Slot di Zona: ' . $slot_info['zone_name'],
                'zone_id' => $slot_info['zone_id'],
                'zone_name' => $slot_info['zone_name'],
                'ref_latitude' => $slot_info['zone_lat'],
                'ref_longitude' => $slot_info['zone_lng'],
                'final_distance_for_display' => $slot_info['distance_to_zone_center'],
                'patient_name' => 'Slot di Zona',
                'appointment_date' => $date_str,
                'appointment_time' => $slot_info['time'],
            ];
            $slot_details_for_selection = [
                'date' => $date_str,
                'time' => $slot_info['time'],
                'type' => 'zone_based',
                'related_appointment_id' => "Z{$slot_info['zone_id']}",
                'related_appointment_details' => $fake_related_for_zone,
                'excluded' => false,
                'excluded_reason' => '',
            ];
            $zone_slots_for_selection[] = [
                'slot_details' => $slot_details_for_selection,
                'priority_score' => 1000 + (float)($slot_info['distance_to_zone_center'] ?? 9999), // Priorità più bassa
                'travel_distance' => (float)($slot_info['distance_to_zone_center'] ?? null),
                'source' => 'zone_logic'
            ];
        }
        $unique_dates_with_slots_count++;
    }
    
    error_log($log_prefix_zone . "Trovati " . count($zone_slots_for_selection) . " slot di zona totali (dalle prime 3 date con disponibilità).");
    return $zone_slots_for_selection;
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
            error_log("getNextAppointmentDatesForZone: Data {$check_date_str} bloccata per zona {$zoneId}. Motivo: " . $date_availability['reason']);
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
                 error_log("getNextAppointmentDatesForZone: Slot {$check_date_str} {$slot_start_time} bloccato per zona {$zoneId}. Motivo: " . $slot_specific_availability['reason']);
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
// GESTIONE DEL POST PRINCIPALE - VERSIONE CORRETTA PER V2_OPTIMIZED.PHP
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['address'])) {
    // Sanitizzazione e recupero input
    $address_utente = trim($_POST['address']);
    $name_utente = isset($_POST['name']) ? trim($_POST['name']) : 'Utente';
    // Il raggio dal form è per FILTRARE QUALI RIFERIMENTI USARE COME BASE e per filtrare gli slot di zona/adiacenti
    $display_radius_km = isset($_POST['radius']) ? (int)$_POST['radius'] : 7; 
    // La durata dell'appuntamento non è presa dal form in questa versione,
    // checkAvailableSlotsNearAppointment userà il suo valore interno (es. 60 min da V2.php)

    $log_prefix_main = "RICERCA POST (V2_OPTIMIZED_FINAL): UserAddr='{$address_utente}', Radius={$display_radius_km}km | ";
    error_log($log_prefix_main . "INIZIO.");

    $latitude_utente = null;
    $longitude_utente = null;

    if (empty($address_utente)) {
        echo "<div class='alert alert-danger text-center'>Indirizzo utente mancante. Si prega di fornire un indirizzo.</div>";
        error_log($log_prefix_main . "Errore: Indirizzo utente mancante.");
        exit;
    }

    // Geocodifica indirizzo utente (ASSICURATI CHE getCoordinatesFromAddress SIA DEFINITA E FUNZIONANTE)
    if (function_exists('getCoordinatesFromAddress')) {
        $user_coords = getCoordinatesFromAddress($address_utente);
        if ($user_coords && isset($user_coords['latitude']) && isset($user_coords['longitude'])) {
            $latitude_utente = $user_coords['latitude'];
            $longitude_utente = $user_coords['longitude'];
            error_log($log_prefix_main . "Coordinate utente: Lat={$latitude_utente}, Lng={$longitude_utente}.");
        } else {
            echo "<div class='alert alert-danger text-center'>Impossibile geocodificare l'indirizzo fornito: " . htmlspecialchars($address_utente) . ". Si prega di riprovare con un indirizzo più preciso o verificare la configurazione del servizio di geocodifica.</div>";
            error_log($log_prefix_main . "Fallimento geocodifica indirizzo utente. Risposta da getCoordinatesFromAddress: " . print_r($user_coords, true));
            exit;
        }
    } else {
        echo "<div class='alert alert-danger text-center'>ERRORE INTERNO: Funzione di geocodifica non disponibile.</div>";
        error_log($log_prefix_main . "ERRORE CRITICO: Funzione getCoordinatesFromAddress non definita.");
        exit;
    }
    

    // Output header della ricerca
    echo "<div class='container mt-4' style='text-align:left;'>"; 
    echo "<div class='card mb-4'><div class='card-body'>";
    echo "<h2 class='card-title text-center mb-3'>Analisi Disponibilità per: <span style='color:green; font-weight:bold;'>" . htmlspecialchars($address_utente) . "</span></h2>";
    echo "<p class='text-center'>Ricerca effettuata da: <strong>" . htmlspecialchars($name_utente) . "</strong></p>";
    echo "<p class='text-center'>Coordinate Utente: Lat " . htmlspecialchars($latitude_utente) . ", Lng " . htmlspecialchars($longitude_utente) . ". Raggio filtro slot: <strong>" . htmlspecialchars($display_radius_km) . " km</strong></p>";
    echo "</div></div><hr>";

    // Blocco Avviso Appuntamento Esistente (ASSICURATI CHE checkExistingAppointment SIA DEFINITA)
    if (function_exists('checkExistingAppointment')) {
        $existing_appointment_info = checkExistingAppointment($name_utente, $address_utente);
        if ($existing_appointment_info) {
            echo "<div id='existingAppointmentWarningContainer' class='alert alert-warning text-center' role='alert'>";
            echo "<strong>Attenzione:</strong> Risulta già un appuntamento per <strong>" . htmlspecialchars($name_utente) . "</strong> presso <strong>" . htmlspecialchars($address_utente) . "</strong>";
            echo " il <strong>" . htmlspecialchars(date("d/m/Y", strtotime($existing_appointment_info['appointment_date']))) . "</strong> alle ore <strong>" . htmlspecialchars(date("H:i", strtotime($existing_appointment_info['appointment_time']))) . "</strong>.";
            echo " (ID Appuntamento: " . htmlspecialchars($existing_appointment_info['id']) . ").";
            // Puoi aggiungere qui link o istruzioni per modificare/cancellare
            echo "</div>";
        }
    } else { error_log($log_prefix_main . "ATTENZIONE: Funzione checkExistingAppointment non trovata."); }


    $all_references_for_table_display = []; // Per displayAppointmentDetails - arg 1
    $all_slots_for_table_display = [];      // Per displayAppointmentDetails - arg 2
    $slots_proposti_finali_selezionabili = []; // Per i bottoni di selezione

    // 1. Recupera TUTTI gli appuntamenti di riferimento con i loro dettagli
    error_log($log_prefix_main . "Chiamo get_reference_appointments_for_table.");
    $potential_references = get_reference_appointments_for_table($latitude_utente, $longitude_utente);
    error_log($log_prefix_main . "get_reference_appointments_for_table ha restituito " . count($potential_references) . " riferimenti totali.");

    // Popola $all_references_for_table_display e determina quali usare come base per slot adiacenti
    foreach ($potential_references as $ref_app) {
        $app_id_main_log = $ref_app['id'] ?? 'N/D';
        $log_prefix_ref_main = $log_prefix_main . "Processo RefApp ID {$app_id_main_log}: ";

        $ref_app_for_display = $ref_app; // Copia per adattarla a displayAppointmentDetails
        $ref_app_for_display['distance'] = $ref_app['final_distance_for_display'];
        $ref_app_for_display['latitude'] = $ref_app['ref_latitude']; // Usa le coordinate corrette del riferimento
        $ref_app_for_display['longitude'] = $ref_app['ref_longitude'];
        
        $can_be_base_for_new_slots = true;
        $base_exclusion_reason = $ref_app['reference_self_exclusion_reason']; // Inizia con l'auto-esclusione

        if (!empty($base_exclusion_reason)) {
            $can_be_base_for_new_slots = false;
            error_log($log_prefix_ref_main . "Non può essere base (auto-escluso): " . $base_exclusion_reason);
        } else {
            // Filtra per distanza dall'utente se deve essere una BASE per slot adiacenti
            if ($ref_app['final_distance_for_display'] === null || $ref_app['final_distance_for_display'] > $display_radius_km) {
                $can_be_base_for_new_slots = false;
                $dist_log = $ref_app['final_distance_for_display'] === null ? "N/D" : number_format($ref_app['final_distance_for_display'],1)."km";
                $base_exclusion_reason = "Troppo distante ({$dist_log}) per generare slot adiacenti (Raggio filtro: {$display_radius_km}km).";
                error_log($log_prefix_ref_main . $base_exclusion_reason);
            }
        }
        $ref_app_for_display['excluded_reason'] = $base_exclusion_reason; // Per la tabella
        $all_references_for_table_display[] = $ref_app_for_display; // Aggiungi SEMPRE alla tabella

        // Se il riferimento è una base valida, cerca slot adiacenti
        // ASSICURATI CHE checkAvailableSlotsNearAppointment SIA DEFINITA E FUNZIONANTE
        if ($can_be_base_for_new_slots && function_exists('checkAvailableSlotsNearAppointment')) {
            error_log($log_prefix_ref_main . "Riferimento VALIDO come base. Chiamo checkAvailableSlotsNearAppointment.");
            $ref_input_for_check = [ // Input atteso da checkAvailableSlotsNearAppointment (come da V2.php)
                'id' => $ref_app['id'],
                'address' => $ref_app['address'],
                'latitude' => $ref_app['ref_latitude'],
                'longitude' => $ref_app['ref_longitude'],
                'appointment_date' => $ref_app['appointment_date'],
                'appointment_time' => $ref_app['appointment_time'],
                'zone_id' => $ref_app['zone_id'],
            ];
            // checkAvailableSlotsNearAppointment in V2.php prende anche user_lat, user_lng
            $slots_adiacenti = checkAvailableSlotsNearAppointment($ref_input_for_check, $latitude_utente, $longitude_utente); 

            foreach ($slots_adiacenti as $slot_adj) {
                $slot_adj_for_table = $slot_adj;
                $slot_adj_for_table['related_appointment_id'] = $ref_app['id']; // Link per displayAppointmentDetails
                // 'travel_time_to_new_slot_km', 'travel_time_from_new_slot_km', 'excluded', 'excluded_reason'
                // dovrebbero essere già popolati da checkAvailableSlotsNearAppointment
                $all_slots_for_table_display[] = $slot_adj_for_table;

                // Aggiungi agli slot selezionabili se non escluso e nel raggio
                if (!$slot_adj['excluded']) {
                    $travel_dist_slot_km = null;
                    if ($slot_adj['type'] == 'before' && isset($slot_adj['travel_time_to_new_slot_km'])) {
                        $travel_dist_slot_km = (float)$slot_adj['travel_time_to_new_slot_km'];
                    } elseif ($slot_adj['type'] == 'after' && isset($slot_adj['travel_time_from_new_slot_km'])) {
                        $travel_dist_slot_km = (float)$slot_adj['travel_time_from_new_slot_km'];
                    }

                    if ($travel_dist_slot_km !== null && $travel_dist_slot_km <= $display_radius_km) {
                        $slots_proposti_finali_selezionabili[] = [
                            'slot_details' => $slot_adj_for_table, // Contiene già i dettagli del related_appointment indirettamente
                            'priority_score' => $travel_dist_slot_km,
                            'travel_distance' => $travel_dist_slot_km,
                            'source' => 'adjacent_to_existing'
                        ];
                    } else {
                         error_log($log_prefix_ref_main."Slot adiacente ({$slot_adj['type']} {$slot_adj['date']} {$slot_adj['time']}) scartato per selezione: distanza slot ({$travel_dist_slot_km}km) > raggio ({$display_radius_km}km) o distanza non calcolata.");
                    }
                }
            }
            error_log($log_prefix_ref_main . "Trovati " . count($slots_adiacenti) . " slot adiacenti.");
        } elseif (!$can_be_base_for_new_slots) {
             // Già loggato sopra
        } elseif (!function_exists('checkAvailableSlotsNearAppointment')) {
            error_log($log_prefix_ref_main . "ERRORE CRITICO: Funzione checkAvailableSlotsNearAppointment non definita.");
        }
    } // Fine loop su potential_references

    // 2. Recupera slot di zona
    error_log($log_prefix_main . "Chiamo get_zone_slots_for_user_location.");
    // Il $display_radius_km è usato anche per filtrare le zone stesse e gli slot di zona
    $zone_slots = get_zone_slots_for_user_location($latitude_utente, $longitude_utente, $display_radius_km); 
    foreach($zone_slots as $zs) {
        $all_slots_for_table_display[] = $zs['slot_details']; // Aggiungi anche questi alla tabella per completezza, se displayAppointmentDetails li gestisce
        $slots_proposti_finali_selezionabili[] = $zs;
    }
    error_log($log_prefix_main . "get_zone_slots_for_user_location ha restituito " . count($zone_slots) . " slot di zona per la selezione.");


    // 3. CHIAMATA A displayAppointmentDetails (SEMPRE)
    //    ASSICURATI CHE displayAppointmentDetails SIA DEFINITA E FUNZIONANTE
    if (function_exists('displayAppointmentDetails')) {
        error_log($log_prefix_main . "Chiamo displayAppointmentDetails con " . count($all_references_for_table_display) . " riferimenti e " . count($all_slots_for_table_display) . " slot totali.");
        displayAppointmentDetails($all_references_for_table_display, $all_slots_for_table_display);
        error_log($log_prefix_main . "DOPO la chiamata a displayAppointmentDetails.");
    } else {
        error_log($log_prefix_main . "ERRORE CRITICO: Funzione displayAppointmentDetails non definita!");
        echo "<div class='alert alert-danger mt-3 text-center'>ERRORE INTERNO: La funzione per visualizzare i dettagli della ricerca non è disponibile.</div>";
    }

    // 4. Ordinamento e visualizzazione finale degli slot SELEZIONABILI
    if (!empty($slots_proposti_finali_selezionabili)) {
        usort($slots_proposti_finali_selezionabili, function ($a, $b) {
            return $a['priority_score'] <=> $b['priority_score'];
        });

        echo "<div class='card mt-4'><div class='card-body'><h3 class='card-title text-center'>Slot Selezionabili</h3>";
        echo "<p class='text-center text-muted'>Slot ordinati per distanza. Clicca su uno slot per procedere.</p>";
        echo "<div class='list-group'>";
        $count_selezionabili_mostrati = 0;
        foreach ($slots_proposti_finali_selezionabili as $item) {
            $slot = $item['slot_details'];
            // $related_appointment_details è dentro $slot['related_appointment_details']
            $related_appointment = $slot['related_appointment_details'] ?? null; 
            if (!$related_appointment) {
                error_log($log_prefix_main."ERRORE: related_appointment_details mancante per uno slot selezionabile: ".print_r($item,true));
                continue;
            }

            $display_time = date("H:i", strtotime($slot['time']));
            $display_date = date("d/m/Y", strtotime($slot['date']));
            $booking_datetime = $slot['date'] . ' ' . $slot['time'];

            $location_name = $related_appointment['address'] ?? 'N/D';
             // Per gli slot di zona, 'address' in related_appointment è già "Slot di Zona: Nome Zona"
            
            $distance_info = isset($item['travel_distance']) ? number_format($item['travel_distance'], 1) . " km" : "N/A";
            
            // Preparazione parametri per create_appointment_from_slot.php
            // Assicurati che create_appointment_from_slot.php gestisca correttamente questi parametri
            $booking_link_params_array = [
                'datetime' => $booking_datetime,
                'address' => $address_utente, 
                'patient_name' => $name_utente,
                'user_lat' => $latitude_utente,
                'user_lng' => $longitude_utente,
                'selected_slot_type' => $slot['type'], // 'before', 'after', 'zone_based'
                'ref_app_id' => $related_appointment['id'] ?? null, // ID del rif. o ID fittizio zona "Z<id>"
                'ref_app_address' => $related_appointment['address'] ?? null, // Indirizzo del rif. o "Slot di Zona: ..."
                'ref_app_lat' => $related_appointment['ref_latitude'] ?? null,
                'ref_app_lng' => $related_appointment['ref_longitude'] ?? null,
                // Zona del NUOVO appuntamento: se è slot di zona, è la zona stessa. Se adiacente, è la zona dell'utente (se esiste) o la zona del riferimento.
                // Questa logica potrebbe necessitare di affinamento a seconda di come vuoi assegnare la zona al nuovo app.
                'new_app_zone_id' => ($item['source'] === 'zone_logic') 
                                        ? ($related_appointment['zone_id'] ?? null) 
                                        : ($ref_app_for_display['zone_id'] ?? ($user_zones[0]['id'] ?? null)), // Fallback a prima zona utente o zona rif.
                'ref_app_actual_zone_id' => $related_appointment['zone_id'] ?? null // Zona effettiva del riferimento o della base di zona
            ];
            $booking_link_params = http_build_query($booking_link_params_array);

            echo "<a href='create_appointment_from_slot.php?{$booking_link_params}' target='_blank' class='list-group-item list-group-item-action flex-column align-items-start'>";
            echo "<div class='d-flex w-100 justify-content-between'>";
            echo "<h5 class='mb-1'>Slot: {$display_date} ore {$display_time}</h5>";
            echo "<span class='badge badge-primary badge-pill p-2'>Distanza: {$distance_info}</span>";
            echo "</div>";
            echo "<p class='mb-1'>Luogo proposto: <strong>" . htmlspecialchars($location_name) . "</strong></p>";
            if ($item['source'] === 'adjacent_to_existing') {
                 echo "<small class='text-muted'>Tipo: Adiacente a app. esistente (ID: " . htmlspecialchars($related_appointment['id'] ?? 'N/A') . " di " . htmlspecialchars($related_appointment['patient_name'] ?? 'N/A') . "). ";
                 echo "Slot proposto " . htmlspecialchars($slot['type'] == 'before' ? 'PRIMA' : 'DOPO') . " questo riferimento.</small>";
            } else { // zone_logic
                echo "<small class='text-muted'>Tipo: Slot basato su zona di appartenenza.</small>";
            }
            echo "</a>";
            $count_selezionabili_mostrati++;
        }
        if ($count_selezionabili_mostrati == 0) {
            echo "<p class='text-center mt-3'>Nessuno slot selezionabile trovato che rispetti tutti i criteri di distanza e validità.</p>";
        }
        echo "</div></div></div>";
    } else {
        echo "<div class='alert alert-info text-center mt-3'>Nessuno slot selezionabile disponibile con i criteri correnti. Prova ad aumentare il raggio di ricerca o controlla più tardi.</div>";
    }
    
    echo "</div>"; // Chiude il container principale della pagina
    error_log($log_prefix_main . "FINE.");
    exit; 
} // Fine del blocco POST

        
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
        <div class="col-12 col-md-8 col-lg-6">
            <form id="addressForm" method="POST" action="magic_address_calculate_V2_optimized.php" class="mb-4">
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
                <!-- NUOVO CAMPO PER IL RAGGIO DI VISUALIZZAZIONE -->
                <div class="mb-3">
                    <label for="display_radius" class="form-label fw-bold">Raggio Ricerca/Visualizzazione (km):</label>
                    <input type="number" id="display_radius" name="display_radius" class="form-control" value="7" min="1" max="50">
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
            <form method="POST" action="magic_address_calculate_V2_optimized.php" class="pure-form pure-form-stacked">
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
