<?php
require_once 'db.php'; // Include your database connection file

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $apiKey = $_POST['api_key'];

    // Insert the API key into the database
    $stmt = $conn->prepare("INSERT INTO cp_api_keys (api_key) VALUES (?)");
    $stmt->bind_param("s", $apiKey);

    if ($stmt->execute()) {
        echo "API Key registered successfully.";
    } else {
        echo "Error: " . $stmt->error;
    }

    $stmt->close();
    $conn->close();
}
?>
