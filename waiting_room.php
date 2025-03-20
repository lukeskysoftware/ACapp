<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit(); // Ensure the script stops executing after the redirect
}

include 'db.php';

$success = false;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = htmlspecialchars($_POST['name']);
    $surname = htmlspecialchars($_POST['surname']);
    $phone = htmlspecialchars($_POST['phone']);

    $sql = "INSERT INTO cp_patients (name, surname, phone) VALUES ('$name', '$surname', '$phone')";
    if (!mysqli_query($conn, $sql)) {
        die('Error: ' . mysqli_error($conn));
    }
    $success = true;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Registrazione Pazienti</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/purecss@3.0.0/build/pure-min.css">
    <link rel="stylesheet" href="styles.css">
    <style>
        body {
            display: flex;
            
            align-items: center;
            height: 100vh;
            margin: 0;
            flex-direction: column;
        }
        .container {
            text-align: center;
        }
        .form-container {
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .menu {
            width: 100%;
        }
    </style>
</head>
<body>
    <div class="menu">
        <?php include 'menu.php'; ?>
    </div>
    <div class="container">
        <h2>Registrazione Pazienti in Attesa</h2>
        <?php if ($success): ?>
            <p>Registrazione avvenuta con successo!</p>
            <button onclick="window.location.href='waiting_room.php'" class="pure-button pure-button-primary">Registra un altro paziente</button>
            <button onclick="window.location.href='dashboard.php'" class="pure-button pure-button-secondary">Esci</button>
        <?php else: ?>
            <form method="POST" action="waiting_room.php" class="pure-form pure-form-stacked form-container">
                <label for="name">Nome:</label>
                <input type="text" id="name" name="name" required>
                <label for="surname">Cognome:</label>
                <input type="text" id="surname" name="surname" required>
                <label for="phone">Telefono:</label>
                <input type="text" id="phone" name="phone" required>
                <button type="submit" class="pure-button pure-button-primary">Registra Paziente</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>