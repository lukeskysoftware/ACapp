<?php
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $apiKey = $_POST['api_key'];
    $hashedApiKey = md5($apiKey); // Hash the API key with MD5

    // Insert the hashed API key into the database
    $stmt = $conn->prepare("INSERT INTO cp_api_keys (api_key) VALUES (?)");
    $stmt->bind_param("s", $hashedApiKey);

    if ($stmt->execute()) {
        echo "API Key registered successfully.";
    } else {
        echo "Error: " . $stmt->error;
    }

    $stmt->close();
    $conn->close();
}
?>
