<?php
// Start output buffering to prevent any output before headers are sent
ob_start();
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit(); // Ensure the script stops executing after the redirect
}

include 'db.php';
include 'utils_appointment.php';
// Function to get all appointments with patient and zone information
function getAppointments($filter = [], $search = '', $phone_search = '', $page = 1, $results_per_page = 15) {
    global $conn;
    $conditions = [];
    
    // Aggiungiamo la condizione per mostrare solo gli appuntamenti dalla data corrente in poi
    $today = date('Y-m-d');
    $conditions[] = "a.appointment_date >= '$today'";
    
    if (!empty($filter['date'])) {
        $conditions[] = "a.appointment_date = '" . mysqli_real_escape_string($conn, $filter['date']) . "'";
    }
    if (!empty($filter['zone'])) {
        $conditions[] = "z.name = '" . mysqli_real_escape_string($conn, $filter['zone']) . "'";
    }
    if (!empty($search)) {
        $conditions[] = "(p.name LIKE '%" . mysqli_real_escape_string($conn, $search) . "%' OR p.surname LIKE '%" . mysqli_real_escape_string($conn, $search) . "%')";
    }
    if (!empty($phone_search)) {
        $conditions[] = "p.phone LIKE '%" . mysqli_real_escape_string($conn, $phone_search) . "%'";
    }
    $offset = ($page - 1) * $results_per_page;
    $sql = "SELECT a.id, p.name, p.surname, p.phone, a.notes, a.appointment_date, a.appointment_time, a.address, COALESCE(z.name, 'N/A') as zone
            FROM cp_appointments a
            JOIN cp_patients p ON a.patient_id = p.id
            LEFT JOIN cp_zones z ON a.zone_id = z.id";
    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(' AND ', $conditions);
    }
    $sql .= " ORDER BY a.appointment_date ASC, a.appointment_time ASC LIMIT $offset, $results_per_page";
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        die('Error: ' . mysqli_error($conn));
    }
    $appointments = mysqli_fetch_all($result, MYSQLI_ASSOC);
    return $appointments;
}

// Function to get total number of appointments for pagination
function getTotalAppointments($filter = [], $search = '', $phone_search = '') {
    global $conn;
    $conditions = [];
    
    // Aggiungiamo la condizione per mostrare solo gli appuntamenti dalla data corrente in poi
    $today = date('Y-m-d');
    $conditions[] = "a.appointment_date >= '$today'";
    
    if (!empty($filter['date'])) {
        $conditions[] = "a.appointment_date = '" . mysqli_real_escape_string($conn, $filter['date']) . "'";
    }
    if (!empty($filter['zone'])) {
        $conditions[] = "z.name = '" . mysqli_real_escape_string($conn, $filter['zone']) . "'";
    }
    if (!empty($search)) {
        $conditions[] = "(p.name LIKE '%" . mysqli_real_escape_string($conn, $search) . "%' OR p.surname LIKE '%" . mysqli_real_escape_string($conn, $search) . "%')";
    }
    if (!empty($phone_search)) {
        $conditions[] = "p.phone LIKE '%" . mysqli_real_escape_string($conn, $phone_search) . "%'";
    }
    $sql = "SELECT COUNT(*) as total
            FROM cp_appointments a
            JOIN cp_patients p ON a.patient_id = p.id
            LEFT JOIN cp_zones z ON a.zone_id = z.id";
    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(' AND ', $conditions);
    }
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        die('Error: ' . mysqli_error($conn));
    }
    $row = mysqli_fetch_assoc($result);
    return $row['total'];
}

// Function to get distinct zones
function getZones() {
    global $conn;
    $sql = "SELECT DISTINCT z.name FROM cp_appointments a LEFT JOIN cp_zones z ON a.zone_id = z.id";
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        die('Error: ' . mysqli_error($conn));
    }
    $zones = mysqli_fetch_all($result, MYSQLI_ASSOC);
    return array_column($zones, 'name');
}

