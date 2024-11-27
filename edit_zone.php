<?php
include 'db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $zone_id = $_POST['zone_id'];
    $name = $_POST['name'];
    $address = $_POST['address'];
    $latitude = $_POST['latitude'];
    $longitude = $_POST['longitude'];
    $slots = $_POST['slots'];

    $sql = "UPDATE cp_zones SET name='$name', address='$address', latitude='$latitude', longitude='$longitude' WHERE id='$zone_id'";
    if (mysqli_query($conn, $sql)) {
        // Delete existing slots for the zone
        $sql_delete_slots = "DELETE FROM cp_slots WHERE zone_id='$zone_id'";
        if (!mysqli_query($conn, $sql_delete_slots)) {
            echo "Error deleting slots: " . mysqli_error($conn);
            mysqli_close($conn);
            exit;
        }

        // Insert new slots
        $slot_inserted = false;
        foreach ($slots as $day => $times) {
            foreach ($times as $time) {
                $sql_slot = "INSERT INTO cp_slots (zone_id, day, time) VALUES ('$zone_id', '$day', '$time')";
                if (!mysqli_query($conn, $sql_slot)) {
                    echo "Error inserting slot: " . mysqli_error($conn);
                    mysqli_close($conn);
                    exit;
                }
                $slot_inserted = true;
            }
        }

        if ($slot_inserted) {
            echo "Zone updated successfully";
        } else {
            echo "No slots were updated";
        }
    } else {
        echo "Error updating zone: " . mysqli_error($conn);
    }
    mysqli_close($conn);
}
?>
