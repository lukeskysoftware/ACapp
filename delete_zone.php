<?php
include 'db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $zone_id = $_POST['zone_id'];

    // Delete associated slots
    $sql_slots = "DELETE FROM cp_slots WHERE zone_id = '$zone_id'";
    mysqli_query($conn, $sql_slots);

    // Delete the zone
    $sql_zone = "DELETE FROM cp_zones WHERE id = '$zone_id'";
    if (mysqli_query($conn, $sql_zone)) {
        header("Location: dashboard.php");
        exit();
    } else {
        echo "Error: " . $sql_zone . "<br>" . mysqli_error($conn);
    }
    mysqli_close($conn);
}
?>
