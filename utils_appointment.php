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
// Modifica nella funzione isSlotAvailable() in utils_appointment.php
function isSlotAvailable($date, $start_time = null, $end_time = null, $zone_id = null) {
    global $conn;
    
    try {
        // Preparazione della risposta
        $response = ['available' => true, 'reason' => ''];
        
        // Query di base per controllare gli unavailable slots
        $sql = "SELECT * FROM cp_unavailable_slots WHERE 
                ((date_start <= ? AND date_end >= ?) OR date_start = ?)";
        
        // Parametri di base
        $types = "sss";
        $params = [$date, $date, $date];
        
        // Se è specificato un orario, utilizziamo una logica più stringente per verificare le sovrapposizioni
        if ($start_time && $end_time) {
            $sql .= " AND (
                       all_day = 1 OR 
                       (start_time <= ? AND end_time >= ?) OR 
                       (start_time >= ? AND start_time < ?) OR
                       (end_time > ? AND end_time <= ?)
                     )";
            $types .= "ssssss";
            $params[] = $end_time;   // Se l'inizio del blocco è prima della fine appuntamento
            $params[] = $start_time; // Se la fine del blocco è dopo dell'inizio appuntamento
            $params[] = $start_time; // Se l'inizio del blocco è durante l'appuntamento
            $params[] = $end_time;   // Se l'inizio del blocco è durante l'appuntamento
            $params[] = $start_time; // Se la fine del blocco è durante l'appuntamento
            $params[] = $end_time;   // Se la fine del blocco è durante l'appuntamento
        } else if ($start_time) {
            // Se è specificato solo l'orario di inizio (senza fine)
            $sql .= " AND (
                       all_day = 1 OR
                       (start_time <= ? AND end_time > ?) OR
                       (start_time >= ? AND start_time < ADDTIME(?, '01:00:00'))
                     )";
            $types .= "ssss";
            $params[] = $start_time; // Inizio blocco prima dell'inizio appuntamento
            $params[] = $start_time; // Fine blocco dopo dell'inizio appuntamento
            $params[] = $start_time; // Inizio blocco durante l'appuntamento (prima ora)
            $params[] = $start_time; // Per calcolare un'ora dopo l'inizio
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
        
        // Debug info
        error_log("isSlotAvailable - Date: $date, Start: " . ($start_time ?? 'NULL') . ", End: " . ($end_time ?? 'NULL') . ", Zone: " . ($zone_id ?? 'NULL'));
        error_log("SQL Query: $sql");
        error_log("Param types: $types");
        error_log("Params count: " . count($params));
        
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
            error_log("Data non disponibile: " . $date . ($start_time ? " " . $start_time : "") . " - Motivo: " . $response['reason']);
        }
        
        $stmt->close();
        return $response;
    } catch (Exception $e) {
        error_log("Errore in isSlotAvailable: " . $e->getMessage());
        return ['available' => false, 'reason' => 'Errore di sistema nella verifica disponibilità: ' . $e->getMessage()];
    }
}

/**
 * Funzione combinata che verifica sia unavailable_slots che appuntamenti esistenti
 * Combina la logica di isSlotAvailable (unavailable_slots) con il controllo degli appuntamenti esistenti
 */
function isSlotCompletelyAvailable($date, $time, $duration_minutes = 60, $zone_id = null, $exclude_appointment_id = null) {
    global $conn;
    
    // STEP 1: Verifica unavailable_slots usando la funzione esistente
    $end_time = date('H:i:s', strtotime($time . " +{$duration_minutes} minutes"));
    $slot_availability = isSlotAvailable($date, $time, $end_time, $zone_id);
    
    if (!$slot_availability['available']) {
        error_log("SLOT BLOCKED by unavailable_slots: {$date} {$time} - {$slot_availability['reason']}");
        return ['available' => false, 'reason' => $slot_availability['reason']];
    }
    
    // STEP 2: Verifica appuntamenti esistenti
    $slot_start = new DateTime($date . ' ' . $time);
    $slot_end = clone $slot_start;
    $slot_end->modify("+{$duration_minutes} minutes");
    
    // Ottieni TUTTI gli appuntamenti del giorno
    $sql = "SELECT id, appointment_time, address FROM cp_appointments WHERE appointment_date = ?";
    if ($exclude_appointment_id) {
        $sql .= " AND id != ?";
    }
    $sql .= " ORDER BY appointment_time";
    
    $stmt = $conn->prepare($sql);
    if ($exclude_appointment_id) {
        $stmt->bind_param("si", $date, $exclude_appointment_id);
    } else {
        $stmt->bind_param("s", $date);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $existing_start = new DateTime($date . ' ' . $row['appointment_time']);
        $existing_end = clone $existing_start;
        $existing_end->modify("+{$duration_minutes} minutes");
        
        // Verifica sovrapposizione
        if (($slot_start < $existing_end) && ($slot_end > $existing_start)) {
            $stmt->close();
            error_log("OVERLAP DETECTED: Slot {$time} conflicts with existing appointment {$row['appointment_time']} (ID: {$row['id']})");
            return ['available' => false, 'reason' => "Sovrapposizione con appuntamento esistente alle {$row['appointment_time']} (ID: {$row['id']})"];
        }
    }
    
    $stmt->close();
    error_log("SLOT FREE: {$date} {$time} completely available");
    return ['available' => true, 'reason' => ''];
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