// Function to update an appointment
if (isset($_POST['update'])) {
    try {
        $id = $_POST['appointment_id'];
        $name = $_POST['name'];
        $surname = $_POST['surname'];
        $phone = $_POST['phone'];
        $notes = $_POST['notes'];
        $appointment_date = $_POST['appointment_date'];
        $appointment_time = $_POST['appointment_time'];
        $address = $_POST['address'];
        
        // Recupera la zona_id dell'appuntamento
        $stmt = $conn->prepare("SELECT zone_id FROM cp_appointments WHERE id = ?");
        if ($stmt === false) {
            throw new Exception('Errore nella preparazione della query: ' . $conn->error);
        }
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $zone_data = $result->fetch_assoc();
        $zone_id = $zone_data['zone_id'];
        $stmt->close();
        
        // Calcola l'orario di fine (1 ora dopo l'inizio)
        $end_time = date('H:i:s', strtotime($appointment_time . ' +1 hour'));
        
        // Verifica se il nuovo orario è disponibile rispetto agli unavailable slots
        $availability = isSlotAvailable($appointment_date, $appointment_time, $end_time, $zone_id);
        if (!$availability['available']) {
            $_SESSION['error_message'] = "Impossibile aggiornare l'appuntamento: " . $availability['reason'];
            header('Location: manage_appointments.php');
            exit();
        }
                // Continua con l'aggiornamento dell'appuntamento
        $stmt = $conn->prepare("UPDATE cp_appointments a 
                               JOIN cp_patients p ON a.patient_id = p.id 
                               SET p.name=?, p.surname=?, p.phone=?, a.notes=?, 
                                   a.appointment_date=?, a.appointment_time=?, a.address=? 
                               WHERE a.id = ?");
        if ($stmt === false) {
            throw new Exception('Errore nella preparazione della query: ' . $conn->error);
        }
        
        $stmt->bind_param("sssssssi", $name, $surname, $phone, $notes, $appointment_date, $appointment_time, $address, $id);
        $stmt->execute();
        $stmt->close();
        
        // Salva un messaggio di successo in una sessione
        $_SESSION['success_message'] = "Appuntamento aggiornato con successo";
        header('Location: manage_appointments.php');
        exit();
    } catch (Exception $e) {
        // Log dell'errore
        error_log("Errore nell'aggiornamento dell'appuntamento: " . $e->getMessage());
        $_SESSION['error_message'] = "Si è verificato un errore: " . $e->getMessage();
        header('Location: manage_appointments.php');
        exit();
    }
}

// Function to delete an appointment
if (isset($_POST['delete_confirm'])) {
    $id = $_POST['appointment_id'];
    $sql = "DELETE FROM cp_appointments WHERE id = $id";
    mysqli_query($conn, $sql);
    header('Location: manage_appointments.php');
    exit(); // Ensure the script stops executing after the redirect
}

// Definisci queste variabili prima di usarle
$search = isset($_GET['search']) ? $_GET['search'] : '';
$phone_search = isset($_GET['phone_search']) ? $_GET['phone_search'] : '';
$results_per_page = 15;

// Gestione del parametro highlight_appointment
if (isset($_GET['highlight_appointment'])) {
    $highlight_id = (int)$_GET['highlight_appointment'];
    
    // Controlla se l'appuntamento esiste
    $check_sql = "SELECT a.*, p.name, p.surname FROM cp_appointments a 
                  JOIN cp_patients p ON a.patient_id = p.id 
                  WHERE a.id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $highlight_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($row = $check_result->fetch_assoc()) {
    // Verifichiamo che l'appuntamento sia un appuntamento futuro
    $today = date('Y-m-d');
    if ($row['appointment_date'] < $today) {
        $_SESSION['info_message'] = "L'appuntamento per " . $row['name'] . " " . $row['surname'] . " del " . date('d/m/Y', strtotime($row['appointment_date'])) . " è una data passata.";
        
        // Riporta a una vista senza filtri, ma con solo appuntamenti futuri
        $filter = [
            'date' => '',
            'zone' => isset($_GET['zone']) ? $_GET['zone'] : '',
        ];
        $page = 1;
        $total_appointments = getTotalAppointments($filter, $search);
        $total_pages = ceil($total_appointments / $results_per_page);
        $appointments = getAppointments($filter, $search, $page, $results_per_page);
        $showTable = !empty($appointments);
    } else {
        // Appuntamento trovato e futuro, impostiamo il filtro per data e forziamo la pagina a 1
        $filter = [
            'date' => $row['appointment_date'],
            'zone' => isset($_GET['zone']) ? $_GET['zone'] : '',
        ];
        $page = 1; // Per assicurarsi di iniziare dalla prima pagina con questo filtro
        
        // Aggiorniamo i risultati con il nuovo filtro
        $total_appointments = getTotalAppointments($filter, $search);
        $total_pages = ceil($total_appointments / $results_per_page);
        $appointments = getAppointments($filter, $search, $page, $results_per_page);
        $showTable = !empty($appointments);
        
        $_SESSION['info_message'] = "Mostrando l'appuntamento richiesto per " . $row['name'] . " " . $row['surname'] . " del " . date('d/m/Y', strtotime($row['appointment_date']));
    }
} else {
    $_SESSION['error_message'] = "Appuntamento ID $highlight_id non trovato nel database.";
}
} else {
    // Se non c'è un highlight_appointment, impostiamo i filtri standard
    $filter = [
        'date' => isset($_GET['date']) ? $_GET['date'] : '',
        'zone' => isset($_GET['zone']) ? $_GET['zone'] : '',
    ];
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $total_appointments = getTotalAppointments($filter, $search, $phone_search);
    $total_pages = ceil($total_appointments / $results_per_page);
    $appointments = getAppointments($filter, $search, $phone_search, $page, $results_per_page);
    $showTable = !empty($appointments);
}

$zones = getZones(); // Questo è ancora necessario per il menu a discesa delle zone
?>

<!DOCTYPE html>
<html>
<head>
    <title>Gestione Appuntamenti</title>
         
        
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/purecss@3.0.0/build/pure-min.css" integrity="sha384-X38yfunGUhNzHpBaEBsWLO+A0HDYOQi8ufWDkZ0k9e0eXz/tH3II7uKZ9msv++Ls" crossorigin="anonymous">
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        .modifica-btn {
            background-color: green;
            color: white;
        }
        .cancella-btn {
            background-color: red;
            color: white;
        }
        .confirm-btn {
            background-color: darkred;
            color: white;
        }
        .chiudi-btn {
            background-color: grey;
            color: white;
        }
        .hidden {
            display: none;
        }
        
        .button-primary {
            background-color: #0078e7;
            color: white;
            margin-right: 5px;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 20px;
        }
        .inline-edit-form {
            display: flex;
            flex-flow: row wrap;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }

        .edit-column {
            display: inline-block;
            margin: 5px;
            text-align: center;
            flex: 1;
            min-width: 100px;
        }

        .edit-column label {
            display: block;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .edit-column input {
            width: 100%;
        }

        .notes-column {
            flex: 2;
            max-width: 200px;
            width: 200px;
        }

        .notes-column textarea {
            width: 100%;
            resize: vertical;
            min-height: 60px;
        }

        .edit-buttons {
            width: 100%;
            text-align: center;
            margin-top: 10px;
        }
        .notes-cell {
            max-width: 200px;
            white-space: normal;
            word-wrap: break-word;
            overflow: hidden;
        }
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid transparent;
            border-radius: 4px;
            width: 100%;
            display: block;
            text-align: center;
        }
        .alert-danger {
            color: #721c24;
            background-color: #f8d7da;
            border-color: #f5c6cb;
        }
        .alert-success {
            color: #155724;
            background-color: #d4edda;
            border-color: #c3e6cb;
        }
        /* AGGIUNGI QUESTO PER IL MESSAGGIO INFO */
        .alert-info {
            color: #0c5460;
            background-color: #d1ecf1;
            border-color: #bee5eb;
        }
        /* Per schermi più piccoli */
        @media (max-width: 992px) {
            .notes-column {
                flex: 1 1 100%;
                max-width: 100%;
            }
        }
        
        .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(0,0,0,.3);
            border-radius: 50%;
            border-top-color: #0078e7;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    
    <script>
        // Funzione debounce per limitare le chiamate durante la digitazione
        function debounce(func, wait) {
            let timeout;
            return function() {
                const context = this;
                const args = arguments;
                clearTimeout(timeout);
                timeout = setTimeout(() => func.apply(context, args), wait);
            };
        }
        
        // Funzioni globali per gestire azioni sugli appuntamenti
        function showActions(id) {
            const actionRow = document.getElementById(`action-${id}`);
            const editForm = document.getElementById(`edit-form-${id}`);
            const displayStatus = actionRow.style.display === 'none' || actionRow.style.display === '';
    
            // Hide all other action rows
            document.querySelectorAll('.action-row').forEach(row => row.style.display = 'none');
    
            // Hide all other edit forms
            document.querySelectorAll('.edit-form').forEach(form => form.style.display = 'none');
    
            if (displayStatus) {
                actionRow.style.display = 'table-row';
                editForm.style.display = 'inline';
            } else {
                actionRow.style.display = 'none';
                editForm.style.display = 'none';
            }
        }
    
        function confirmDelete(appointment) {
            if (confirm(`Sei sicuro di voler cancellare l'appuntamento in zona ${appointment.zone} ${appointment.address} con ${appointment.name} ${appointment.surname} ${appointment.phone} ${appointment.date} ${appointment.time}?`)) {
                document.getElementById(`confirm-delete-${appointment.id}`).style.display = 'inline';
                document.getElementById(`delete-btn-${appointment.id}`).style.display = 'none';
            }
        }
    
        function hideActions(id) {
            const actionRow = document.getElementById(`action-${id}`);
            const editForm = document.getElementById(`edit-form-${id}`);
            actionRow.style.display = 'none';
            editForm.style.display = 'none';
        }
    
        // Funzione per riaplicare gli event listener dopo un filtro
        function reapplyEventHandlers() {
            document.querySelectorAll(".flatpickr").forEach(elem => {
                flatpickr(elem, {
                    dateFormat: "Y-m-d",
                    allowInput: true
                });
            });
    
            document.querySelectorAll(".flatpickr-time").forEach(elem => {
                flatpickr(elem, {
                    enableTime: true,
                    noCalendar: true,
                    dateFormat: "H:i",
                    time_24hr: true,
                    allowInput: true
                });
            });
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            flatpickr("#date", {
                dateFormat: "Y-m-d",
                allowInput: true
            });
            flatpickr(".flatpickr", {
                dateFormat: "Y-m-d",
                allowInput: true
            });
            flatpickr(".flatpickr-time", {
                enableTime: true,
                noCalendar: true,
                dateFormat: "H:i",
                time_24hr: true,
                allowInput: true
            });
            
            // Utilizziamo debounce per i filtri in tempo reale
           // document.getElementById('date').addEventListener('change', debounce(filterAppointments, 500));
            //document.getElementById('zone').addEventListener('input', debounce(filterAppointments, 500));
           // document.getElementById('search').addEventListener('input', debounce(filterAppointments, 500));
           // document.getElementById('phone_search').addEventListener('input', debounce(filterAppointments, 500));
            
            // Aggiungere pulsante di ricerca esplicito
            document.getElementById('search-button').addEventListener('click', filterAppointments);
            document.getElementById('clear-filters').addEventListener('click', clearFilters);
        });

        // Indichiamo visivamente che stiamo cercando
        function showLoadingIndicator() {
            document.getElementById('loading-indicator').style.display = 'inline-block';
        }

        function hideLoadingIndicator() {
            document.getElementById('loading-indicator').style.display = 'none';
        }

        function filterAppointments() {
            showLoadingIndicator();
            
            const date = document.getElementById('date').value;
            const zone = document.getElementById('zone').value;
            const search = document.getElementById('search').value;
            const phone_search = document.getElementById('phone_search').value;
            
            const xhr = new XMLHttpRequest();
            xhr.open('GET', `manage_appointments.php?date=${encodeURIComponent(date)}&zone=${encodeURIComponent(zone)}&search=${encodeURIComponent(search)}&phone_search=${encodeURIComponent(phone_search)}`, true);
            
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    hideLoadingIndicator();
                    
                    if (xhr.status === 200) {
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(xhr.responseText, 'text/html');
                        const newTable = doc.querySelector('table');
                        const tableElement = document.querySelector('table');
                        
                        if (newTable && newTable.querySelector('tbody').children.length > 0) {
                            // Ottimizzazione: aggiorniamo solo tbody invece dell'intera tabella
                            const newTbody = newTable.querySelector('tbody');
                            const tbody = tableElement.querySelector('tbody');
                            tbody.innerHTML = newTbody.innerHTML;
                            
                            tableElement.classList.remove('hidden');
                            document.getElementById('no-appointments-message').classList.add('hidden');
                            
                            // Riapplica i gestori di eventi dopo l'aggiornamento della tabella
                            reapplyEventHandlers();
                        } else {
                            tableElement.classList.add('hidden');
                            document.getElementById('no-appointments-message').classList.remove('hidden');
                        }
                    }
                }
            };
            xhr.send();
        }
        
        function clearFilters() {
            document.getElementById('date').value = '';
            document.getElementById('zone').value = '';
            document.getElementById('search').value = '';
            document.getElementById('phone_search').value = '';
            filterAppointments();
        }
    </script>
    
   

    
