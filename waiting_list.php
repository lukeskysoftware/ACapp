<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit(); // Ensure the script stops executing after the redirect
}

include 'db.php';

function getWaitingPatients() {
    global $conn;
    // Get the date 20 days ago
    $date_20_days_ago = date('Y-m-d', strtotime('-20 days'));
    // Query to select patients registered from 20 days ago to today who do not have an appointment
    $sql = "SELECT p.id, p.name, p.surname, p.phone, p.created_at 
            FROM cp_patients p
            LEFT JOIN cp_appointments a ON p.id = a.patient_id
            WHERE p.created_at >= '$date_20_days_ago' AND a.id IS NULL
            ORDER BY p.created_at ASC";
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
    <style>
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            text-align: center;
        }
        .table-container {
            overflow-x: auto;
        }
        table {
            margin: 0 auto;
        }
        th, td {
            text-align: center;
        }
    </style>
</head>
<body>
    <?php include 'menu.php'; ?>
    <div class="container">
        <h2>Lista Pazienti in Attesa</h2>
        <p>In questa lista sono elencati i pazienti che negli ultimi 20 giorni hanno richiesta visita</p>
        <p></p>
        <div class="table-container">
            <table class="pure-table pure-table-bordered">
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
                            <a href="combined_address_calculate.php?name=<?php echo urlencode($patient['name']); ?>&surname=<?php echo urlencode($patient['surname']); ?>&phone=<?php echo urlencode($patient['phone']); ?>" class="pure-button">Inserisci Appuntamento Zona</a>
                            <a href="insert_appointment.php?name=<?php echo urlencode($patient['name']); ?>&surname=<?php echo urlencode($patient['surname']); ?>&phone=<?php echo urlencode($patient['phone']); ?>" class="pure-button pure-button-primary">Inserisci Appuntamento</a>
                        </td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
