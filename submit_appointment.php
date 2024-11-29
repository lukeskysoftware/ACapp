<?php
include 'db.php';

function formatDateItalian($date) {
    setlocale(LC_TIME, 'it_IT.UTF-8');
    $timestamp = strtotime($date);
    return strftime("%A %d %B %Y", $timestamp);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $zone_id = $_POST['zone_id'];
    $date = $_POST['date'];
    $time = $_POST['time'];
    $name = $_POST['name'];
    $surname = $_POST['surname'];
    $phone = $_POST['phone'];
    $address = $_POST['address'];
    $notes = $_POST['notes'];

    // Debugging: Log the received POST data
    error_log("Received POST data: zone_id={$zone_id}, date={$date}, time={$time}, name={$name}, surname={$surname}, phone={$phone}, address={$address}, notes={$notes}");

    // Ensure all parameters are received
    if (!isset($zone_id) || !isset($date) || !isset($time) || !isset($name) || !isset($surname) || !isset($phone) || !isset($address)) {
        echo "Missing parameters!";
        exit;
    }

    // Check if the appointment time slot is already booked
    $query = $conn->prepare("SELECT COUNT(*) AS count FROM cp_appointments WHERE zone_id = ? AND appointment_date = ? AND appointment_time = ?");
    $query->bind_param("iss", $zone_id, $date, $time);
    $query->execute();
    $result = $query->get_result();
    $row = $result->fetch_assoc();

    if ($row['count'] > 0) {
        echo "This time slot is already booked. Please choose another time.";
        exit;
    }

    // Check if patient already exists
    $sql_check = "SELECT id FROM cp_patients WHERE name = ? AND surname = ? AND phone = ?";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->bind_param("sss", $name, $surname, $phone);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();

    if ($result_check->num_rows > 0) {
        // Patient exists, get the patient ID
        $patient = $result_check->fetch_assoc();
        $patient_id = $patient['id'];
    } else {
        // Insert patient data
        $sql1 = "INSERT INTO cp_patients (name, surname, phone) VALUES (?, ?, ?)";
        $stmt1 = $conn->prepare($sql1);
        $stmt1->bind_param("sss", $name, $surname, $phone);
        if (!$stmt1->execute()) {
            error_log("Database query failed for adding patient: " . mysqli_error($conn));
            echo "Database query failed for adding patient: " . mysqli_error($conn);
            exit;
        }
        $patient_id = $conn->insert_id;
    }

    // Insert appointment data with address and notes
    $sql2 = "INSERT INTO cp_appointments (zone_id, patient_id, appointment_date, appointment_time, address, notes) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt2 = $conn->prepare($sql2);
    $stmt2->bind_param("iissss", $zone_id, $patient_id, $date, $time, $address, $notes);
    if (!$stmt2->execute()) {
        error_log("Database query failed for adding appointment: " . mysqli_error($conn));
        echo "Database query failed for adding appointment: " . mysqli_error($conn);
        exit;
    }

    echo "<p>Appuntamento prenotato con successo!</p>";
    echo "<p>Data: " . formatDateItalian($date) . " Ora: " . htmlspecialchars($time) . "</p>";
    echo "<p>Cognome: " . htmlspecialchars($surname) . "</p>";
    echo "<p>Nome: " . htmlspecialchars($name) . "</p>";
    echo "<p>Telefono: " . htmlspecialchars($phone) . "</p>";
    echo "<p>Indirizzo: " . htmlspecialchars($address) . "</p>";
    echo "<p>Note: " . htmlspecialchars($notes) . "</p>";
    echo '<button onclick="window.location.href=\'dashboard.php\'">Vai alla Dashboard</button>';
}
?>
