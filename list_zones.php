<?php
include 'db.php';
include 'menu.php'; // Include the menu
echo "<link rel='stylesheet' type='text/css' href='styles.css'>";
$bootstrapCssUrl = "https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css";
echo "<link rel='stylesheet' type='text/css' href='$bootstrapCssUrl'>";
$sql = "SELECT * FROM cp_zones";
$result = mysqli_query($conn, $sql);
echo "<div class='container'>";
echo "<h2 class='text-center my-4'>Gestione delle Zone</h2>";
echo "<div class='table-responsive'><table class='table table-bordered text-center'>
<tr>
<th>Nome della Zona</th>
<th>Indirizzo</th>
<th>Azioni</th>
</tr>";

while ($row = mysqli_fetch_assoc($result)) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($row['name']) . "</td>";
    echo "<td>" . htmlspecialchars($row['address']) . "</td>";
    echo "<td>
            <form action='edit_zone_form.php' method='get' class='d-inline-block'>
                <input type='hidden' name='zone_id' value='" . htmlspecialchars($row['id']) . "'>
                <button type='submit' class='btn btn-success btn-sm'>Modifica</button>
            </form>
            <form action='delete_zone.php' method='post' class='d-inline-block' onsubmit='return confirm(\"Sei sicuro di voler eliminare questa zona?\");'>
                <input type='hidden' name='zone_id' value='" . htmlspecialchars($row['id']) . "'>
                <button type='submit' class='btn btn-danger btn-sm'>Elimina</button>
            </form>
          </td>";
    echo "</tr>";
}
echo "</table>";
echo "</div>";
echo "</div>";

mysqli_close($conn);
?>
