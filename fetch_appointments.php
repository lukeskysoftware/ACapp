<?php
include 'db.php';

$appointments = array();
$sql = "SELECT id, name, appointment_date, appointment_time FROM appointments";
$result = mysqli_query($conn, $sql);

while ($row = mysqli_fetch_assoc($result)) {
    $appointments[] = array(
        'id' => $row['id'],
        'title' => $row['name'],
        'start' => $row['appointment_date'] . 'T' . $row['appointment_time'],
    );
}

echo json_encode($appointments);

mysqli_close($conn);
?>
