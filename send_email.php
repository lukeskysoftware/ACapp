<?php
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $data = json_decode(file_get_contents('php://input'), true);

    $email = $data['email'];
    $subject = $data['subject'];
    $message = $data['message'];

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
