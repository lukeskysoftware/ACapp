<?php
// Funzione per il logging
function log_message($message) {
    $log_file = __DIR__ . "/email_debug.log";
    $timestamp = date("Y-m-d H:i:s");
    $log_entry = "[$timestamp] $message\n";
    file_put_contents($log_file, $log_entry, FILE_APPEND);
}

// Funzione per inviare email tramite SMTP invece di mail()
function send_smtp_email($to, $subject, $message, $headers = '') {
    // Configurazione del server SMTP
    $smtp_server = 'ac.nimagodev.com';
    $smtp_port = 465;
    $smtp_username = 'acapp@ac.nimagodev.com';
    $smtp_password = 'TUA_PASSWORD_QUI'; // Sostituisci con la password reale
    $smtp_from = 'acapp@ac.nimagodev.com';
    $smtp_from_name = 'ACapp';
    
    // Utilizza la libreria Socket per connettersi direttamente al server SMTP
    try {
        // Crea una connessione sicura al server SMTP
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ]
        ]);
        
        $smtp = stream_socket_client(
            "ssl://$smtp_server:$smtp_port", 
            $errno, 
            $errstr, 
            30, 
            STREAM_CLIENT_CONNECT, 
            $context
        );
        
        if (!$smtp) {
            log_message("ERRORE: Connessione SMTP fallita: $errstr ($errno)");
            return false;
        }
        
        // Leggi il saluto del server
        fgets($smtp);
        
        // EHLO command
        fputs($smtp, "EHLO " . $_SERVER['SERVER_NAME'] . "\r\n");
        fgets($smtp);
        
        // AUTH LOGIN
        fputs($smtp, "AUTH LOGIN\r\n");
        fgets($smtp);
        
        // Username (base64 encoded)
        fputs($smtp, base64_encode($smtp_username) . "\r\n");
        fgets($smtp);
        
        // Password (base64 encoded)
        fputs($smtp, base64_encode($smtp_password) . "\r\n");
        $auth_response = fgets($smtp);
        
        if (strpos($auth_response, '235') === false) {
            log_message("ERRORE: Autenticazione SMTP fallita: " . trim($auth_response));
            fclose($smtp);
            return false;
        }
        
        // MAIL FROM
        fputs($smtp, "MAIL FROM: <$smtp_from>\r\n");
        fgets($smtp);
        
        // RCPT TO
        fputs($smtp, "RCPT TO: <$to>\r\n");
        fgets($smtp);
        
        // DATA
        fputs($smtp, "DATA\r\n");
        fgets($smtp);
        
        // Costruisci l'intestazione e il corpo dell'email
        $email_content = "From: $smtp_from_name <$smtp_from>\r\n";
        $email_content .= "To: $to\r\n";
        $email_content .= "Subject: $subject\r\n";
        $email_content .= "MIME-Version: 1.0\r\n";
        $email_content .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $email_content .= "Content-Transfer-Encoding: 8bit\r\n";
        $email_content .= "\r\n";
        $email_content .= "$message\r\n";
        
        // Invia il contenuto dell'email
        fputs($smtp, $email_content . "\r\n.\r\n");
        $send_response = fgets($smtp);
        
        // QUIT
        fputs($smtp, "QUIT\r\n");
        fclose($smtp);
        
        if (strpos($send_response, '250') === false) {
            log_message("ERRORE: Invio email SMTP fallito: " . trim($send_response));
            return false;
        }
        
        return true;
    } catch (Exception $e) {
        log_message("ERRORE: Eccezione SMTP: " . $e->getMessage());
        return false;
    }
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
    
    // Intestazioni per la funzione mail() (saranno usate come backup)
    $headers = 'From: ACapp <acapp@ac.nimagodev.com>' . "\r\n";
    $headers .= 'Reply-To: acapp@ac.nimagodev.com' . "\r\n";
    $headers .= 'X-Mailer: PHP/' . phpversion() . "\r\n";
    $headers .= 'MIME-Version: 1.0' . "\r\n";
    $headers .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
    $headers .= 'Content-Transfer-Encoding: 8bit' . "\r\n";
    
    // Parametri aggiuntivi per mail() (backup)
    $additional_parameters = '-f acapp@ac.nimagodev.com';
    
    // Prova prima l'invio tramite SMTP
    $smtp_result = send_smtp_email($to, $subject, $message, $headers);
    
    if ($smtp_result) {
        log_message("Email inviata con successo via SMTP a: $to");
        echo json_encode(['success' => true]);
    } else {
        // Fallback sulla funzione mail() se SMTP fallisce
        log_message("ATTENZIONE: SMTP fallito, tentativo con mail()");
        $mail_result = mail($to, $subject, $message, $headers, $additional_parameters);
        
        if ($mail_result) {
            log_message("Email inviata con successo via mail() a: $to");
            echo json_encode(['success' => true]);
        } else {
            $error = error_get_last();
            log_message("ERRORE: Invio email fallito a: $to");
            log_message("Dettagli errore: " . ($error ? json_encode($error) : "Nessun dettaglio disponibile"));
            echo json_encode(['success' => false, 'error' => 'Invio fallito']);
        }
    }
} else {
    log_message("ERRORE: Metodo richiesta non valido");
    echo json_encode(['success' => false, 'error' => 'Metodo richiesta non valido']);
}
?>
