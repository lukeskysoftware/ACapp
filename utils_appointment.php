<?php
/**
 * File con funzioni utility per la gestione degli appuntamenti
 */

/**
 * Verifica se un dato slot temporale è disponibile (non è un unavailable slot)
 * 
 * @param string $date La data in formato Y-m-d
 * @param string $start_time Orario di inizio in formato H:i:s (opzionale)
 * @param string $end_time Orario di fine in formato H:i:s (opzionale)
 * @param int $zone_id ID della zona (opzionale)
 * @return array ['available' => bool, 'reason' => string]
 */
function isSlotAvailable($date, $start_time = null, $end_time = null, $zone_id = null) {
    global $conn;
    
    // Preparazione della risposta
    $response = ['available' => true, 'reason' => ''];
    
    // Query di base per controllare gli unavailable slots
    $sql = "SELECT * FROM cp_unavailable_slots WHERE 
            ((date_start <= ? AND date_end >= ?) OR date_start = ?)";
    
    // Parametri di base
    $types = "sss";
    $params = [$date, $date, $date];
    
    // Se è specificato un orario, aggiungiamo le condizioni per verificare la sovrapposizione oraria
    if ($start_time && $end_time) {
        $sql .= " AND (
                   (all_day = 1) OR 
                   (start_time <= ? AND end_time >= ?) OR 
                   (start_time >= ? AND start_time < ?) OR
                   (end_time > ? AND end_time <= ?)
                 )";
        $types .= "sssss";
        $params[] = $end_time;   // Se l'inizio del blocco è prima della fine appuntamento
        $params[] = $start_time; // Se la fine del blocco è dopo dell'inizio appuntamento
        $params[] = $start_time; // Se l'inizio del blocco è durante l'appuntamento
        $params[] = $end_time;   // Se l'inizio del blocco è durante l'appuntamento
        $params[] = $start_time; // Se la fine del blocco è durante l'appuntamento
        $params[] = $end_time;   // Se la fine del blocco è durante l'appuntamento
    } else {
        // Se non è specificato un orario, cerchiamo i blocchi per l'intero giorno
        $sql .= " AND all_day = 1";
    }
    
    // Se è specificata una zona, filtriamo per zona o per "tutte le zone" (zone_id IS NULL)
    if ($zone_id) {
        $sql .= " AND (zone_id = ? OR zone_id IS NULL)";
        $types .= "i";
        $params[] = $zone_id;
    }
    
    // Preparazione e esecuzione della query
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Errore nella preparazione della query: " . $conn->error);
        return ['available' => false, 'reason' => 'Errore di sistema nella verifica disponibilità'];
    }
    
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Se troviamo almeno un record, lo slot non è disponibile
    if ($result->num_rows > 0) {
        $unavailable = $result->fetch_assoc();
        $response = [
            'available' => false, 
            'reason' => $unavailable['reason'] ?: 'Data/ora non disponibile'
        ];
    }
    
    $stmt->close();
    return $response;
}

/**
 * Trova le prossime N date disponibili a partire da una data
 * 
 * @param string $start_date Data di inizio in formato Y-m-d
 * @param int $count Numero di date disponibili da trovare
 * @param string $time_slot Orario desiderato in formato H:i:s (opzionale)
 * @param int $zone_id ID della zona (opzionale)
 * @param int $max_days Numero massimo di giorni da controllare
 * @return array Lista di date disponibili
 */
function findNextAvailableDates($start_date, $count = 3, $time_slot = null, $zone_id = null, $max_days = 60) {
    $available_dates = [];
    $current_date = new DateTime($start_date);
    $end_date = (new DateTime($start_date))->add(new DateInterval("P{$max_days}D"));
    
    // Separazione del time_slot in inizio e fine (se fornito)
    $start_time = null;
    $end_time = null;
    if ($time_slot) {
        // Assumi che il time_slot sia di 1 ora o che sia già fornito come "start-end"
        if (strpos($time_slot, '-') !== false) {
            list($start_time, $end_time) = explode('-', $time_slot);
        } else {
            $start_time = $time_slot;
            $time_obj = new DateTime($time_slot);
            $time_obj->add(new DateInterval('PT1H')); // Aggiunge 1 ora
            $end_time = $time_obj->format('H:i:s');
        }
    }
    
    // Continua a cercare finché non troviamo abbastanza date o raggiungiamo il limite
    while (count($available_dates) < $count && $current_date <= $end_date) {
        $date_str = $current_date->format('Y-m-d');
        
        // Controlla solo i giorni feriali (opzionale)
        $day_of_week = (int)$current_date->format('N'); // 1 (lunedì) a 7 (domenica)
        if ($day_of_week < 6) { // Solo giorni feriali (lunedì-venerdì)
            $availability = isSlotAvailable($date_str, $start_time, $end_time, $zone_id);
            
            if ($availability['available']) {
                $available_dates[] = $date_str;
            }
        }
        
        // Passa al giorno successivo
        $current_date->add(new DateInterval('P1D'));
    }
    
    return $available_dates;
}