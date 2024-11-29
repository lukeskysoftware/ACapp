<?php
include 'db.php';

$zone = isset($_GET['zone']) ? $_GET['zone'] : '';

if ($zone) {
    echo json_encode(getAvailableDatesAndTimeslots($zone));
} else {
    echo json_encode([]);
}
?>
