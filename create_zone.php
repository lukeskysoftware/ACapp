<?php
include 'db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = $_POST['name'];
    $address = $_POST['address'];
    $radius_km = $_POST['radius_km'];
    $days = $_POST['days'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $duration = $_POST['duration'];

    $sql = "INSERT INTO zones (name, address, radius_km) VALUES ('$name', '$address', '$radius_km')";
    if (mysqli_query($conn, $sql)) {
        $zone_id = mysqli_insert_id($conn);
        
        // Generate time slots based on the provided days, start time, end time, and duration
        foreach ($days as $day) {
            $current_time = strtotime($start_time);
            $end_time_ts = strtotime($end_time);

            while ($current_time + ($duration * 60) <= $end_time_ts) {
                $slot_time = date('H:i:s', $current_time);
                $sql_slot = "INSERT INTO slots (zone_id, day, time) VALUES ('$zone_id', '$day', '$slot_time')";
                mysqli_query($conn, $sql_slot);
                $current_time += ($duration * 60);
            }
        }
        echo "New zone and slots created successfully";
    } else {
        echo "Error: " . $sql . "<br>" . mysqli_error($conn);
    }
    mysqli_close($conn);
}
?>
