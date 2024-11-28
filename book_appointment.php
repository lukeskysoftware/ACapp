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

    // Check if the appointment time slot is already booked
    $query = $conn->prepare("SELECT COUNT(*) AS count FROM cp_appointments WHERE zone_id = ? AND appointment_date = ? AND appointment_time = ?");
    $query->bind_param("iss", $zone_id, $date, $time);
    $query->execute();
    $result = $query->get_result();
    $row = $result->fetch_assoc();

    if ($row['count'] > 0) {
        echo "This time slot is already booked. Please choose another time.";
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Prenota Appuntamento</title>
</head>
<body>
    <h1>Prenota Appuntamento</h1>
    <form method="POST" action="submit_appointment.php">
        <input type="hidden" name="zone_id" value="<?php echo htmlspecialchars($zone_id); ?>">
        <input type="hidden" name="date" value="<?php echo htmlspecialchars($date); ?>">
        <input type="hidden" name="time" value="<?php echo htmlspecialchars($time); ?>">

        <label for="name">Nome:</label>
        <input type="text" id="name" name="name" required><br><br>

        <label for="surname">Cognome:</label>
        <input type="text" id="surname" name="surname" required><br><br>

        <label for="phone">Telefono:</label>
        <input type="text" id="phone" name="phone" required><br><br>

        <label for="notes">Note:</label>
        <textarea id="notes" name="notes"></textarea><br><br>

        <button type="submit">Prenota</button>
    </form>
</body>
</html>
