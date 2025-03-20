<?php
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $data = json_decode(file_get_contents('php://input'), true);

    $email = $data['email'];
    $url = $data['url'];
    $selectedDate = $data['selectedDate']; // Get the selected date

    $subject = "Itinerario per gli appuntamenti del giorno " . $selectedDate;
    $message = "Ciao,\n\necco il link per l'itinerario degli appuntamenti del giorno " . $selectedDate . ":\n\n" . $url . "\n\nCordiali saluti,\nIl Team degli Appuntamenti";

    $headers = 'From: no-reply@yourdomain.com' . "\r\n" .
               'Reply-To: no-reply@yourdomain.com' . "\r\n" .
               'X-Mailer: PHP/' . phpversion();

    if (mail($email, $subject, $message, $headers)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
    }
}
?>