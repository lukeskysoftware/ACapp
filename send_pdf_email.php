<?php
// File per gestire l'invio di email con PDF allegato

// Funzione per il logging
function log_message($message) {
    $log_file = __DIR__ . "/email_debug.log";
    $timestamp = date("Y-m-d H:i:s");
    $log_entry = "[$timestamp] $message\n";
    file_put_contents($log_file, $log_entry, FILE_APPEND);
}

// Funzione per inviare email con allegato tramite SMTP
function send_smtp_email_with_attachment($to, $subject, $message, $attachment_path, $attachment_name) {
    // Configurazione del server SMTP
    $smtp_server = 'ac.nimagodev.com';
    $smtp_port = 465;
    $smtp_username = 'acapp@ac.nimagodev.com';
    $smtp_password = 'TUA_PASSWORD_QUI'; // Sostituisci con la password reale
    $smtp_from = 'acapp@ac.nimagodev.com';
    $smtp_from_name = 'ACapp';
    
    try {
        // Genera un boundary per il messaggio multipart
        $boundary = md5(time());
        
        // Leggi il contenuto del file PDF
        $pdf_content = file_get_contents($attachment_path);
        if ($pdf_content === false) {
            log_message("ERRORE: Impossibile leggere il file PDF");
            return false;
        }
        
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
        
        // Costruisci l'intestazione e il corpo dell'email con allegato
        $email_content = "From: $smtp_from_name <$smtp_from>\r\n";
        $email_content .= "To: $to\r\n";
        $email_content .= "Subject: $subject\r\n";
        $email_content .= "MIME-Version: 1.0\r\n";
        $email_content .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";
        $email_content .= "\r\n";
        
        // Parte testuale
        $email_content .= "--$boundary\r\n";
        $email_content .= "Content-Type: text/plain; charset=utf-8\r\n";
        $email_content .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $email_content .= "$message\r\n\r\n";
        
        // Parte allegato
        $email_content .= "--$boundary\r\n";
        $email_content .= "Content-Type: application/pdf; name=\"$attachment_name\"\r\n";
        $email_content .= "Content-Disposition: attachment; filename=\"$attachment_name\"\r\n";
        $email_content .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $email_content .= chunk_split(base64_encode($pdf_content)) . "\r\n";
        $email_content .= "--$boundary--";
        
        // Invia il contenuto dell'email
        fputs($smtp, $email_content . "\r\n.\r\n");
        $send_response = fgets($smtp);
        
        // QUIT
        fputs($smtp, "QUIT\r\n");
        fclose($smtp);
        
        if (strpos($send_response, '250') === false) {
            log_message("ERRORE: Invio email SMTP con allegato fallito: " . trim($send_response));
            return false;
        }
        
        return true;
    } catch (Exception $e) {
        log_message("ERRORE: Eccezione SMTP: " . $e->getMessage());
        return false;
    }
}

log_message("Avvio invio email con PDF allegato");

// Verifica se la richiesta è di tipo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    log_message("ERRORE: Metodo richiesta non valido");
    echo json_encode(['success' => false, 'error' => 'Metodo non consentito']);
    exit;
}

// Verifica la presenza dei dati necessari
if (!isset($_FILES['pdf']) || !isset($_POST['email']) || !isset($_POST['subject']) || !isset($_POST['message'])) {
    log_message("ERRORE: Dati mancanti nella richiesta");
    echo json_encode(['success' => false, 'error' => 'Dati mancanti']);
    exit;
}

$email = $_POST['email'];
$subject = $_POST['subject'];
$message = $_POST['message'];

log_message("Invio email a: $email");
log_message("Oggetto: $subject");
log_message("Messaggio: " . substr($message, 0, 100) . "...");

// Verifica che il file PDF sia stato caricato correttamente
if ($_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
    $upload_error = $_FILES['pdf']['error'];
    log_message("ERRORE: Upload file fallito con codice $upload_error");
    echo json_encode(['success' => false, 'error' => 'Errore nel caricamento del file']);
    exit;
}

// Informazioni sul file PDF
$pdf_path = $_FILES['pdf']['tmp_name'];
$pdf_name = $_FILES['pdf']['name'];
$pdf_size = filesize($pdf_path);

log_message("PDF caricato: $pdf_name ($pdf_size bytes)");

// Prova l'invio tramite SMTP
$smtp_result = send_smtp_email_with_attachment($email, $subject, $message, $pdf_path, $pdf_name);

if ($smtp_result) {
    log_message("Email con PDF inviata con successo via SMTP a: $email");
    echo json_encode(['success' => true]);
} else {
    // Fallback al metodo originale se SMTP fallisce
    log_message("ATTENZIONE: SMTP fallito, tentativo con metodo tradizionale");
    
    // Crea un boundary per il messaggio multipart
    $boundary = md5(time());
    
    // Leggi il contenuto del file PDF
    $pdf_content = file_get_contents($pdf_path);
    
    // Intestazioni dell'email
    $headers = "From: ACapp <acapp@ac.nimagodev.com>\r\n";
    $headers .= "Reply-To: acapp@ac.nimagodev.com\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";
    
    // Corpo dell'email
    $email_body = "--$boundary\r\n";
    $email_body .= "Content-Type: text/plain; charset=utf-8\r\n";
    $email_body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $email_body .= $message . "\r\n\r\n";
    
    // Allegato PDF
    $email_body .= "--$boundary\r\n";
    $email_body .= "Content-Type: application/pdf; name=\"$pdf_name\"\r\n";
    $email_body .= "Content-Disposition: attachment; filename=\"$pdf_name\"\r\n";
    $email_body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $email_body .= chunk_split(base64_encode($pdf_content)) . "\r\n";
    $email_body .= "--$boundary--";
    
    // Parametri aggiuntivi per mail()
    $additional_parameters = '-f acapp@ac.nimagodev.com';
    
    // Invia l'email
    $mail_sent = mail($email, $subject, $email_body, $headers, $additional_parameters);
    
    if ($mail_sent) {
        log_message("Email con PDF inviata con successo via mail() a: $email");
        echo json_encode(['success' => true]);
    } else {
        $error = error_get_last();
        log_message("ERRORE: Invio email fallito a: $email");
        log_message("Dettagli errore: " . ($error ? json_encode($error) : "Nessun dettaglio disponibile"));
        echo json_encode(['success' => false, 'error' => 'Errore nell\'invio dell\'email']);
    }
}
?>
