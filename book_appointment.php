<?php
include 'db.php';
include 'menu.php';

$zone_id = isset($_GET['zone_id']) ? $_GET['zone_id'] : null;
$date = isset($_GET['date']) ? $_GET['date'] : null;
$time = isset($_GET['time']) ? $_GET['time'] : null;

if ($_SERVER["REQUEST_METHOD"] == "GET") {
    // Debugging: Log the received GET data
    error_log("Received GET data: zone_id={$zone_id}, date={$date}, time={$time}");

    // Ensure all parameters are received
    if (!$zone_id || !$date || !$time) {
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

    // Debugging: Log the JSON response
    error_log("JSON response: " . json_encode($patients));

    echo json_encode($patients);
    exit;
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Prenota Appuntamento</title>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const surnameInput = document.getElementById('surname_search');
            const nameInput = document.getElementById('name');
            const surnameField = document.getElementById('surname');
            const phoneInput = document.getElementById('phone');
            const patientsList = document.createElement('ul');
            patientsList.id = 'patientsList';
            surnameInput.parentNode.appendChild(patientsList);

            surnameInput.addEventListener('input', function() {
                const surname = surnameInput.value;
                if (surname.length > 2) {
                    fetch('book_appointment.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: 'surname_search=' + encodeURIComponent(surname)
                    })
                    .then(response => response.json())
                    .then(data => {
                        patientsList.innerHTML = '';
                        data.forEach(patient => {
                            const listItem = document.createElement('li');
                            listItem.textContent = `${patient.name} ${patient.surname} - ${patient.phone}`;
                            listItem.addEventListener('click', function() {
                                nameInput.value = patient.name;
                                surnameField.value = patient.surname;
                                phoneInput.value = patient.phone;
                                patientsList.innerHTML = '';
                            });
                            patientsList.appendChild(listItem);
                        });
                    })
                    .catch(error => {
                        console.error('Error parsing JSON:', error);
                        patientsList.innerHTML = 'Error loading patients';
                    });
                } else {
                    patientsList.innerHTML = '';
                }
            });
        });
    </script>
</head>
<body>
    <h1>Prenota Appuntamento</h1>
    <label for="surname_search">Cerca Paziente per Cognome:</label>
    <input type="text" id="surname_search" name="surname_search"><br><br>

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
