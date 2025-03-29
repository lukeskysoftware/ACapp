<?php
// Inizializza errori e inclusioni
error_reporting(E_ALL);
ini_set('display_errors', 1);
include 'db.php';

// Output in formato HTML con stile
echo "<!DOCTYPE html>
<html lang='it'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Aggiornamento Zone ID</title>
    <link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/purecss@3.0.0/build/pure-min.css'>
    <style>
        body { padding: 20px; font-family: Arial, sans-serif; }
        .container { max-width: 900px; margin: 0 auto; }
        .success { color: green; }
        .warning { color: orange; }
        .error { color: red; }
        table { width: 100%; margin: 20px 0; border-collapse: collapse; }
        th, td { padding: 8px; text-align: left; border: 1px solid #ddd; }
        th { background-color: #f2f2f2; }
        .progress { margin: 20px 0; }
        .actions { margin-top: 20px; }
        .button-success { background: rgb(28, 184, 65); color: white; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>Aggiornamento Zone ID per appuntamenti</h1>";

// Funzione per calcolare la distanza tra due coordinate (già presente nel tuo codice)
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

// Funzione per ottenere coordinate da un indirizzo (già presente nel tuo codice)
function getCoordinatesFromAddress($address, $appointment_id = null) {
    global $conn;
    
    // Controlla se abbiamo già le coordinate per questo indirizzo
    $sql = "SELECT latitude, longitude FROM address_cache WHERE address = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("s", $address);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
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
        echo "<p class='error'>Errore nel recupero della chiave API di Google Maps</p>";
        return null;
    }
    
    if (empty($apiKey)) {
        echo "<p class='error'>API key non trovata per la geocodifica</p>";
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
        echo "<p class='error'>Errore cURL durante la chiamata all'API di geocodifica</p>";
        curl_close($ch);
        return null;
    }
    
    curl_close($ch);
    
    $data = json_decode($response, true);
    
    if ($data['status'] == 'OK') {
        $lat = $data['results'][0]['geometry']['location']['lat'];
        $lng = $data['results'][0]['geometry']['location']['lng'];
        
        // Salva nella cache
        $sql = "INSERT INTO address_cache (address, latitude, longitude) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("sdd", $address, $lat, $lng);
            $stmt->execute();
        }
        
        return ['lat' => $lat, 'lng' => $lng];
    } else {
        echo "<p class='error'>Errore geocodifica per " . htmlspecialchars($address) . ": " . $data['status'] . "</p>";
        return null;
    }
}

// Funzione per ottenere tutte le zone
function getAllZones() {
    global $conn;
    $sql = "SELECT id, name, latitude, longitude, radius_km FROM cp_zones";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        echo "<p class='error'>Database prepare failed: " . mysqli_error($conn) . "</p>";
        return [];
    }
    
    if (!$stmt->execute()) {
        echo "<p class='error'>Database query failed: " . mysqli_error($conn) . "</p>";
        return [];
    }
    
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Funzione per determinare in quale zona si trova un indirizzo
function determineZoneForAddress($latitude, $longitude) {
    global $conn;
    $zones = getAllZones();
    $origin = [$latitude, $longitude];
    
    foreach ($zones as $zone) {
        $destination = [$zone['latitude'], $zone['longitude']];
        $distance = calculateDistance($origin, $destination);
        
        if ($distance <= $zone['radius_km']) {
            return $zone['id'];
        }
    }
    
    return 0; // Nessuna zona trovata
}

// Modalità di esecuzione: 'analyze' o 'update'
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'analyze';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

// Conta totale degli appuntamenti con zone_id = 0
$countSql = "SELECT COUNT(*) as total FROM cp_appointments WHERE zone_id = 0";
$countResult = $conn->query($countSql);
$countRow = $countResult->fetch_assoc();
$totalAppointments = $countRow['total'];

echo "<p>Totale appuntamenti con zone_id = 0: <strong>{$totalAppointments}</strong></p>";
echo "<div class='progress'>Elaborazione: {$offset} - " . ($offset + $limit) . " di {$totalAppointments}</div>";

// Ottieni gli appuntamenti con zone_id = 0
$sql = "SELECT id, address, appointment_date, appointment_time FROM cp_appointments 
        WHERE zone_id = 0 
        ORDER BY id ASC 
        LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $limit, $offset);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo "<p>Nessun appuntamento con zone_id = 0 trovato.</p>";
} else {
    echo "<table>
            <tr>
                <th>ID</th>
                <th>Indirizzo</th>
                <th>Data</th>
                <th>Ora</th>
                <th>Coordinate</th>
                <th>Zone ID</th>
                <th>Stato</th>
            </tr>";
    
    $updated = 0;
    $failed = 0;
    
    while ($row = $result->fetch_assoc()) {
        $id = $row['id'];
        $address = $row['address'];
        $date = $row['appointment_date'];
        $time = $row['appointment_time'];
        
        echo "<tr>";
        echo "<td>{$id}</td>";
        echo "<td>" . htmlspecialchars($address) . "</td>";
        echo "<td>{$date}</td>";
        echo "<td>{$time}</td>";
        
        // Geocodifica l'indirizzo
        $coordinates = getCoordinatesFromAddress($address, $id);
        
        if ($coordinates) {
            $zoneId = determineZoneForAddress($coordinates['lat'], $coordinates['lng']);
            
            echo "<td>Lat: {$coordinates['lat']}, Lng: {$coordinates['lng']}</td>";
            echo "<td>{$zoneId}</td>";
            
            if ($mode === 'update') {
                // Aggiorna il record dell'appuntamento
                $updateSql = "UPDATE cp_appointments SET zone_id = ? WHERE id = ?";
                $updateStmt = $conn->prepare($updateSql);
                $updateStmt->bind_param("ii", $zoneId, $id);
                
                if ($updateStmt->execute()) {
                    echo "<td class='success'>Aggiornato</td>";
                    $updated++;
                } else {
                    echo "<td class='error'>Errore nell'aggiornamento</td>";
                    $failed++;
                }
            } else {
                echo "<td class='warning'>Solo analisi (nessun aggiornamento)</td>";
            }
        } else {
            echo "<td class='error'>Geocodifica fallita</td>";
            echo "<td>-</td>";
            echo "<td class='error'>Errore</td>";
            $failed++;
        }
        
        echo "</tr>";
    }
    
    echo "</table>";
    
    if ($mode === 'update') {
        echo "<p>Aggiornamento completato: <span class='success'>{$updated} successo</span>, <span class='error'>{$failed} falliti</span></p>";
    }
}

// Paginazione e azioni
$nextOffset = $offset + $limit;
$prevOffset = max(0, $offset - $limit);

echo "<div class='actions'>";
echo "<a href='update_zone_ids.php?mode=analyze&limit={$limit}&offset={$prevOffset}' class='pure-button'>Precedente</a> ";

if ($nextOffset < $totalAppointments) {
    echo "<a href='update_zone_ids.php?mode=analyze&limit={$limit}&offset={$nextOffset}' class='pure-button'>Successivo</a> ";
}

echo "<a href='update_zone_ids.php?mode=update&limit={$limit}&offset={$offset}' class='pure-button button-success'>Esegui aggiornamento</a>";
echo "</div>";

echo "</div></body></html>";
?>