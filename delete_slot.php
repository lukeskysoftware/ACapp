<?php
include 'db.php';

// Verifica che la richiesta sia di tipo POST
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Ottieni i parametri
    $zone_id = $_POST['zone_id'];
    $day = $_POST['day'];
    $time = $_POST['time'];
    
    // Prepara la query con prepared statement per sicurezza
    $stmt = $conn->prepare("DELETE FROM cp_slots WHERE zone_id = ? AND day = ? AND time = ?");
    $stmt->bind_param("iss", $zone_id, $day, $time);
    
    // Esegui la query
    if ($stmt->execute()) {
        // Successo
        echo json_encode(['success' => true]);
    } else {
        // Errore
        echo json_encode(['success' => false, 'error' => $stmt->error]);
    }
    
    $stmt->close();
} else {
    // Metodo non consentito
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}
$conn->close();
?>
