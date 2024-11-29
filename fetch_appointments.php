<?php
include_once 'db.php'; // Include database connection
include_once 'manage_appointments.php'; // Include the file where getAppointments() is defined

$filter = [
    'date' => isset($_GET['date']) ? $_GET['date'] : '',
    'zone' => isset($_GET['zone']) ? $_GET['zone'] : '',
];
$search = isset($_GET['search']) ? $_GET['search'] : '';
$appointments = getAppointments($filter, $search);

// Return appointments as JSON
header('Content-Type: application/json');
echo json_encode($appointments);
?>
