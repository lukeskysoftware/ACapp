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

// Funzione per inviare email con PDF tramite SMTP di Gmail
function send_email_with_attachment($to, $subject, $message, $attachment_path, $attachment_name) {
    // Crea una nuova istanza
    $mail = new PHPMailer(true);
    
    try {
        // Configura il server SMTP di Gmail
        $mail->isSMTP();
        $mail->SMTPDebug = 0;                      // Disabilita il debug per la produzione
        $mail->Host = 'smtp.gmail.com';            // Server SMTP di Gmail
        $mail->SMTPAuth = true;                    // Abilita autenticazione SMTP
        $mail->Username = 'MAIL@gmail.com'; // Il tuo indirizzo Gmail completo
        $mail->Password = 'PASSWORDAPP';  // La password per app generata generata
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // Usa STARTTLS
        $mail->Port = 587;                         // Porta TCP per STARTTLS
        $mail->CharSet = 'UTF-8';                  // Set caratteri

        // Impostazioni mittente e destinatario
        $mail->setFrom('MAIL@gmail.com', 'Agenda'); // Usa lo stesso indirizzo Gmail
       // $mail->addReplyTo('acapp@ac.nimagodev.com', 'ACapp'); // Questo può essere diverso
        $mail->addAddress($to);                    // Destinatario

        // Allegato PDF
        if (file_exists($attachment_path)) {
            $mail->addAttachment($attachment_path, $attachment_name);
        } else {
            log_message("ERRORE: File allegato non trovato: $attachment_path");
            return false;
        }

        // Contenuto
        $mail->isHTML(true);                     // Imposta il formato HTML
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
        log_message("Email con PDF inviata con successo a: $to");
        return true;
    } catch (Exception $e) {
        log_message("ERRORE: Invio email fallito. " . $mail->ErrorInfo);
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

// Invia email tramite PHPMailer
$result = send_email_with_attachment($email, $subject, $message, $pdf_path, $pdf_name);

if ($result) {
    echo json_encode(['success' => true]);
} else {
    // Fallback alla funzione mail() nativa
    log_message("PHPMailer fallito, tentativo con mail() nativa");
    
    // Crea un boundary per il messaggio multipart
    $boundary = md5(time());
    
    // Leggi il contenuto del file PDF
    $pdf_content = file_get_contents($pdf_path);
    
    // Prepara gli header e il corpo per email multipart con PDF e opzionalmente HTML
    $headers = "From: Agenda <acapp@nimagodev.com>\r\n";
   // $headers .= "Reply-To: acapp@ac.nimagodev.com\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";
    
    // Inizio corpo email
    $body = "--$boundary\r\n";
    
    // Controlla se c'è un link alle mappe di Google
    if (strpos($message, 'google.com/maps') !== false) {
        preg_match('/(https?:\/\/(?:www\.)?google\.com\/maps[^\s]+)/', $message, $matches);
        $maps_url = isset($matches[1]) ? $matches[1] : '';
        
        if ($maps_url) {
            $message_without_url = str_replace($maps_url, '', $message);
            
            // Crea una sottoparte per il contenuto multipart/alternative (testo/HTML)
            $body .= "Content-Type: multipart/alternative; boundary=\"alt-$boundary\"\r\n\r\n";
            
            // Versione testo
            $body .= "--alt-$boundary\r\n";
            $body .= "Content-Type: text/plain; charset=utf-8\r\n";
            $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= $message_without_url . "\n\nApri l'itinerario: " . $maps_url . "\r\n\r\n";
            
            // Versione HTML
            $body .= "--alt-$boundary\r\n";
            $body .= "Content-Type: text/html; charset=utf-8\r\n";
            $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= "<html><body>" . nl2br($message_without_url) . "<br><br>";
            $body .= "<a href=\"" . htmlspecialchars($maps_url) . "\" style=\"background-color: #4285F4; color: white; padding: 10px 15px; text-decoration: none; border-radius: 4px; font-weight: bold;\">Apri l'itinerario</a>";
            $body .= "</body></html>\r\n\r\n";
            $body .= "--alt-$boundary--\r\n\r\n";
        } else {
            $body .= "Content-Type: text/plain; charset=utf-8\r\n";
            $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= $message . "\r\n\r\n";
        }
    } else {
        $body .= "Content-Type: text/plain; charset=utf-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $message . "\r\n\r\n";
    }
    
    // Allegato PDF
    $body .= "--$boundary\r\n";
    $body .= "Content-Type: application/pdf; name=\"$pdf_name\"\r\n";
    $body .= "Content-Disposition: attachment; filename=\"$pdf_name\"\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($pdf_content)) . "\r\n";
    $body .= "--$boundary--";
    
    // Invia l'email
    $mail_result = mail($email, $subject, $body, $headers);
    
    if ($mail_result) {
        log_message("Email con PDF inviata con successo via mail() a: $email");
        echo json_encode(['success' => true]);
    } else {
        log_message("ERRORE: Invio email fallito a: $email");
        echo json_encode(['success' => false, 'error' => 'Errore nell\'invio dell\'email']);
    }
}
?>
