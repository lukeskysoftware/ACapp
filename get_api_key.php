<?php
require_once 'db.php';

// Fetch the API key from the database
$result = $conn->query("SELECT api_key FROM cp_api_keys LIMIT 1");
$apiKey = $result->fetch_assoc()['api_key'];

// Return the API key as a JSON response
header('Content-Type: application/json');
echo json_encode(['api_key' => $apiKey]);
?>
