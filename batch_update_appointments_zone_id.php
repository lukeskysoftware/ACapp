<?php
include 'db.php';

// Aggiorna zone_id in cp_appointments in base a address_cache_zone_map
$sql = "SELECT id, address FROM cp_appointments";
$res = $conn->query($sql);
$count = 0; $updated = 0; $skipped = 0;
while ($row = $res->fetch_assoc()) {
    $app_id = $row['id'];
    $address = trim($row['address']);
    if ($address == '') { $skipped++; continue; }

    // Prendi la prima zona associata all'indirizzo (puoi migliorare scegliendo la migliore)
    $zone_sql = "SELECT zone_id FROM address_cache_zone_map aczm
        JOIN address_cache ac ON aczm.address_cache_id = ac.id
        WHERE ac.address = ? LIMIT 1";
    $zone_stmt = $conn->prepare($zone_sql);
    $zone_stmt->bind_param("s", $address);
    $zone_stmt->execute();
    $zone_res = $zone_stmt->get_result();
    if ($zone_row = $zone_res->fetch_assoc()) {
        $zone_id = $zone_row['zone_id'];
        // Aggiorna zone_id solo se diverso/da aggiornare
        $update_sql = "UPDATE cp_appointments SET zone_id = ? WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("ii", $zone_id, $app_id);
        if ($update_stmt->execute()) $updated++;
        $update_stmt->close();
    } else {
        $skipped++;
    }
    $zone_stmt->close();
    $count++;
}
echo "Batch completato.<br>Appuntamenti trovati: $count<br>Aggiornati: $updated<br>Non aggiornati (no zona): $skipped<br>";
$conn->close();
?>