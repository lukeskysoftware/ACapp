<?php
include 'db.php';

function testUserRegistration() {
    // Dati di test
    $username = 'testuser';
    $password = 'Test@1234';

    // Hash della password
    $hashed_password = password_hash($password, PASSWORD_BCRYPT);

    // Inserimento utente nel database
    $sql = "INSERT INTO users (username, password) VALUES ('$username', '$hashed_password')";
    if (mysqli_query($conn, $sql)) {
        echo "User registered successfully\n";
    } else {
        echo "Error: " . $sql . "\n" . mysqli_error($conn);
    }

    // Verifica che l'utente sia stato registrato
    $sql = "SELECT * FROM users WHERE username = '$username'";
    $result = mysqli_query($conn, $sql);
    $user = mysqli_fetch_assoc($result);

    // Verifica della password
    if ($user && password_verify($password, $user['password'])) {
        echo "Password verification successful\n";
    } else {
        echo "Password verification failed\n";
    }

    // Pulizia del database
    $sql = "DELETE FROM users WHERE username = '$username'";
    mysqli_query($conn, $sql);

    mysqli_close($conn);
}

// Esecuzione del test
testUserRegistration();
?>
