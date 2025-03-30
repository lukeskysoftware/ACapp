<?php
// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// DO NOT include menu.php here
// Start output buffering immediately
ob_start();

// Connect to database directly instead of including db.php
$dbHost = 'localhost';  // Change as needed
$dbUser = 'root';       // Change as needed
$dbPass = '';           // Change as needed
$dbName = 'acapp';      // Change as needed

// Create connection
$conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set character set
$conn->set_charset("utf8");

// Get parameters
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$zoneId = isset($_GET['zone_id']) ? (int)$_GET['zone_id'] : 0;

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

// Get zone information if specified
$zoneName = "Tutte le zone";
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

// Function to get appointments for a specific date and zone
function getAppointmentsByDate($conn, $date, $zoneId = 0) {
    $sql = "SELECT a.id, p.name, p.surname, CONCAT('+39', p.phone) as phone, 
            a.notes, a.appointment_date, a.appointment_time, a.address, a.zone_id,
            CASE WHEN z.name IS NULL THEN 'N/A' ELSE z.name END as zone_name
            FROM cp_appointments a
            JOIN cp_patients p ON a.patient_id = p.id
            LEFT JOIN cp_zones z ON a.zone_id = z.id
            WHERE a.appointment_date = ?";
    
    $params = [$date];
    $types = "s";
    
    // If zone_id is provided and not 0, filter by zone
    if ($zoneId > 0) {
        $sql .= " AND a.zone_id = ?";
        $params[] = $zoneId;
        $types .= "i";
    }
    
    $sql .= " ORDER BY a.appointment_time ASC";
    
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        return [];
    }
    
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_all(MYSQLI_ASSOC);
}

// Format the date for display
setlocale(LC_TIME, 'it_IT.UTF-8');
$formattedDate = strftime('%d %B %Y', strtotime($date));

// Get the appointments
$appointments = getAppointmentsByDate($conn, $date, $zoneId);

// Clear any buffered output before sending our HTML
ob_end_clean();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appuntamenti del <?php echo $formattedDate; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: Arial, sans-serif;
            padding: 20px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            background-color: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .appointment-card {
            background-color: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .appointment-time {
            font-size: 1.3rem;
            font-weight: bold;
            color: #212529;
        }
        .appointment-name {
            font-weight: bold;
            margin-bottom: 5px;
            font-size: 1.1rem;
        }
        .info-row {
            margin-bottom: 8px;
        }
        .action-button {
            margin-top: 5px;
            margin-right: 5px;
        }
        .orange-button {
            background-color: #fd7e14;
            border-color: #fd7e14;
            color: white;
        }
        .orange-button:hover {
            background-color: #e8590c;
            border-color: #e8590c;
            color: white;
        }
        .zone-badge {
            background-color: #007bff;
            font-size: 0.8rem;
            padding: 3px 8px;
            margin-left: 10px;
        }
        .empty-state {
            text-align: center;
            padding: 40px 0;
        }
        .empty-state i {
            font-size: 3rem;
            color: #6c757d;
            margin-bottom: 15px;
        }
        .date-navigation {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>Appuntamenti del <?php echo $formattedDate; ?></h2>
            <div>
                <button onclick="window.print()" class="btn btn-sm btn-outline-secondary me-2">
                    <i class="bi bi-printer"></i> Stampa
                </button>
                <button onclick="window.close()" class="btn btn-sm btn-secondary">
                    <i class="bi bi-x-lg"></i> Chiudi
                </button>
            </div>
        </div>
        
        <div class="date-navigation">
            <a href="?date=<?php echo date('Y-m-d', strtotime($date . ' -1 day')); ?>&zone_id=<?php echo $zoneId; ?>" class="btn btn-outline-primary">
                <i class="bi bi-arrow-left"></i> Giorno precedente
            </a>
            <a href="?date=<?php echo date('Y-m-d'); ?>&zone_id=<?php echo $zoneId; ?>" class="btn btn-outline-success">
                <i class="bi bi-calendar-check"></i> Oggi
            </a>
            <a href="?date=<?php echo date('Y-m-d', strtotime($date . ' +1 day')); ?>&zone_id=<?php echo $zoneId; ?>" class="btn btn-outline-primary">
                Giorno successivo <i class="bi bi-arrow-right"></i>
            </a>
        </div>
        
        <?php if ($zoneId > 0): ?>
            <div class="alert alert-info">
                <i class="bi bi-filter"></i> Filtrato per zona: <strong><?php echo htmlspecialchars($zoneName); ?></strong>
                <a href="?date=<?php echo $date; ?>&zone_id=0" class="btn btn-sm btn-outline-info float-end">
                    <i class="bi bi-x"></i> Rimuovi filtro
                </a>
            </div>
        <?php endif; ?>
        
        <?php if (empty($appointments)): ?>
            <div class="empty-state">
                <i class="bi bi-calendar-x d-block"></i>
                <h4>Nessun appuntamento</h4>
                <p class="text-muted">Non ci sono appuntamenti programmati per questa data<?php echo $zoneId > 0 ? ' nella zona selezionata' : ''; ?>.</p>
            </div>
        <?php else: ?>
            <div class="mb-4">
                <h5><?php echo count($appointments); ?> appuntamento/i trovato/i</h5>
            </div>
            
            <?php foreach ($appointments as $appointment): ?>
                <div class="appointment-card">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="appointment-time"><?php echo date('H:i', strtotime($appointment['appointment_time'])); ?></span>
                        <?php if ($zoneId == 0): ?>
                            <span class="badge zone-badge"><?php echo htmlspecialchars($appointment['zone_name']); ?></span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="appointment-name">
                        <?php echo htmlspecialchars($appointment['name']) . ' ' . htmlspecialchars($appointment['surname']); ?>
                    </div>
                    
                    <div class="info-row">
                        <i class="bi bi-telephone me-2"></i> <?php echo $appointment['phone']; ?>
                        <a href="tel:<?php echo $appointment['phone']; ?>" class="btn btn-sm orange-button action-button">
                            <i class="bi bi-telephone-fill"></i> Chiama
                        </a>
                    </div>
                    
                    <div class="info-row">
                        <i class="bi bi-geo-alt me-2"></i> <?php echo htmlspecialchars($appointment['address']); ?>
                        <a href="https://www.google.com/maps/search/?api=1&query=<?php echo urlencode($appointment['address']); ?>" 
                           target="_blank" class="btn btn-sm btn-info action-button">
                            <i class="bi bi-geo-alt-fill"></i> Apri in Mappe
                        </a>
                    </div>
                    
                    <?php if (!empty($appointment['notes'])): ?>
                        <div class="info-row mt-2">
                            <strong>Note:</strong><br>
                            <?php echo nl2br(htmlspecialchars($appointment['notes'])); ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script>
        // Focus the document to ensure it's in the foreground
        window.focus();
        
        // Debug output to check if page is loading correctly
        console.log("View agenda page loaded successfully");
    </script>
</body>
</html>