<?php
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $api_key = $_POST['api_key'];

    $config_content = "<?php\n";
    $config_content .= "define('GOOGLE_MAPS_API_KEY', '$api_key');\n";

    file_put_contents('config.php', $config_content);
    echo "API key registered successfully.";
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Register API Key</title>
</head>
<body>
    <h2>Register API Key</h2>
    <form method="post" action="register_api_key.php">
        <label for="api_key">API Key:</label><br>
        <input type="text" id="api_key" name="api_key" required><br><br>
        <input type="submit" value="Register">
    </form>
</body>
</html>
