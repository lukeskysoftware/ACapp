<?php
include 'db.php';

$zone_id = isset($_GET['zone_id']) ? $_GET['zone_id'] : null;
$date = isset($_GET['date']) ? $_GET['date'] : null;
$time = isset($_GET['time']) ? $_GET['time'] : null;
$address = isset($_GET['address']) ? $_GET['address'] : null;
$name = isset($_GET['name']) ? $_GET['name'] : null;
$surname = isset($_GET['surname']) ? $_GET['surname'] : null;
$phone = isset($_GET['phone']) ? $_GET['phone'] : null;

if ($_SERVER["REQUEST_METHOD"] == "GET") {
    // Debugging: Log the received GET data
    error_log("Received GET data: zone_id={$zone_id}, date={$date}, time={$time}, address={$address}");

    // Ensure all parameters are received
    if (!$zone_id || !$date || !$time || !$address) {
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

// Endpoint to fetch patients by surname
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['surname_search'])) {
    $surname_search = $_POST['surname_search'];
    $stmt = $conn->prepare("SELECT id, name, surname, phone FROM cp_patients WHERE surname LIKE ?");
    $search_param = "%{$surname_search}%";
    $stmt->bind_param("s", $search_param);
    $stmt->execute();
    $result = $stmt->get_result();
    $patients = $result->fetch_all(MYSQLI_ASSOC);

    echo '<ul>';
    foreach ($patients as $patient) {
        echo '<li style="cursor: pointer;" onclick="selectPatient(\'' . $patient['name'] . '\', \'' . $patient['surname'] . '\', \'' . $patient['phone'] . '\')">' . $patient['name'] . ' ' . $patient['surname'] . '</li>';
    }
    echo '</ul>';
    exit;
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/purecss@3.0.0/build/pure-min.css" integrity="sha384-X38yfunGUhNzHpBaEBsWLO+A0HDYOQi8ufWDkZ0k9e0eXz/tH3II7uKZ9msv++Ls" crossorigin="anonymous">
    <title>Prenota Appuntamento</title>
    <style>
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            text-align: center;
        }
        .menu {
            margin-bottom: 20px;
        }
    </style>
    <script>
        function searchSurname() {
            const surnameInput = document.getElementById('surname_search').value;
            if (surnameInput.length > 2) {
                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'book_appointment.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onreadystatechange = function () {
                    if (xhr.readyState === 4 && xhr.status === 200) {
                        document.getElementById('patientsList').innerHTML = xhr.responseText;
                    }
                };
                xhr.send('surname_search=' + encodeURIComponent(surnameInput));
            } else {
                document.getElementById('patientsList').innerHTML = '';
            }
        }

        function selectPatient(name, surname, phone) {
            document.getElementById('name').value = name;
            document.getElementById('surname').value = surname;
            document.getElementById('phone').value = phone;
            document.getElementById('patientsList').innerHTML = '';
        }
    </script>
</head>
<body>
    <div class="menu">
        <?php include 'menu.php'; ?>
    </div>
    <div class="container">
        <h1>Prenota Appuntamento</h1>
        <label for="surname_search">Cerca Paziente per Cognome:</label>
        <input type="text" id="surname_search" name="surname_search" oninput="searchSurname()"><br><br>
        <div id="patientsList"></div>

        <form method="POST" action="submit_appointment.php" class="pure-form pure-form-stacked">
            <input type="hidden" name="zone_id" value="<?php echo htmlspecialchars($zone_id); ?>">
            <input type="hidden" name="date" value="<?php echo htmlspecialchars($date); ?>">
            <input type="hidden" name="time" value="<?php echo htmlspecialchars($time); ?>">
            <input type="hidden" name="address" value="<?php echo htmlspecialchars($address); ?>">

            <label for="name">Nome:</label>
            <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($name); ?>" required><br><br>

            <label for="surname">Cognome:</label>
            <input type="text" id="surname" name="surname" value="<?php echo htmlspecialchars($surname); ?>" required><br><br>

            <label for="phone">Telefono:</label>
            <input type="text" id="phone" name="phone" value="<?php echo htmlspecialchars($phone); ?>" required><br><br>

            <p>Indirizzo: <?php echo htmlspecialchars($address); ?></p>

            <label for="notes">Note:</label>
            <textarea id="notes" name="notes"></textarea><br><br>

            <button type="submit" class="pure-button pure-button-primary">Prenota</button>
        </form>
    </div>
</body>
</html>
