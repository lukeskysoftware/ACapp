<?php
include 'db.php';

function testUserRegistration() {
    global $conn;

    $username = 'organizza';
    $password = '@AppuApp';

    // Hash della password
    $hashed_password = password_hash($password, PASSWORD_BCRYPT);

    // Inserimento utente nel database
    $sql = "INSERT INTO cp_users (username, password) VALUES (?, ?)";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ss", $username, $hashed_password);
    if (mysqli_stmt_execute($stmt)) {
        echo "User registered successfully\n";
    } else {
        echo "Error: " . mysqli_stmt_error($stmt) . "\n";
    }

    // Verifica che l'utente sia stato registrato
    $sql = "SELECT * FROM cp_users WHERE username = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $username);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result);

    // Verifica della password
    if ($user && password_verify($password, $user['password'])) {
        echo "Password verification successful\n";
    } else {
        echo "Password verification failed\n";
    }

    // Pulizia del database
    $sql = "DELETE FROM cp_users WHERE username = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $username);
    mysqli_stmt_execute($stmt);

    mysqli_stmt_close($stmt);
    mysqli_close($conn);
}

testUserRegistration();
?>
