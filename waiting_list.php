<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit(); // Ensure the script stops executing after the redirect
}

include 'db.php';

function getWaitingPatients() {
    global $conn;
    $today = date('Y-m-d');
    $date_20_days_ago = date('Y-m-d', strtotime('-20 days'));

    // Query per selezionare i pazienti registrati negli ultimi 20 giorni
    $sql_patients = "SELECT p.id, p.name, p.surname, p.phone, p.created_at 
                     FROM cp_patients p
                     WHERE p.created_at BETWEEN '$date_20_days_ago' AND '$today'";
    $result_patients = mysqli_query($conn, $sql_patients);
    if (!$result_patients) {
        die('Error: ' . mysqli_error($conn));
    }
    $patients = mysqli_fetch_all($result_patients, MYSQLI_ASSOC);

    $patients_without_appointments = [];
    $patients_with_possible_appointments = [];

    foreach ($patients as $patient) {
        $patient_id = $patient['id'];
        // Query per verificare se il paziente ha un appuntamento da 20 giorni fa fino al futuro
        $appointment_sql = "SELECT a.appointment_date, a.appointment_time, a.address 
                            FROM cp_appointments a 
                            WHERE a.patient_id = '$patient_id' AND a.appointment_date >= '$date_20_days_ago'";
        $appointment_result = mysqli_query($conn, $appointment_sql);
        if (!$appointment_result) {
            die('Error: ' . mysqli_error($conn));
        }
        $appointments = mysqli_fetch_all($appointment_result, MYSQLI_ASSOC);

        $has_future_appointment = false;
        foreach ($appointments as $appointment) {
            if ($patient['phone'] == $appointment['patient_phone']) {
                $has_future_appointment = true;
                break;
            }
        }

        if ($has_future_appointment) {
            // Escludere i pazienti con lo stesso telefono
            continue;
        } elseif (!empty($appointments)) {
            // Se il paziente ha un appuntamento futuro, includere con dettagli
            foreach ($appointments as $appointment) {
                $patient['appointment_info'] = "Questo paziente potrebbe avere un appuntamento già registrato.<br>" .
                                               "Giorno: {$appointment['appointment_date']}<br>" .
                                               "Ora: {$appointment['appointment_time']}<br>" .
                                               "Indirizzo: {$appointment['address']}<br>";
                $patients_with_possible_appointments[] = $patient;
            }
        } else {
            // Nessun appuntamento futuro, includere senza dettagli
            $patients_without_appointments[] = $patient;
        }
    }

    return [
        'without_appointments' => $patients_without_appointments,
        'with_possible_appointments' => $patients_with_possible_appointments,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_patient_id'])) {
    $delete_patient_id = $_POST['delete_patient_id'];
    $delete_sql = "DELETE FROM cp_patients WHERE id = '$delete_patient_id'";
    mysqli_query($conn, $delete_sql);
    header("Location: waiting_list.php");
    exit();
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
        .hidden {
            display: none;
        }
        .btn-delete {
            background-color: red;
            color: white;
        }
        .btn-confirm-delete {
            background-color: darkred;
            color: white;
        }
    </style>
    <script>
        function confirmDelete(patientId, patientName, patientSurname, patientPhone) {
            if (confirm(`Vuoi cancellare il paziente ${patientName} ${patientSurname} con numero di telefono ${patientPhone}?`)) {
                const deleteButton = document.getElementById(`delete-btn-${patientId}`);
                deleteButton.innerHTML = 'Conferma Eliminazione';
                deleteButton.classList.add('btn-confirm-delete');
                deleteButton.onclick = function() {
                    document.getElementById(`confirm-delete-${patientId}`).submit();
                };
            }
        }
    </script>
</head>
<body>
    <?php include 'menu.php'; ?>
    <div class="container">
        <h2>Lista Pazienti in Attesa</h2>
        <p>In questa lista sono elencati i pazienti che negli ultimi 20 giorni hanno richiesta visita</p>
        <p></p>
        <div class="table-container">
            <h3>Pazienti senza appuntamenti futuri</h3>
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
                    <?php foreach ($patients['without_appointments'] as $patient) { ?>
                    <tr>
                        <td><?php echo htmlspecialchars($patient['name']); ?></td>
                        <td><?php echo htmlspecialchars($patient['surname']); ?></td>
                        <td><?php echo htmlspecialchars($patient['phone']); ?></td>
                        <td><?php echo htmlspecialchars($patient['created_at']); ?></td>
                        <td>
                            <a href="combined_address_calculate_v2.php?name=<?php echo urlencode($patient['name']); ?>&surname=<?php echo urlencode($patient['surname']); ?>&phone=<?php echo urlencode($patient['phone']); ?>" class="pure-button">Inserisci Appuntamento Zona</a>
                            <a href="insert_appointment.php?name=<?php echo urlencode($patient['name']); ?>&surname=<?php echo urlencode($patient['surname']); ?>&phone=<?php echo urlencode($patient['phone']); ?>" class="pure-button pure-button-primary">Inserisci Appuntamento</a>
                            <form id="confirm-delete-<?php echo $patient['id']; ?>" method="POST" style="display:inline;">
                                <input type="hidden" name="delete_patient_id" value="<?php echo $patient['id']; ?>">
                                <button type="button" id="delete-btn-<?php echo $patient['id']; ?>" class="pure-button btn-delete" onclick="confirmDelete('<?php echo $patient['id']; ?>', '<?php echo htmlspecialchars($patient['name']); ?>', '<?php echo htmlspecialchars($patient['surname']); ?>', '<?php echo htmlspecialchars($patient['phone']); ?>')">Cancella</button>
                            </form>
                        </td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>

            <!-- Nascondi la lista dei pazienti con possibili appuntamenti futuri -->
            <div class="hidden">
                <h3>Pazienti con possibili appuntamenti futuri</h3>
                <table class="pure-table pure-table-bordered">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Cognome</th>
                            <th>Telefono</th>
                            <th>Data di Registrazione</th>
                            <th>Dettagli Appuntamento</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($patients['with_possible_appointments'] as $patient) { ?>
                        <tr>
                            <td><?php echo htmlspecialchars($patient['name']); ?></td>
                            <td><?php echo htmlspecialchars($patient['surname']); ?></td>
                            <td><?php echo htmlspecialchars($patient['phone']); ?></td>
                            <td><?php echo htmlspecialchars($patient['created_at']); ?></td>
                            <td><?php echo $patient['appointment_info']; ?></td>
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
    </div>
</body>
</html>
