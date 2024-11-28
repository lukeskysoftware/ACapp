<?php
include 'db.php';
include 'menu.php';

if ($_SERVER["REQUEST_METHOD"] == "GET") {
    $zone_id = $_GET['zone_id'];
    $date = $_GET['date'];
    $time = $_GET['time'];

    // Debugging: Log the received GET data
    error_log("Received GET data: zone_id={$zone_id}, date={$date}, time={$time}");

    // Ensure all parameters are received
    if (!isset($zone_id) || !isset($date) || !isset($time)) {
        echo "Missing parameters!";
        exit;
    }

    // (Rest of your booking logic here)

    echo "Booking details: Zone ID: $zone_id, Date: $date, Time: $time";
}
?>
