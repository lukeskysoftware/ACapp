<?php
include 'db.php';

if (isset($_GET['zone_id'])) {
    $zone_id = $_GET['zone_id'];
    
    // Fetch zone data
    $zoneSql = "SELECT * FROM cp_zones WHERE id = '$zone_id'";
    $zoneResult = mysqli_query($conn, $zoneSql);

    if ($zoneResult) {
        $zone = mysqli_fetch_assoc($zoneResult);

        // Fetch slots data
        $slotsSql = "SELECT day, time FROM cp_slots WHERE zone_id = '$zone_id'";
        $slotsResult = mysqli_query($conn, $slotsSql);

        if ($slotsResult) {
            $slots = [];
            while ($row = mysqli_fetch_assoc($slotsResult)) {
                $slots[] = $row;
            }
            $zone['slots'] = $slots;
        } else {
            $zone['slots'] = [];
        }

        echo json_encode($zone);
    } else {
        echo json_encode(['error' => 'Error fetching zone data']);
    }

    mysqli_close($conn);
} else {
    echo json_encode(['error' => 'Zone ID not provided']);
}
?>
