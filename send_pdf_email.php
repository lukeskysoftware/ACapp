<?php
// File per gestire l'invio di email con PDF allegato

// Verifica se la richiesta è di tipo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Metodo non consentito']);
    exit;
}

// Verifica la presenza dei dati necessari
if (!isset($_FILES['pdf']) || !isset($_POST['email']) || !isset($_POST['subject']) || !isset($_POST['message'])) {
    echo json_encode(['success' => false, 'error' => 'Dati mancanti']);
    exit;
}

$email = $_POST['email'];
$subject = $_POST['subject'];
$message = $_POST['message'];

// Verifica che il file PDF sia stato caricato correttamente
if ($_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'Errore nel caricamento del file']);
    exit;
}

// Leggi il contenuto del file PDF
$pdf_content = file_get_contents($_FILES['pdf']['tmp_name']);
$pdf_name = $_FILES['pdf']['name'];

// Crea un boundary per il messaggio multipart
$boundary = md5(time());

// Intestazioni dell'email
$headers = "From: ACapp <noreply@acapp.it>\r\n";
$headers .= "Reply-To: noreply@acapp.it\r\n";
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

// Invia l'email
$mail_sent = mail($email, $subject, $email_body, $headers);

if ($mail_sent) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Errore nell\'invio dell\'email']);
}
?>