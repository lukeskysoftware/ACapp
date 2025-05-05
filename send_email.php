<?php
// Includi le librerie PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require 'ext_parts/phpmailer/src/Exception.php';
require 'ext_parts/phpmailer/src/PHPMailer.php';
require 'ext_parts/phpmailer/src/SMTP.php';

// Funzione per il logging in un file specifico
function log_message($message) {
    $log_file = __DIR__ . "/email_debug.log";
    $timestamp = date("Y-m-d H:i:s");
    $log_entry = "[$timestamp] $message\n";
    
    try {
        // Tenta di scrivere nel file
        if (@file_put_contents($log_file, $log_entry, FILE_APPEND) === false) {
            // Se fallisce, prova l'error_log di PHP come fallback
            error_log("Non è stato possibile scrivere nel file di log. Messaggio: $log_entry");
        }
    } catch (Exception $e) {
        // Cattura eventuali eccezioni
        error_log("Eccezione durante la scrittura nel file di log: " . $e->getMessage());
    }
}

// Funzione per inviare email tramite SMTP di Gmail
function send_email($to, $subject, $message) {
    // Crea una nuova istanza
    $mail = new PHPMailer(true);
    
    try {
        // Configura il server SMTP di Gmail
        $mail->isSMTP();
        $mail->SMTPDebug = 0;                      // Disabilita il debug per la produzione
        $mail->Host = 'smtp.gmail.com';            // Server SMTP di Gmail
        $mail->SMTPAuth = true;                    // Abilita autenticazione SMTP
        $mail->Username = 'MAIL@gmail.com'; // Il tuo indirizzo Gmail completo
        $mail->Password = 'PASSWORDAPP';  // La password per app generata
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // Usa STARTTLS
        $mail->Port = 587;                         // Porta TCP per STARTTLS
        $mail->CharSet = 'UTF-8';                  // Set caratteri

        // Impostazioni mittente e destinatario
        $mail->setFrom('MAIL@gmail.com', 'Agenda'); // Usa lo stesso indirizzo Gmail
       // $mail->addReplyTo('acapp@ac.nimagodev.com', 'Agenda'); // Questo può essere diverso
        $mail->addAddress($to);                    // Destinatario

        // Contenuto
        $mail->isHTML(true);                       // Imposta il formato HTML
        $mail->Subject = $subject;
        
        // Gestiamo il link alle mappe di Google
        if (strpos($message, 'google.com/maps') !== false) {
            preg_match('/(https?:\/\/(?:www\.)?google\.com\/maps[^\s]+)/', $message, $matches);
            $maps_url = isset($matches[1]) ? $matches[1] : '';
            
            if ($maps_url) {
                $message_without_url = str_replace($maps_url, '', $message);
                
                // Versione HTML del messaggio
                $html_message = nl2br($message_without_url);
                $html_message .= '<br><br><a href="' . htmlspecialchars($maps_url) . '" style="background-color: #4285F4; color: white; padding: 10px 15px; text-decoration: none; border-radius: 4px; font-weight: bold;">Apri l\'itinerario</a>';
                
                // Versione testo del messaggio
                $text_message = $message_without_url . "\n\nApri l'itinerario: " . $maps_url;
                
                $mail->Body = $html_message;
                $mail->AltBody = $text_message;
            } else {
                $mail->Body = nl2br($message);
                $mail->AltBody = $message;
            }
        } else {
            $mail->Body = nl2br($message);
            $mail->AltBody = $message;
        }

        $mail->send();
        log_message("Email inviata con successo a: $to");
        return true;
    } catch (Exception $e) {
        log_message("ERRORE: Invio email fallito. " . $mail->ErrorInfo);
        return false;
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    log_message("Richiesta POST ricevuta");
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['email']) || !isset($data['subject']) || !isset($data['message'])) {
        log_message("ERRORE: Dati mancanti nella richiesta");
        echo json_encode(['success' => false, 'error' => 'Dati mancanti']);
        exit;
    }

    $to = $data['email'];
    $subject = $data['subject'];
    $message = $data['message'];
    
    log_message("Tentativo invio email a: $to");
    
    // Invia email tramite PHPMailer
    $result = send_email($to, $subject, $message);
    
    if ($result) {
        echo json_encode(['success' => true]);
    } else {
        // Fallback alla funzione mail() nativa
        log_message("PHPMailer fallito, tentativo con mail() nativa");
        
        // Crea un'email HTML se contiene un link a Google Maps
        if (strpos($message, 'google.com/maps') !== false) {
            preg_match('/(https?:\/\/(?:www\.)?google\.com\/maps[^\s]+)/', $message, $matches);
            $maps_url = isset($matches[1]) ? $matches[1] : '';
            
            if ($maps_url) {
                $message_without_url = str_replace($maps_url, '', $message);
                $boundary = md5(time());
                
                $headers = "From: Agenda <agenda@nimagodev.com>\r\n";
                //$headers .= "Reply-To: acapp@ac.nimagodev.com\r\n";
                $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
                $headers .= "MIME-Version: 1.0\r\n";
                $headers .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
                
                $body = "--$boundary\r\n";
                $body .= "Content-Type: text/plain; charset=utf-8\r\n";
                $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
                $body .= $message_without_url . "\n\nApri l'itinerario: " . $maps_url . "\r\n\r\n";
                
                $body .= "--$boundary\r\n";
                $body .= "Content-Type: text/html; charset=utf-8\r\n";
                $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
                $body .= "<html><body>" . nl2br($message_without_url) . "<br><br>";
                $body .= "<a href=\"" . htmlspecialchars($maps_url) . "\" style=\"background-color: #4285F4; color: white; padding: 10px 15px; text-decoration: none; border-radius: 4px; font-weight: bold;\">Apri l'itinerario</a>";
                $body .= "</body></html>\r\n\r\n";
                $body .= "--$boundary--";
                
                $mail_result = mail($to, $subject, $body, $headers);
            } else {
                $headers = 'From: Agenda <agenda@nimagodev.com>' . "\r\n";
                //$headers .= 'Reply-To: acapp@ac.nimagodev.com' . "\r\n";
                $headers .= 'X-Mailer: PHP/' . phpversion() . "\r\n";
                $headers .= 'MIME-Version: 1.0' . "\r\n";
                $headers .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
                
                $mail_result = mail($to, $subject, $message, $headers);
            }
        } else {
            $headers = 'From: Agenda <agenda@nimagodev.com>' . "\r\n";
           // $headers .= 'Reply-To: acapp@ac.nimagodev.com' . "\r\n";
            $headers .= 'X-Mailer: PHP/' . phpversion() . "\r\n";
            $headers .= 'MIME-Version: 1.0' . "\r\n";
            $headers .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
            
            $mail_result = mail($to, $subject, $message, $headers);
        }
        
        if ($mail_result) {
            log_message("Email inviata con successo via mail() a: $to");
            echo json_encode(['success' => true]);
        } else {
            log_message("ERRORE: Invio email fallito a: $to");
            echo json_encode(['success' => false, 'error' => 'Errore nell\'invio dell\'email']);
        }
    }
} else {
    log_message("ERRORE: Metodo richiesta non valido");
    echo json_encode(['success' => false, 'error' => 'Metodo richiesta non valido']);
}
?>
