<?php
include 'db.php';

// Abilita la visualizzazione degli errori
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$nome = $cognome = $telefono = $indirizzo = $data = $ora = $zona = $notes = "";
$success = $error = "";

// Funzione per cercare pazienti per cognome
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['surname_search'])) {
    $surname_search = htmlspecialchars($_POST['surname_search']);
    $stmt = $conn->prepare("SELECT p.id, p.name, p.surname, p.phone, a.address FROM cp_patients p LEFT JOIN cp_appointments a ON p.id = a.patient_id WHERE p.surname LIKE ?");
    if ($stmt === false) {
        die('Errore nella preparazione della query: ' . $conn->error);
    }
    $search_param = "%{$surname_search}%";
    $stmt->bind_param("s", $search_param);
    $stmt->execute();
    $result = $stmt->get_result();
    $patients = $result->fetch_all(MYSQLI_ASSOC);

    echo '<ul>';
    $seen = [];
    foreach ($patients as $patient) {
        $key = $patient['name'] . '|' . $patient['surname'] . '|' . $patient['phone'] . '|' . $patient['address'];
        if (!isset($seen[$key])) {
            echo '<li style="cursor: pointer;" onclick="selectPatient(' . $patient['id'] . ', \'' . $patient['name'] . '\', \'' . $patient['surname'] . '\', \'' . $patient['phone'] . '\', \'' . $patient['address'] . '\')">' . $patient['name'] . ' ' . $patient['surname'] . ' - ' . $patient['phone'] . ' - ' . $patient['address'] . '</li>';
            $seen[$key] = true;
        }
    }
    echo '</ul>';
    exit;
}

// Funzione per inserire un nuovo appuntamento
if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_POST['surname_search'])) {
    $nome = htmlspecialchars($_POST['nome']);
    $cognome = htmlspecialchars($_POST['cognome']);
    $telefono = htmlspecialchars($_POST['telefono']);
    $indirizzo = htmlspecialchars($_POST['indirizzo']);
    $data = htmlspecialchars($_POST['data']); // Campo data
    $ora = htmlspecialchars($_POST['ora']);   // Campo ora
    $zona = isset($_POST['zone_id']) ? htmlspecialchars($_POST['zone_id']) : null;
    $notes = htmlspecialchars($_POST['notes']);

    if ($zona === null) {
        $zona = 0; // Imposta a 0 se non esiste
    }

    // Controlla se esiste già un appuntamento nella stessa data e ora
    $stmt = $conn->prepare("SELECT id FROM cp_appointments WHERE appointment_date = ? AND appointment_time = ?");
    if ($stmt === false) {
        $error = 'Errore nella preparazione della query: ' . $conn->error;
    } else {
        $stmt->bind_param("ss", $data, $ora);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $error = "Esiste già un appuntamento nella stessa data e ora.";
        } else {
            // Verifica se il paziente esiste già
            $stmt = $conn->prepare("SELECT id FROM cp_patients WHERE name = ? AND surname = ? AND phone = ?");
            if ($stmt === false) {
                $error = 'Errore nella preparazione della query: ' . $conn->error;
            } else {
                $stmt->bind_param("sss", $nome, $cognome, $telefono);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $patient_id = $row['id'];
                } else {
                    // Se il paziente non esiste, crearne uno nuovo
                    $stmt = $conn->prepare("INSERT INTO cp_patients (name, surname, phone, address) VALUES (?, ?, ?, ?)");
                    if ($stmt === false) {
                        $error = 'Errore nella preparazione della query: ' . $conn->error;
                    } else {
                        $stmt->bind_param("ssss", $nome, $cognome, $telefono, $indirizzo);
                        $stmt->execute();
                        $patient_id = $stmt->insert_id;
                    }
                }

                // Inserimento dati nel database
                if (!$error) {
                    $stmt = $conn->prepare("INSERT INTO cp_appointments (patient_id, appointment_date, appointment_time, address, zone_id, notes) VALUES (?, ?, ?, ?, ?, ?)");
                    if ($stmt === false) {
                        $error = 'Errore nella preparazione della query: ' . $conn->error;
                    } else {
                        $stmt->bind_param("isssis", $patient_id, $data, $ora, $indirizzo, $zona, $notes);

                        if ($stmt->execute()) {
                            $success = "Nuovo appuntamento inserito con successo";
                        } else {
                            $error = "Errore: " . $stmt->error;
                        }

                        // Chiudi la connessione
                        $stmt->close();
                    }
                }
            }
        }
    }

    $conn->close();
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
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
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
            const surnameInput = document.getElementById('surname_search').value;
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

        function selectPatient(id, name, surname, phone, address) {
            document.getElementById('nome').value = name;
            document.getElementById('cognome').value = surname;
            document.getElementById('telefono').value = phone;
            document.getElementById('indirizzo').value = address;
            document.getElementById('patientsList').innerHTML = '';
        }

        document.addEventListener('DOMContentLoaded', function() {
            initAutocomplete();
            flatpickr("#data", {
                dateFormat: "Y-m-d",
                allowInput: true
            });
            flatpickr("#ora", {
                enableTime: true,
                noCalendar: true,
                dateFormat: "H:i:S",
                time_24hr: true,
                allowInput: true
            });
        });
    </script>
