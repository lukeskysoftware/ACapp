<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (!isset($_GET['id']) || !isset($_GET['patient_id'])) {
    header("Location: search_patients.php");
    exit();
}

$appointment_id = intval($_GET['id']);
$patient_id = intval($_GET['patient_id']);
$return_to = isset($_GET['return_to']) ? $_GET['return_to'] : 'search_patients';

try {
    $sql = "UPDATE cp_appointments SET status = NULL WHERE id = ? AND patient_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $appointment_id, $patient_id);
    
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            $_SESSION['success_message'] = "Appuntamento ripristinato con successo!";
        } else {
            $_SESSION['error_message'] = "Appuntamento non trovato o già attivo.";
        }
    } else {
        $_SESSION['error_message'] = "Errore durante il ripristino dell'appuntamento.";
    }
    $stmt->close();
    
} catch (Exception $e) {
    $_SESSION['error_message'] = "Errore: " . $e->getMessage();
}

if ($return_to === 'manage_appointments') {
    header("Location: manage_appointments.php?highlight_appointment=" . $appointment_id);
} else {
    $sql = "SELECT name, surname FROM cp_patients WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($patient = $result->fetch_assoc()) {
        $query = urlencode(trim($patient['name']) . ' ' . trim($patient['surname']));
        header("Location: search_patients.php?mode=patient&query=" . $query);
    } else {
        header("Location: search_patients.php");
    }
}

$conn->close();
exit();
?>