</head>
<body>
    <?php include 'menu.php'; ?>

<div class="pure-g aria">
    <h2 class="centrato">Gestione Appuntamenti</h2>
</div>

<!-- Div dedicato ai messaggi di errore/successo -->
<div class="container">
<div class="pure-g aria">
    <div class="centrato" style="width: 100%;">
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger">
                <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success">
                <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['info_message'])): ?>
            <div class="alert alert-info">
                <?php echo $_SESSION['info_message']; unset($_SESSION['info_message']); ?>
            </div>
        <?php endif; ?>
    </div>
</div>
</div>

<!-- Div separato per i filtri -->
<div class="pure-g aria">
    <form onsubmit="event.preventDefault(); filterAppointments();" class="pure-form centrato aria">
        <label for="date">Filtra per Data:</label>
        <input type="text" id="date" name="date" class="flatpickr" value="<?php echo htmlspecialchars($filter['date']); ?>">
        <label for="zone">Filtra per Zona:</label>
        <select id="zone" name="zone">
            <option value="">Seleziona Zona</option>
            <?php foreach ($zones as $zone) { ?>
                <option value="<?php echo htmlspecialchars($zone); ?>"<?php echo ($filter['zone'] === $zone) ? ' selected' : ''; ?>><?php echo htmlspecialchars($zone); ?></option>
            <?php } ?>
        </select>
        <label for="search">Cerca per Nome/Cognome:</label>
        <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>">
        <label for="phone_search">Cerca per Telefono:</label>
        <input type="text" id="phone_search" name="phone_search" value="<?php echo htmlspecialchars($phone_search); ?>">
        <button type="button" id="search-button" class="pure-button button-primary">Cerca</button>
        <button type="button" id="clear-filters" class="pure-button button-small">Cancella Filtri</button>
        <span id="loading-indicator" style="display: none; margin-left: 10px;">
            <span class="spinner"></span> Caricamento...
        </span>
    </form>
    
    <p id="no-appointments-message" class="<?php echo $showTable ? 'hidden' : ''; ?> centrato aria">Non ci sono appuntamenti</p>
