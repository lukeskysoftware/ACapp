<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit(); // Ensure the script stops executing after the redirect
}

include 'db.php';

function getWaitingPatients() {
    global $conn;
    $sql = "SELECT id, name, surname, phone, created_at FROM cp_patients ORDER BY created_at ASC";
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        die('Error: ' . mysqli_error($conn));
    }
    return mysqli_fetch_all($result, MYSQLI_ASSOC);
}

$patients = getWaitingPatients();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Lista Pazienti in Attesa</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/purecss@3.0.0/build/pure-min.css">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <?php include 'menu.php'; ?>
    <div class="pure-g aria">
        <h2 class="centrato">Lista Pazienti in Attesa</h2>
        <table class="pure-table pure-table-bordered centrato aria">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Cognome</th>
                    <th>Telefono</th>
                    <th>Data di Registrazione</th>
                    <th>Azioni</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($patients as $patient) { ?>
                <tr>
                    <td><?php echo htmlspecialchars($patient['name']); ?></td>
                    <td><?php echo htmlspecialchars($patient['surname']); ?></td>
                    <td><?php echo htmlspecialchars($patient['phone']); ?></td>
                    <td><?php echo htmlspecialchars($patient['created_at']); ?></td>
                    <td>
                        <a href="address_calculate.html?name=<?php echo urlencode($patient['name']); ?>&surname=<?php echo urlencode($patient['surname']); ?>&phone=<?php echo urlencode($patient['phone']); ?>" class="pure-button pure-button-primary">Crea Appuntamento Zona</a>
                        <a href="insert_appointment.php?name=<?php echo urlencode($patient['name']); ?>&surname=<?php echo urlencode($patient['surname']); ?>&phone=<?php echo urlencode($patient['phone']); ?>" class="pure-button pure-button-primary">Inserisci Appuntamento</a>
                    </td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</body>
</html>