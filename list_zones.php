<?php
include 'db.php';
include 'menu.php'; // Include the menu
echo "<link rel='stylesheet' type='text/css' href='styles.css'>";
$cssurl = "https://cdn.jsdelivr.net/npm/purecss@3.0.0/build/pure-min.css";
echo "<link rel='stylesheet' type='text/css' href='$cssurl'>";
$sql = "SELECT * FROM cp_zones";
$result = mysqli_query($conn, $sql);
echo "<div class='pure-g aria'>";
echo "<h2 class='centrato centro'>Gestione delle Zone </h2> </div>";
echo "<div class='pure-g aria'><table border='0' class='pure-table pure-table-bordered centrato aria'>;
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
            <form action='edit_zone_form.html' method='get' style='display:inline-block;'  class='pure-form pure-form-aligned centrato centro'>
                <input type='hidden' name='zone_id' value='" . $row['id'] . "'>
                <button type='submit' class='modifica-btn pure-button button-small button-green'>Modifica</button>
            </form>
            <form action='delete_zone.php' method='post' style='display:inline-block;' onsubmit='return confirm(\"Sei sicuro di voler eliminare questa zona?\");'>
                <input type='hidden' name='zone_id' value='" . $row['id'] . "'>
                <button type='submit' class='cancella-btn pure-button button-small button-red'>Elimina</button>
            </form>
          </td>";
    echo "</tr>";
}
echo "</table>";
echo "</div>";

mysqli_close($conn);
?>
