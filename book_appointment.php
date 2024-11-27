
<?php
include 'db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $zone_id = $_POST['zone_id'];
    $name = $_POST['name'];
    $phone = $_POST['phone'];
    $notes = $_POST['notes'];
    $appointment_date = $_POST['appointment_date'];
    $appointment_time = $_POST['appointment_time'];

    $sql = "INSERT INTO appointments (zone_id, name, phone, notes, appointment_date, appointment_time) VALUES ('$zone_id', '$name', '$phone', '$notes', '$appointment_date', '$appointment_time')";
    if (mysqli_query($conn, $sql)) {
        echo "Appointment booked successfully";
    } else {
        echo "Error: " . $sql . "<br>" . mysqli_error($conn);
    }
    mysqli_close($conn);
}
?>
