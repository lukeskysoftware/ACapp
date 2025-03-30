<?php
include_once 'db.php';

// Check if JSON format is requested
$outputFormat = isset($_GET['format']) && $_GET['format'] === 'json' ? 'json' : 'html';

if ($outputFormat === 'json') {
    header('Content-Type: application/json');
}

if (!isset($_GET['date'])) {
    if ($outputFormat === 'json') {
        echo json_encode(['error' => 'Date parameter is required']);
    } else {
        echo "<div class='alert alert-danger'>Parametro data mancante.</div>";
    }
    exit;
}

$date = $_GET['date'];
$zoneId = isset($_GET['zone_id']) ? (int)$_GET['zone_id'] : 0;

// Validate date format (YYYY-MM-DD)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    if ($outputFormat === 'json') {
        echo json_encode(['error' => 'Invalid date format']);
    } else {
        echo "<div class='alert alert-danger'>Formato data non valido.</div>";
    }
    exit;
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
        return ['error' => 'Database error: ' . $conn->error];
    }
    
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_all(MYSQLI_ASSOC);
}

// Get appointments
$appointments = getAppointmentsByDate($conn, $date, $zoneId);

// If JSON format is requested, return JSON data and exit
if ($outputFormat === 'json') {
    echo json_encode($appointments);
    exit;
}

// For HTML format, include the rest of your HTML rendering code here...
// This part can include the full page view of appointments for the date
include_once 'menu.php';
// HTML content follows...
?>