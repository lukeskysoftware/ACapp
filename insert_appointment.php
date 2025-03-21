<?php
include 'db.php';

// Fetch Google Maps API key from the config table
$apiKey = '';
$sql = "SELECT value FROM config WHERE name = 'GOOGLE_MAPS_API_KEY'";
$result = mysqli_query($conn, $sql);
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $apiKey = $row['value'];
} else {
    die('Errore nel recupero della chiave API di Google Maps: ' . mysqli_error($conn));
}

// Enable error reporting
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$nome = isset($_GET['name']) ? htmlspecialchars($_GET['name']) : "";
$cognome = isset($_GET['surname']) ? htmlspecialchars($_GET['surname']) : "";
$telefono = isset($_GET['phone']) ? htmlspecialchars($_GET['phone']) : "";
$indirizzo = $data = $ora = $zona = $notes = "";
$success = $error = "";

// Function to search patients by surname
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['surname_search'])) {
    $surname_search = htmlspecialchars($_POST['surname_search']);
    $stmt = $conn->prepare("SELECT p.id, p.name, p.surname, p.phone, a.address 
                            FROM cp_patients p 
                            LEFT JOIN cp_appointments a ON p.id = a.patient_id 
                            WHERE p.surname LIKE ?");
    if ($stmt === false) {
        die('Errore nella preparazione della query: ' . $conn->error);
    }
    $search_param = "%{$surname_search}%";
    $stmt->bind_param("s", $search_param);
    $stmt->execute();
    $result = $stmt->get_result();
    $patients = $result->fetch_all(MYSQLI_ASSOC);

    // Display unique patients by address
    echo '<div>';
    $seen = [];
    foreach ($patients as $patient) {
        $key = $patient['surname'] . '|' . $patient['address'];
        if (!isset($seen[$key])) {
            echo '<div style="cursor: pointer;" onclick="selectPatient(' . $patient['id'] . ', \'' . $patient['name'] . '\', \'' . $patient['surname'] . '\', \'' . $patient['phone'] . '\', \'' . $patient['address'] . '\')">' . $patient['name'] . ' ' . $patient['surname'] . '</div>';
            $seen[$key] = true;
        }
    }
    echo '</div>';
    exit;
}

// Function to insert a new appointment
if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_POST['surname_search'])) {
    $nome = htmlspecialchars($_POST['nome']);
    $cognome = htmlspecialchars($_POST['cognome']);
    $telefono = htmlspecialchars($_POST['telefono']);
    $indirizzo = htmlspecialchars($_POST['indirizzo']);
    $data = htmlspecialchars($_POST['data']); // Campo data
    $ora = htmlspecialchars($_POST['ora']);   // Campo ora
    $zona = isset($_POST['zone_id']) ? htmlspecialchars($_POST['zone_id']) : 0; // Imposta a 0 se non esiste
    $notes = htmlspecialchars($_POST['notes']);

    // Check if an appointment already exists at the same date and time
    $stmt = $conn->prepare("SELECT COUNT(*) AS count FROM cp_appointments WHERE appointment_date = ? AND appointment_time = ?");
    if ($stmt === false) {
        $error = 'Errore nella preparazione della query: ' . $conn->error;
    } else {
        $stmt->bind_param("ss", $data, $ora);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        if ($row['count'] > 0) {
            $error = "Esiste già un appuntamento nella stessa data e ora.";
        } else {
            // Check if the patient already exists
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
                    // If the patient does not exist, create a new one
                    $stmt = $conn->prepare("INSERT INTO cp_patients (name, surname, phone) VALUES (?, ?, ?)");
                    if ($stmt === false) {
                        $error = 'Errore nella preparazione della query: ' . $conn->error;
                    } else {
                        $stmt->bind_param("sss", $nome, $cognome, $telefono);
                        $stmt->execute();
                        $patient_id = $stmt->insert_id;
                    }
                }

                // Insert appointment data into the database
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

                        // Close the connection
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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <style>
        body {
            background-color: #f8f9fa;
        }
        .card {
            margin-top: 20px;
        }
        #appointmentSection {
            display: <?php echo $success ? 'none' : 'block'; ?>;
        }
    </style>
    <script>
        async function loadAPIKey() {
            try {
                const response = await fetch('get_api_key.php');
                const data = await response.json();
                const apiKey = data.api_key;
                const script = document.createElement('script');
                script.src = `https://maps.googleapis.com/maps/api/js?key=${apiKey}&libraries=places&language=it`;
                script.async = true;
                script.onload = initAutocomplete;
                document.head.appendChild(script);
            } catch (error) {
                console.error('Error fetching API key:', error);
            }
        }

        window.addEventListener('load', loadAPIKey);

        function initAutocomplete() {
            const input = document.getElementById('indirizzo');
            const options = {
                types: ['geocode'],
                strictBounds: true,
                bounds: {
                    north: 42.1,
                    south: 40.8,
                    west: 11.5,
                    east: 13.0
                }
            };
            const autocomplete = new google.maps.places.Autocomplete(input, options);
            autocomplete.addListener('place_changed', function () {
                const place = autocomplete.getPlace();
                if (place.geometry) {
                    document.getElementById('latitude').value = place.geometry.location.lat();
                    document.getElementById('longitude').value = place.geometry.location.lng();
                }
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
            document.getElementById('appointmentForm').style.display = 'block';
        }

        function skipResults() {
            document.getElementById('appointmentForm').style.display = 'block';
        }

        document.addEventListener('DOMContentLoaded', function() {
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
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card p-4 shadow-sm">
                    <?php if ($success): ?>
                        <div class="alert alert-success" role="alert">
                            <?php echo $success; ?>
                        </div>
                        <div class="text-center">
                            <button class="btn btn-primary" onclick="window.location.href='insert_appointment.php';">Registra un altro appuntamento</button>
                            <button class="btn btn-secondary" onclick="window.location.href='dashboard.php';">Esci</button>
                        </div>
                    <?php else: ?>
                        <h1 class="text-center">Inserisci un nuovo appuntamento</h1>
                        <?php if ($error): ?>
                            <div class="alert alert-danger" role="alert">
                                <?php echo $error; ?>
                            </div>
                        <?php endif; ?>
                        <div id="appointmentSection">
                            <form method="POST" action="insert_appointment.php">
                                <div class="mb-3">
                                    <label for="surname_search" class="form-label">Cerca Paziente per Cognome:</label>
                                    <input type="text" id="surname_search" name="surname_search" class="form-control" oninput="searchSurname()">
                                </div>
                                <div id="patientsList"></div>
                            </form>
                            <button onclick="skipResults()" class="btn btn-secondary">Salta i risultati e crea nuovo appuntamento</button>
                            <form method="POST" action="insert_appointment.php" id="appointmentForm" style="display:none;">
                                <input type="hidden" name="zone_id" value="0">
                                <div class="mb-3">
                                    <label for="data" class="form-label">Data:</label>
                                    <input type="text" id="data" name="data" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label for="ora" class="form-label">Ora:</label>
                                    <input type="text" id="ora" name="ora" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label for="nome" class="form-label">Nome:</label>
                                    <input type="text" id="nome" name="nome" class="form-control" value="<?php echo $nome; ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label for="cognome" class="form-label">Cognome:</label>
                                    <input type="text" id="cognome" name="cognome" class="form-control" value="<?php echo $cognome; ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label for="telefono" class="form-label">Telefono:</label>
                                    <input type="text" id="telefono" name="telefono" class="form-control" value="<?php echo $telefono; ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label for="indirizzo" class="form-label">Indirizzo:</label>
                                    <input type="text" id="indirizzo" name="indirizzo" class="form-control pac-target-input" placeholder="Inserisci una posizione" autocomplete="off" required>
                                    <input type="hidden" id="latitude" name="latitude">
                                    <input type="hidden" id="longitude" name="longitude">
                                </div>
                                <div class="mb-3">
                                    <label for="notes" class="form-label">Note:</label>
                                    <textarea id="notes" name="notes" class="form-control"></textarea>
                                </div>
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary">Inserisci Appuntamento</button>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
