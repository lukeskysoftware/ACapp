<?php
// Funzione per il logging
function log_message($message) {
    $log_file = __DIR__ . "/email_debug.log";
    $timestamp = date("Y-m-d H:i:s");
    $log_entry = "[$timestamp] $message\n";
    file_put_contents($log_file, $log_entry, FILE_APPEND);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    log_message("Richiesta POST ricevuta");
    
    $data = json_decode(file_get_contents('php://input'), true);
    log_message("Dati ricevuti: " . json_encode($data));
    
    if (!isset($data['email']) || !isset($data['subject']) || !isset($data['message'])) {
        log_message("ERRORE: Dati mancanti nella richiesta");
        echo json_encode(['success' => false, 'error' => 'Dati mancanti']);
        exit;
    }

    $to = $data['email'];
    $subject = $data['subject'];
    $message = $data['message'];
    
    log_message("Invio email a: $to");
    log_message("Oggetto: $subject");
    log_message("Messaggio: " . substr($message, 0, 100) . "...");
    
    // Intestazioni migliorate per una migliore consegna
    $headers = 'From: ACapp <acapp@ac.nimagodev.com>' . "\r\n";  // MODIFICA: inserisci un indirizzo valido del tuo dominio
    $headers .= 'Reply-To: acapp@ac.nimagodev.com' . "\r\n";     // MODIFICA: inserisci un indirizzo valido del tuo dominio
    $headers .= 'X-Mailer: PHP/' . phpversion() . "\r\n";
    $headers .= 'MIME-Version: 1.0' . "\r\n";
    $headers .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
    $headers .= 'Content-Transfer-Encoding: 8bit' . "\r\n";
    
    // Parametri aggiuntivi per mail()
    $additional_parameters = '-f acapp@ac.nimagodev.com';  // MODIFICA: inserisci l'indirizzo del mittente
    
    // Invia l'email
    $mail_result = mail($to, $subject, $message, $headers, $additional_parameters);
    
    if ($mail_result) {
        log_message("Email inviata con successo a: $to");
        echo json_encode(['success' => true]);
    } else {
        $error = error_get_last();
        log_message("ERRORE: Invio email fallito a: $to");
        log_message("Dettagli errore: " . ($error ? json_encode($error) : "Nessun dettaglio disponibile"));
        echo json_encode(['success' => false, 'error' => 'Invio fallito']);
    }
} else {
    log_message("ERRORE: Metodo richiesta non valido");
    echo json_encode(['success' => false, 'error' => 'Metodo richiesta non valido']);
}
?>
