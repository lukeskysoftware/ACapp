<?php
include 'db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $zone_id = $_POST['zone_id'];
    $date = $_POST['date'];
    $time = $_POST['time'];
    $name = $_POST['name'];
    $surname = $_POST['surname'];
    $phone = $_POST['phone'];
    $notes = $_POST['notes'];

    // Debugging: Log the received POST data
    error_log("Received POST data: zone_id={$zone_id}, date={$date}, time={$time}, name={$name}, surname={$surname}, phone={$phone}, notes={$notes}");

    // Ensure all parameters are received
    if (!isset($zone_id) || !isset($date) || !isset($time) || !isset($name) || !isset($surname) || !isset($phone)) {
        echo "Missing parameters!";
        exit;
    }

    // Insert patient data
    $sql1 = "INSERT INTO cp_patients (name, surname, phone, notes) VALUES (?, ?, ?, ?)";
    $stmt1 = $conn->prepare($sql1);
    $stmt1->bind_param("ssss", $name, $surname, $phone, $notes);
    if (!$stmt1->execute()) {
        error_log("Database query failed for adding patient: " . mysqli_error($conn));
        echo "Database query failed for adding patient: " . mysqli_error($conn);
        exit;
    }
    $patient_id = $conn->insert_id;

    // Insert appointment data
    $sql2 = "INSERT INTO cp_appointments (zone_id, patient_id, appointment_date, appointment_time) VALUES (?, ?, ?, ?)";
    $stmt2 = $conn->prepare($sql2);
    $stmt2->bind_param("iiss", $zone_id, $patient_id, $date, $time);
    if (!$stmt2->execute()) {
        error_log("Database query failed for adding appointment: " . mysqli_error($conn));
        echo "Database query failed for adding appointment: " . mysqli_error($conn);
        exit;
    }

    echo "Appuntamento prenotato con successo!";
}
?>
