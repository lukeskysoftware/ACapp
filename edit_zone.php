<?php
include 'db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $zone_id = $_POST['zone_id'];
    $name = $_POST['name'];
    $address = $_POST['address'];
    $latitude = $_POST['latitude'];
    $longitude = $_POST['longitude'];
    $days = $_POST['days'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $duration = $_POST['duration'];

    $sql = "UPDATE cp_zones SET name='$name', address='$address', latitude='$latitude', longitude='$longitude' WHERE id='$zone_id'";
    if (mysqli_query($conn, $sql)) {
        // Delete existing slots for the zone
        $sql_delete_slots = "DELETE FROM cp_slots WHERE zone_id='$zone_id'";
        if (!mysqli_query($conn, $sql_delete_slots)) {
            echo "Error deleting slots: " . mysqli_error($conn);
            mysqli_close($conn);
            exit;
        }

        // Generate new time slots based on the provided days, start time, end time, and duration
        foreach ($days as $day) {
            $current_time = strtotime($start_time);
            $end_time_ts = strtotime($end_time);

            while ($current_time + ($duration * 60) <= $end_time_ts) {
                $slot_time = date('H:i:s', $current_time);
                $sql_slot = "INSERT INTO cp_slots (zone_id, day, time) VALUES ('$zone_id', '$day', '$slot_time')";
                if (!mysqli_query($conn, $sql_slot)) {
                    echo "Error inserting slot: " . mysqli_error($conn);
                    mysqli_close($conn);
                    exit;
                }
                $current_time += ($duration * 60);
            }
        }
        echo "Zone updated successfully";
    } else {
        echo "Error updating zone: " . mysqli_error($conn);
    }
    mysqli_close($conn);
}
?>
