
<?php
include 'db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = $_POST['name'];
    $address = $_POST['address'];
    $radius_km = $_POST['radius_km'];

    $sql = "INSERT INTO zones (name, address, radius_km) VALUES ('$name', '$address', '$radius_km')";
    if (mysqli_query($conn, $sql)) {
        echo "New zone created successfully";
    } else {
        echo "Error: " . $sql . "<br>" . mysqli_error($conn);
    }
    mysqli_close($conn);
}
?>
