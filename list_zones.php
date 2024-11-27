<?php
include 'db.php';
include 'menu.php'; // Include the menu

$sql = "SELECT * FROM cp_zones";
$result = mysqli_query($conn, $sql);

echo "<h2>Gestione delle Zone</h2>";
echo "<table border='1'>
<tr>
<th>Nome della Zona</th>
<th>Indirizzo</th>
<th>Azioni</th>
</tr>";

while ($row = mysqli_fetch_assoc($result)) {
    echo "<tr>";
    echo "<td>" . $row['name'] . "</td>";
    echo "<td>" . $row['address'] . "</td>";
    echo "<td>
            <form action='edit_zone_form.html' method='get' style='display:inline-block;'>
                <input type='hidden' name='zone_id' value='" . $row['id'] . "'>
                <button type='submit'>Modifica</button>
            </form>
            <form action='delete_zone.php' method='post' style='display:inline-block;' onsubmit='return confirm(\"Sei sicuro di voler eliminare questa zona?\");'>
                <input type='hidden' name='zone_id' value='" . $row['id'] . "'>
                <button type='submit'>Elimina</button>
            </form>
          </td>";
    echo "</tr>";
}
echo "</table>";

mysqli_close($conn);
?>
