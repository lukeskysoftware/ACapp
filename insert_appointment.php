<?php
include 'db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['surname_search'])) {
    $surname_search = htmlspecialchars($_POST['surname_search']);
    $stmt = $conn->prepare("SELECT name, surname, phone, address FROM cp_patients WHERE surname LIKE ?");
    $search_param = "%{$surname_search}%";
    $stmt->bind_param("s", $search_param);
    $stmt->execute();
    $result = $stmt->get_result();
    $patients = $result->fetch_all(MYSQLI_ASSOC);

    echo '<ul>';
    foreach ($patients as $patient) {
        echo '<li style="cursor: pointer;" onclick="selectPatient(\'' . $patient['name'] . '\', \'' . $patient['surname'] . '\', \'' . $patient['phone'] . '\', \'' . $patient['address'] . '\')">' . $patient['name'] . ' ' . $patient['surname'] . ' - ' . $patient['phone'] . ' - ' . $patient['address'] . '</li>';
    }
    echo '</ul>';
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_POST['surname_search'])) {
    // Recupera i dati dalla form e inserisci nel database
    $nome = htmlspecialchars($_POST['nome']);
    $cognome = htmlspecialchars($_POST['cognome']);
    $telefono = htmlspecialchars($_POST['telefono']);
    $indirizzo = htmlspecialchars($_POST['indirizzo']);
    $data = htmlspecialchars($_POST['data']);
    $ora = htmlspecialchars($_POST['ora']);
    $zona = htmlspecialchars($_POST['zona']);
    $notes = htmlspecialchars($_POST['notes']);

    // Verifica connessione
    if ($conn->connect_error) {
        die("Connessione fallita: " . $conn->connect_error);
    }

    // Inserimento dati nel database
    $sql = "INSERT INTO cp_appointments (patient_id, appointment_date, appointment_time, address, zone_id, notes) VALUES (?, ?, ?, ?, ?, ?)";
    
    // Recupera l'ID del paziente (assumendo che il paziente sia già presente nel database, altrimenti questa parte va modificata per creare un nuovo paziente)
    $patient_id = getPatientId($nome, $cognome, $telefono);
    $zona_id = getZoneId($indirizzo, $data);

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isssis", $patient_id, $data, $ora, $indirizzo, $zona_id, $notes);

    if ($stmt->execute()) {
        echo "<div class='centrato aria'>Nuovo appuntamento inserito con successo</div>";
    } else {
        echo "<div class='centrato aria'>Errore: " . $stmt->error . "</div>";
    }

    // Chiudi la connessione
    $stmt->close();
    $conn->close();
}

function getPatientId($nome, $cognome, $telefono) {
    global $conn;
    $sql = "SELECT id FROM cp_patients WHERE name = ? AND surname = ? AND phone = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sss", $nome, $cognome, $telefono);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['id'];
    } else {
        // Se il paziente non esiste, crearne uno nuovo
        $sql = "INSERT INTO cp_patients (name, surname, phone) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sss", $nome, $cognome, $telefono);
        $stmt->execute();
        return $stmt->insert_id;
    }
}

function getZoneId($indirizzo, $data) {
    global $conn;
    $sql = "SELECT z.id, z.name FROM cp_zones z
            JOIN cp_appointments a ON z.id = a.zone_id
            WHERE a.address = ?
            ORDER BY CASE WHEN a.appointment_date = ? THEN 0 ELSE 1 END, z.id";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $indirizzo, $data);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['id'];
    } else {
        return null;
    }
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Inserimento Appuntamenti</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/purecss@3.0.0/build/pure-min.css" integrity="sha384-X38yfunGUhNzHpBaEBsWLO+A0HDYOQi8ufWDkZ0k9e0eXz/tH3II7uKZ9msv++Ls" crossorigin="anonymous">
    <link rel="stylesheet" href="styles.css">
    <?php include 'config.php'; ?>
    <script src="https://maps.googleapis.com/maps/api/js?key=<?php echo GOOGLE_MAPS_API_KEY; ?>&libraries=places"></script>
    <script>
        function initAutocomplete() {
            const input = document.getElementById('indirizzo');
            const options = {
                componentRestrictions: { country: 'it' },
                types: ['address']
            };
            const autocomplete = new google.maps.places.Autocomplete(input, options);
            autocomplete.setFields(['address_component', 'geometry', 'formatted_address']);
            autocomplete.addListener('place_changed', function () {
                const place = autocomplete.getPlace();
                const address = place.formatted_address;
                input.value = address;
            });
        }

        function searchSurname() {
            const surnameInput = document.getElementById('cognome').value;
            if (surnameInput.length > 2) {
                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'insert_appointment.php', true);
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

        function selectPatient(name, surname, phone, address) {
            document.getElementById('nome').value = name;
            document.getElementById('cognome').value = surname;
            document.getElementById('telefono').value = phone;
            document.getElementById('indirizzo').value = address;
            document.getElementById('patientsList').innerHTML = '';
        }

        document.addEventListener('DOMContentLoaded', initAutocomplete);
    </script>
</head>
<body>
    <?php include 'menu.php'; ?>
    <div class="pure-g aria">
        <h1 class="centrato">Inserisci un nuovo appuntamento</h1>
    </div>
    <div class="pure-g aria">
        <form action="insert_appointment.php" method="post" class="pure-form pure-form-aligned centrato aria">
            <div class="pure-control-group">
                <label for="nome">Nome:</label>
                <input type="text" id="nome" name="nome" required>
            </div>
            <div class="pure-control-group">
                <label for="cognome">Cognome:</label>
                <input type="text" id="cognome" name="cognome" oninput="searchSurname()" required>
                <div id="patientsList" style="margin-top: 10px; border: 1px solid #ccc; padding: 5px;"></div>
            </div>
            <div class="pure-control-group">
                <label for="telefono">Telefono:</label>
                <input type="text" id="telefono" name="telefono" required>
            </div>
            <div class="pure-control-group">
                <label for="indirizzo">Indirizzo:</label>
                <input type="text" id="indirizzo" name="indirizzo" required>
            </div>
            <div class="pure-control-group">
                <label for="data">Data:</label>
                <input type="date" id="data" name="data" required>
            </div>
            <div class="pure-control-group">
                <label for="ora">Ora:</label>
                <input type="time" id="ora" name="ora" required>
            </div>
            <div class="pure-control-group">
                <label for="zona">Zona:</label>
                <select id="zona" name="zona" required>
                    <option value="">Seleziona Zona</option>
                    <?php
                    $zones = getZones();
                    foreach ($zones as $zone) {
                        echo "<option value='" . htmlspecialchars($zone) . "'>" . htmlspecialchars($zone) . "</option>";
                    }
                    ?>
                </select>
            </div>
            <div class="pure-control-group">
                <label for="notes">Note:</label>
                <textarea id="notes" name="notes"></textarea>
            </div>
            <div class="pure-controls">
                <input type="submit" value="Inserisci Appuntamento" class="pure-button pure-button-primary">
            </div>
        </form>
    </div>
</body>
</html>