</div>
    
   
    
    
    <div class="pure-g aria">
        <table border="0" class="<?php echo $showTable ? '' : 'hidden'; ?> pure-table pure-table-bordered centrato aria">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Cognome</th>
                    <th>Telefono</th>
                    <th>Note</th>
                    <th>Data Appuntamento</th>
                    <th>Ora Appuntamento</th>
                    <th>Indirizzo</th>
                    <th>Zona</th>
                    <th>Azioni</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($appointments as $appointment) { ?>
                <tr>
                    
                    
                    <td><?php echo htmlspecialchars($appointment['name']); ?></td>
                    <td><?php echo htmlspecialchars($appointment['surname']); ?></td>
                    <td><?php echo htmlspecialchars($appointment['phone']); ?></td>
                    <td class="notes-cell"><?php echo htmlspecialchars($appointment['notes']); ?></td>
                    <td><?php echo date('d/m/Y', strtotime($appointment['appointment_date'])); ?></td>
                    <td><?php echo htmlspecialchars($appointment['appointment_time']); ?></td>
                    <td><?php echo htmlspecialchars($appointment['address']); ?></td>
                    <td><?php echo htmlspecialchars($appointment['zone']); ?></td>
                    <td>
                        <button class="modifica-btn pure-button button-small button-green" onclick="showActions(<?php echo $appointment['id']; ?>)">Modifica</button>
                        <button class="cancella-btn pure-button button-small button-red" id="delete-btn-<?php echo $appointment['id']; ?>" onclick="confirmDelete(<?php echo htmlspecialchars(json_encode(['id' => $appointment['id'], 'name' => $appointment['name'], 'surname' => $appointment['surname'], 'phone' => $appointment['phone'], 'zone' => $appointment['zone'], 'date' => date('d/m/Y', strtotime($appointment['appointment_date'])), 'time' => $appointment['appointment_time'], 'address' => $appointment['address']])); ?>)">Cancella</button>
                        <form method="post" action="manage_appointments.php" style="display:inline;">
                            <input type="hidden" name="appointment_id" value="<?php echo $appointment['id']; ?>">
                            <input type="submit" name="delete_confirm" value="Conferma cancella" class="confirm-btn pure-button button-small button-red" id="confirm-delete-<?php echo $appointment['id']; ?>" style="display:none;">
                        </form>
                    </td>
                </tr>
<tr id="action-<?php echo $appointment['id']; ?>" class="action-row" style="display:none;">
    <td colspan="9">
        <form method="post" action="manage_appointments.php" id="edit-form-<?php echo $appointment['id']; ?>" class="edit-form pure-form" style="display:inline;">
            <input type="hidden" name="appointment_id" value="<?php echo $appointment['id']; ?>">
            <div class="inline-edit-form">
                <div class="edit-column">
                    <label for="name-<?php echo $appointment['id']; ?>">Nome</label>
                    <input type="text" id="name-<?php echo $appointment['id']; ?>" name="name" value="<?php echo htmlspecialchars($appointment['name']); ?>" required>
                </div>
                <div class="edit-column">
                    <label for="surname-<?php echo $appointment['id']; ?>">Cognome</label>
                    <input type="text" id="surname-<?php echo $appointment['id']; ?>" name="surname" value="<?php echo htmlspecialchars($appointment['surname']); ?>" required>
                </div>
                                <div class="edit-column">
                    <label for="phone-<?php echo $appointment['id']; ?>">Telefono</label>
                    <input type="text" id="phone-<?php echo $appointment['id']; ?>" name="phone" value="<?php echo htmlspecialchars($appointment['phone']); ?>" required>
                </div>
                <div class="edit-column notes-column">
                    <label for="notes-<?php echo $appointment['id']; ?>">Note</label>
                    <textarea id="notes-<?php echo $appointment['id']; ?>" name="notes" rows="3"><?php echo htmlspecialchars($appointment['notes']); ?></textarea>
                </div>
                <div class="edit-column">
                    <label for="appointment_date-<?php echo $appointment['id']; ?>">Data</label>
                    <input type="date" id="appointment_date-<?php echo $appointment['id']; ?>" name="appointment_date" value="<?php echo htmlspecialchars($appointment['appointment_date']); ?>" required class="flatpickr">
                </div>
                <div class="edit-column">
                    <label for="appointment_time-<?php echo $appointment['id']; ?>">Ora</label>
                    <input type="time" id="appointment_time-<?php echo $appointment['id']; ?>" name="appointment_time" value="<?php echo htmlspecialchars($appointment['appointment_time']); ?>" required class="flatpickr-time">
                </div>
                <div class="edit-column">
                    <label for="address-<?php echo $appointment['id']; ?>">Indirizzo</label>
                    <input type="text" id="address-<?php echo $appointment['id']; ?>" name="address" value="<?php echo htmlspecialchars($appointment['address']); ?>" required>
                </div>
            </div>
            <div class="edit-buttons">
                <input type="submit" name="update" value="Conferma Modifica" class="modifica-btn pure-button button-small button-green">
                <button type="button" class="chiudi-btn pure-button button-small" onclick="hideActions(<?php echo $appointment['id']; ?>)">Chiudi</button>
            </div>
        </form>
    </td>
</tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
    <div class="pure-g aria centrato pagination">
        <?php if ($page > 1) { ?>
            <a href="manage_appointments.php?page=<?php echo $page - 1; ?>&date=<?php echo urlencode($filter['date']); ?>&zone=<?php echo urlencode($filter['zone']); ?>&search=<?php echo urlencode($search); ?>&phone_search=<?php echo urlencode($phone_search); ?>" class="pure-button">Pagina Precedente</a>
        <?php } ?>
        <?php if ($page < $total_pages) { ?>
            <a href="manage_appointments.php?page=<?php echo $page + 1; ?>&date=<?php echo urlencode($filter['date']); ?>&zone=<?php echo urlencode($filter['zone']); ?>&search=<?php echo urlencode($search); ?>&phone_search=<?php echo urlencode($phone_search); ?>" class="pure-button">Pagina Successiva</a>
        <?php } ?>
    </div>
    
    
<?php if (isset($_GET['highlight_appointment'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Recupera l'ID dell'appuntamento da evidenziare
    const appointmentId = <?php echo (int)$_GET['highlight_appointment']; ?>;
    
    console.log('Cercando appuntamento ID:', appointmentId);
    
    // Usiamo un timeout per assicurarci che tutti gli elementi siano caricati
    setTimeout(function() {
        // Trova il pulsante di modifica con l'ID dell'appuntamento
        const modifyButtons = document.querySelectorAll('button.modifica-btn');
        let targetButton = null;
        
        modifyButtons.forEach(button => {
            const onclickAttr = button.getAttribute('onclick');
            if (onclickAttr && onclickAttr.includes(`showActions(${appointmentId})`)) {
                targetButton = button;
            }
        });
        
        if (targetButton) {
            // Trova la riga che contiene il pulsante
            const row = targetButton.closest('tr');
            
            // Scorri fino alla riga
            row.scrollIntoView({ behavior: 'smooth', block: 'center' });
            
            // Evidenzia temporaneamente la riga
            const originalColor = row.style.backgroundColor;
            row.style.backgroundColor = '#fff3cd';
            
            setTimeout(() => {
                // Simula il click sul pulsante modifica
                targetButton.click();
                
                // Ripristina il colore originale dopo un po'
                setTimeout(() => {
                    row.style.backgroundColor = originalColor;
                }, 5000);
            }, 1000);
            
            console.log("Appuntamento trovato e evidenziato");
        } else {
            console.error("Appuntamento ID", appointmentId, "non trovato nella tabella");
        }
    }, 500);
});
</script>
<?php endif; ?>
    

</body>
</html>

<?php
// End output buffering and flush the output
ob_end_flush();
?>