</head>
<body>
    <?php include 'menu.php'; ?>
    <div class="pure-g aria">
        <div class="pure-u-1">
            <h1 class="centrato">Inserisci un nuovo appuntamento</h1>
        </div>
        <div class="pure-u-1">
            <label for="surname_search">Cerca Paziente per Cognome:</label>
            <input type="text" id="surname_search" name="surname_search" oninput="searchSurname()"><br><br>
        </div>
        <div class="pure-u-1">
            <div id="patientsList"></div>
        </div>
        <?php if ($success): ?>
            <div class="pure-u-1">
                <p><?php echo $success; ?></p>
                <button onclick="window.location.href='insert_appointment.php';">Inserisci un altro appuntamento</button>
                <button onclick="window.location.href='logout.php';">Esci</button>
            </div>
        <?php elseif ($error): ?>
            <div class="pure-u-1">
                <p style="color: red;"><?php echo $error; ?></p>
            </div>
            <div class="pure-u-1">
                <form method="POST" action="insert_appointment.php">
                    <input type="hidden" name="zone_id" value="<?php echo $zona; ?>">
                    
                    <label for="data">Data:</label>
                    <input type="text" id="data" name="data" value="<?php echo $data; ?>" required><br><br>

                    <label for="ora">Ora:</label>
                    <input type="text" id="ora" name="ora" value="<?php echo $ora; ?>" required><br><br>

                    <label for="nome">Nome:</label>
                    <input type="text" id="nome" name="nome" value="<?php echo $nome; ?>" required><br><br>

                    <label for="cognome">Cognome:</label>
                    <input type="text" id="cognome" name="cognome" value="<?php echo $cognome; ?>" required><br><br>

                    <label for="telefono">Telefono:</label>
                    <input type="text" id="telefono" name="telefono" value="<?php echo $telefono; ?>" required><br><br>

                    <label for="indirizzo">Indirizzo:</label>
                    <input type="text" id="indirizzo" name="indirizzo" value="<?php echo $indirizzo; ?>" required class="pac-target-input" placeholder="Inserisci una posizione" autocomplete="off"><br><br>

                    <label for="notes">Note:</label>
                    <textarea id="notes" name="notes"><?php echo $notes; ?></textarea><br><br>

                    <button type="submit">Inserisci Appuntamento</button>
                </form>
            </div>
        <?php else: ?>
            <div class="pure-u-1">
                <form method="POST" action="insert_appointment.php">
                    <input type="hidden" name="zone_id" value="">
                    
                    <label for="data">Data:</label>
                    <input type="text" id="data" name="data" required><br><br>

                    <label for="ora">Ora:</label>
                    <input type="text" id="ora" name="ora" required><br><br>

                    <label for="nome">Nome:</label>
                    <input type="text" id="nome" name="nome" required><br><br>

                    <label for="cognome">Cognome:</label>
                    <input type="text" id="cognome" name="cognome" required><br><br>

                    <label for="telefono">Telefono:</label>
                    <input type="text" id="telefono" name="telefono" required><br><br>

                    <label for="indirizzo">Indirizzo:</label>
                    <input type="text" id="indirizzo" name="indirizzo" required class="pac-target-input" placeholder="Inserisci una posizione" autocomplete="off"><br><br>

                    <label for="notes">Note:</label>
                    <textarea id="notes" name="notes"></textarea><br><br>

                    <button type="submit">Inserisci Appuntamento</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
