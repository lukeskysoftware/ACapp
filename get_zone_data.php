<?php
include 'db.php';

if (isset($_GET['zone_id'])) {
    $zone_id = $_GET['zone_id'];
    $sql = "SELECT * FROM cp_zones WHERE id = '$zone_id'";
    $result = mysqli_query($conn, $sql);

    if ($result) {
        $zone = mysqli_fetch_assoc($result);
        echo json_encode($zone);
    } else {
        echo json_encode(['error' => 'Error fetching data']);
    }

    mysqli_close($conn);
} else {
    echo json_encode(['error' => 'Zone ID not provided']);
}
?>
