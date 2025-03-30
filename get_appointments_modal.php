<?php
// Inizia l'output buffering per catturare eventuali output indesiderati
ob_start();

// Includi il file di connessione al database
include_once 'db.php';

// Pulisci il buffer per rimuovere eventuali messaggi indesiderati
ob_clean();

// Log per debug (verifica che i parametri arrivino correttamente)
$date_param = isset($_GET['date']) ? $_GET['date'] : 'non impostato';
$zone_id_param = isset($_GET['zone_id']) ? $_GET['zone_id'] : 'non impostato';
error_log("GET_APPOINTMENTS_MODAL - Ricevuto richiesta: date={$date_param}, zone_id={$zone_id_param}");

// Previeni l'accesso diretto senza parametro data
if (!isset($_GET['date'])) {
    echo '<div class="alert alert-danger">Parametro data mancante.</div>';
    exit;
}

$date = $_GET['date'];
$zoneId = isset($_GET['zone_id']) ? (int)$_GET['zone_id'] : 0;

// Validate date format (YYYY-MM-DD)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo '<div class="alert alert-danger">Formato data non valido.</div>';
    exit;
}

// Get appointments for a specific date and zone
$appointments = array();
$sql = "SELECT a.id, p.name, p.surname, CONCAT('+39', p.phone) as phone, 
        a.notes, a.appointment_date, a.appointment_time, a.address, a.zone_id,
        CASE WHEN z.name IS NULL THEN 'N/A' ELSE z.name END as zone_name
        FROM cp_appointments a
        JOIN cp_patients p ON a.patient_id = p.id
        LEFT JOIN cp_zones z ON a.zone_id = z.id
        WHERE a.appointment_date = ?";

$params = array($date);
$types = "s";

// If zone_id is provided and not 0, filter by zone
if ($zoneId > 0) {
    $sql .= " AND a.zone_id = ?";
    $types .= "i";
}

$sql .= " ORDER BY a.appointment_time ASC";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    error_log("GET_APPOINTMENTS_MODAL - Errore preparazione query: " . $conn->error);
    echo '<div class="alert alert-danger">Errore nella preparazione della query.</div>';
    exit;
}

// Usa il metodo standard bind_param con controlli appropriati per la versione PHP
if ($zoneId > 0) {
    $stmt->bind_param($types, $date, $zoneId); // Se c'è zona, aggiungi il parametro
} else {
    $stmt->bind_param($types, $date); // Solo data
}

if (!$stmt->execute()) {
    error_log("GET_APPOINTMENTS_MODAL - Errore esecuzione query: " . $stmt->error);
    echo '<div class="alert alert-danger">Errore nell\'esecuzione della query.</div>';
    exit;
}

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $appointments[] = $row;
}

// Get zone name if zoneId is provided
$zoneName = "Tutti";
if ($zoneId > 0) {
    $zoneQuery = "SELECT name FROM cp_zones WHERE id = ?";
    $zoneStmt = $conn->prepare($zoneQuery);
    $zoneStmt->bind_param("i", $zoneId);
    $zoneStmt->execute();
    $zoneResult = $zoneStmt->get_result();
    
    if ($zoneRow = $zoneResult->fetch_assoc()) {
        $zoneName = $zoneRow['name'];
    }
}

// Format date for display
setlocale(LC_TIME, 'it_IT.UTF-8');
$formattedDate = strftime('%d %B %Y', strtotime($date));

// Clear any buffered output before sending our content
ob_end_clean();

// Output the appointments HTML
if (empty($appointments)) {
    ?>
    <div class="text-center py-5">
        <i class="bi bi-calendar-x" style="font-size: 3rem; color: #6c757d;"></i>
        <h5 class="mt-3">Nessun appuntamento registrato</h5>
        <p>Non ci sono appuntamenti programmati per questa data<?php echo $zoneId > 0 ? ' nella zona '.$zoneName : ''; ?>.</p>
    </div>
    <?php
} else {
    ?>
    <div class="mb-3">
        <h6><?php echo count($appointments); ?> appuntamento/i
            <?php if ($zoneId > 0): ?>
                <span class="badge bg-primary"><?php echo htmlspecialchars($zoneName); ?></span>
            <?php endif; ?>
        </h6>
    </div>
    
    <?php foreach ($appointments as $appointment): ?>
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="card-title"><?php echo date('H:i', strtotime($appointment['appointment_time'])); ?></h5>
                    <span class="badge bg-secondary"><?php echo htmlspecialchars($appointment['zone_name']); ?></span>
                </div>
                
                <h6 class="card-subtitle mb-2">
                    <?php echo htmlspecialchars($appointment['name']); ?> 
                    <?php echo htmlspecialchars($appointment['surname']); ?>
                </h6>
                
                <div class="mb-2">
                    <i class="bi bi-telephone me-1"></i> 
                    <a href="tel:<?php echo $appointment['phone']; ?>" class="text-decoration-none">
                        <?php echo $appointment['phone']; ?>
                    </a>
                    <a href="tel:<?php echo $appointment['phone']; ?>" class="btn btn-sm ms-2" 
                        style="background-color: #fd7e14; color: white;">
                        <i class="bi bi-telephone-fill"></i> Chiama
                    </a>
                </div>
                
                <div class="mb-2">
                    <i class="bi bi-geo-alt me-1"></i>
                    <?php echo htmlspecialchars($appointment['address']); ?>
                    <a href="https://www.google.com/maps/search/?api=1&query=<?php echo urlencode($appointment['address']); ?>" 
                       target="_blank" class="btn btn-sm btn-info text-white ms-2">
                        <i class="bi bi-geo-alt-fill"></i> Apri in Mappe
                    </a>
                </div>
                
                <?php if (!empty($appointment['notes'])): ?>
                    <div class="mt-2">
                        <strong>Note:</strong>
                        <p class="mb-0"><?php echo nl2br(htmlspecialchars($appointment['notes'])); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <?php
}
?>
