<?php
// File per gestire l'invio di email con PDF allegato

// Funzione per il logging
function log_message($message) {
    $log_file = __DIR__ . "/email_debug.log";
    $timestamp = date("Y-m-d H:i:s");
    $log_entry = "[$timestamp] $message\n";
    file_put_contents($log_file, $log_entry, FILE_APPEND);
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

// Leggi il contenuto del file PDF
$pdf_content = file_get_contents($_FILES['pdf']['tmp_name']);
$pdf_name = $_FILES['pdf']['name'];
$pdf_size = strlen($pdf_content);

log_message("PDF caricato: $pdf_name ($pdf_size bytes)");

// Crea un boundary per il messaggio multipart
$boundary = md5(time());

// Intestazioni dell'email
$headers = "From: ACapp <acapp@ac.nimagodev.com>\r\n";  // MODIFICA QUI: inserisci la tua email configurata
$headers .= "Reply-To: acapp@ac.nimagodev.com\r\n";     // MODIFICA QUI: inserisci la tua email configurata
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
$additional_parameters = '-f acapp@ac.nimagodev.com';  // MODIFICA QUI: inserisci la tua email configurata

// Invia l'email
$mail_sent = mail($email, $subject, $email_body, $headers, $additional_parameters);

if ($mail_sent) {
    log_message("Email con PDF inviata con successo a: $email");
    echo json_encode(['success' => true]);
} else {
    $error = error_get_last();
    log_message("ERRORE: Invio email fallito a: $email");
    log_message("Dettagli errore: " . ($error ? json_encode($error) : "Nessun dettaglio disponibile"));
    echo json_encode(['success' => false, 'error' => 'Errore nell\'invio dell\'email']);
}
?>
