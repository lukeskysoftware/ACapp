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
// **NUOVA FUNZIONE: Gestire disdetta appuntamento**
if (isset($_POST['cancel_appointment'])) {
    $id = $_POST['appointment_id'];
    
    try {
        // Aggiorna lo stato a 'disdetto' invece di eliminare
        $stmt = $conn->prepare("UPDATE cp_appointments SET status = 'disdetto' WHERE id = ?");
        if ($stmt === false) {
            throw new Exception('Errore nella preparazione della query: ' . $conn->error);
        }
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        
        $_SESSION['success_message'] = "Appuntamento disdetto con successo. Può essere ripristinato dalla ricerca pazienti.";
        header('Location: manage_appointments.php');
        exit();
    } catch (Exception $e) {
        error_log("Errore nella disdetta dell'appuntamento: " . $e->getMessage());
        $_SESSION['error_message'] = "Si è verificato un errore nella disdetta: " . $e->getMessage();
        header('Location: manage_appointments.php');
        exit();
    }
}

// **NUOVA FUNZIONE: Ripristinare appuntamento disdetto**
if (isset($_POST['restore_appointment'])) {
    $id = $_POST['appointment_id'];
    
    try {
        // Verifica che lo slot sia ancora disponibile prima di ripristinare
        $check_stmt = $conn->prepare("SELECT appointment_date, appointment_time, zone_id FROM cp_appointments WHERE id = ?");
        $check_stmt->bind_param("i", $id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        $appointment_data = $result->fetch_assoc();
        $check_stmt->close();
        
        if ($appointment_data) {
            // Verifica conflitti con altri appuntamenti attivi
            $conflict_stmt = $conn->prepare("SELECT COUNT(*) as count FROM cp_appointments WHERE appointment_date = ? AND appointment_time = ? AND status = 'attivo' AND id != ?");
            $conflict_stmt->bind_param("ssi", $appointment_data['appointment_date'], $appointment_data['appointment_time'], $id);
            $conflict_stmt->execute();
            $conflict_result = $conflict_stmt->get_result();
            $conflict_row = $conflict_result->fetch_assoc();
            $conflict_stmt->close();
            
            if ($conflict_row['count'] > 0) {
                $_SESSION['error_message'] = "Impossibile ripristinare: lo slot è già occupato da un altro appuntamento.";
            } else {
                // Verifica unavailable slots
                $end_time = date('H:i:s', strtotime($appointment_data['appointment_time'] . ' +1 hour'));
                $availability = isSlotAvailable($appointment_data['appointment_date'], $appointment_data['appointment_time'], $end_time, $appointment_data['zone_id']);
                
                if (!$availability['available']) {
                    $_SESSION['error_message'] = "Impossibile ripristinare: " . $availability['reason'];
                } else {
                    // Ripristina lo stato a 'attivo'
                    $restore_stmt = $conn->prepare("UPDATE cp_appointments SET status = 'attivo' WHERE id = ?");
                    $restore_stmt->bind_param("i", $id);
                    $restore_stmt->execute();
                    $restore_stmt->close();
                    
                    $_SESSION['success_message'] = "Appuntamento ripristinato con successo.";
                }
            }
        } else {
            $_SESSION['error_message'] = "Appuntamento non trovato.";
        }
        
        header('Location: manage_appointments.php');
        exit();
    } catch (Exception $e) {
        error_log("Errore nel ripristino dell'appuntamento: " . $e->getMessage());
        $_SESSION['error_message'] = "Si è verificato un errore nel ripristino: " . $e->getMessage();
        header('Location: manage_appointments.php');
        exit();
    }
}

// Function to get all appointments with patient and zone information
function getAppointments($filter = [], $search = '', $phone_search = '', $page = 1, $results_per_page = 15, $address_search = '') {
    global $conn;
    $conditions = [];
    $today = date('Y-m-d');
    
    // **NUOVO: Logica filtro corretta**
$conditions[] = "a.appointment_date >= '$today'"; // Sempre solo futuri

if ($filter['status'] === 'attivo') {
    // Solo appuntamenti attivi
    $conditions[] = "(a.status IS NULL OR a.status = 'attivo')";
}
// Se status = 'tutti' o vuoto, mostra tutti (attivi + disdetti)

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
    if (!empty($address_search)) {
        $conditions[] = "a.address LIKE '%" . mysqli_real_escape_string($conn, $address_search) . "%'";
    }
    $offset = ($page - 1) * $results_per_page;
    
    // **NUOVO: Aggiunta campo status nella SELECT**
    $sql = "SELECT a.id, p.name, p.surname, p.phone, a.notes, a.appointment_date, a.appointment_time, a.address, a.status, COALESCE(z.name, 'N/A') as zone
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
function getTotalAppointments($filter = [], $search = '', $phone_search = '', $address_search = '') {
    global $conn;
    $conditions = [];
    $today = date('Y-m-d');
    
    // **NUOVO: Stessa logica di filtro per il count**
    if (empty($filter['status']) || $filter['status'] === 'attivo') {
        $conditions[] = "a.appointment_date >= '$today'";
        $conditions[] = "(a.status IS NULL OR a.status = 'attivo')";
    } elseif ($filter['status'] === 'disdetto') {
        $conditions[] = "a.status = 'disdetto'";
    } elseif ($filter['status'] === 'tutti') {
        $conditions[] = "a.appointment_date >= '$today'";
    }
    
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
    if (!empty($address_search)) {
        $conditions[] = "a.address LIKE '%" . mysqli_real_escape_string($conn, $address_search) . "%'";
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

// Function to delete an appointment (MANTENUTA per compatibilità)
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
$address_search = isset($_GET['address_search']) ? $_GET['address_search'] : '';

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
            'status' => 'attivo' // **NUOVO: Aggiunto filtro status**
        ];
        $page = 1;
        $total_appointments = getTotalAppointments($filter, $search, $phone_search, $address_search);
        $total_pages = ceil($total_appointments / $results_per_page);
        $appointments = getAppointments($filter, $search, $phone_search, $page, $results_per_page, $address_search);

        $showTable = !empty($appointments);
    } else {
        // Appuntamento trovato e futuro, impostiamo il filtro per data e forziamo la pagina a 1
        $filter = [
            'date' => $row['appointment_date'],
            'zone' => isset($_GET['zone']) ? $_GET['zone'] : '',
            'status' => 'attivo' // **NUOVO: Aggiunto filtro status**
        ];
        $page = 1; // Per assicurarsi di iniziare dalla prima pagina con questo filtro
        
        // Aggiorniamo i risultati con il nuovo filtro
        $total_appointments = getTotalAppointments($filter, $search, $phone_search, $address_search);
        $total_pages = ceil($total_appointments / $results_per_page);
        $appointments = getAppointments($filter, $search, $phone_search, $page, $results_per_page, $address_search);
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
    $total_appointments = getTotalAppointments($filter, $search, $phone_search, $address_search);
    $total_pages = ceil($total_appointments / $results_per_page);
    $appointments = getAppointments($filter, $search, $phone_search, $page, $results_per_page, $address_search);
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
        /* **NUOVI STILI PER DISDETTA** */
.disdici-btn {
    background-color: #ff9500;
    color: white;
    margin-left: 5px;
}
.ripristina-btn {
    background-color: #28a745;
    color: white;
    margin-left: 5px;
}
.status-disdetto {
    background-color: #fff3cd;
    border-left: 4px solid #ffc107;
}
.badge-disdetto {
    background-color: #ffc107;
    color: #212529;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 0.8em;
    font-weight: bold;
}
.riprendi-btns {
    margin-top: 5px;
}
.riprendi-btns a {
    margin-right: 5px;
    padding: 3px 8px;
    font-size: 0.8em;
    text-decoration: none;
    border-radius: 3px;
}
.btn-smart {
    background-color: #17a2b8;
    color: white;
}
.btn-direct {
    background-color: #6f42c1;
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
        
        
        @media (max-width: 1200px) {
  #filtro-riga-unica { min-width: 700px; }
  #filtro-riga-unica input, #filtro-riga-unica select { width: 80px !important; }
}
@media (max-width: 900px) {
  #filtro-riga-unica { min-width: 500px; font-size: 12px; }
  #filtro-riga-unica input, #filtro-riga-unica select { width: 60px !important; }
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
        
        // **NUOVA FUNZIONE: Conferma disdetta**
function confirmCancel(appointment) {
    if (confirm(`Sei sicuro di voler disdire l'appuntamento con ${appointment.name} ${appointment.surname} del ${appointment.appointment_date} alle ${appointment.appointment_time}?\n\nL'appuntamento potrà essere ripristinato dalla ricerca pazienti.`)) {
        document.getElementById(`confirm-cancel-${appointment.id}`).style.display = 'inline';
        document.getElementById(`cancel-btn-${appointment.id}`).style.display = 'none';
    }
}

// **NUOVA FUNZIONE: Conferma ripristino**
function confirmRestore(appointment) {
    if (confirm(`Vuoi ripristinare l'appuntamento con ${appointment.name} ${appointment.surname} del ${appointment.appointment_date} alle ${appointment.appointment_time}?`)) {
        document.getElementById(`restore-form-${appointment.id}`).submit();
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
    const address_search = document.getElementById('address_search').value;
    // **NUOVA: Gestione filtro status**
    const status = document.getElementById('status').value;

    const xhr = new XMLHttpRequest();
    xhr.open('GET', `manage_appointments.php?date=${encodeURIComponent(date)}&zone=${encodeURIComponent(zone)}&search=${encodeURIComponent(search)}&phone_search=${encodeURIComponent(phone_search)}&address_search=${encodeURIComponent(address_search)}&status=${encodeURIComponent(status)}`, true);

    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            hideLoadingIndicator();
            if (xhr.status === 200) {
                const parser = new DOMParser();
                const doc = parser.parseFromString(xhr.responseText, 'text/html');
                const newTable = doc.querySelector('table');
                const tableElement = document.querySelector('table');
                if (newTable && newTable.querySelector('tbody').children.length > 0) {
                    const newTbody = newTable.querySelector('tbody');
                    const tbody = tableElement.querySelector('tbody');
                    tbody.innerHTML = newTbody.innerHTML;
                    tableElement.classList.remove('hidden');
                    document.getElementById('no-appointments-message').classList.add('hidden');
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
    document.getElementById('address_search').value = '';
    // Reset filtro status a default 'tutti'
document.getElementById('status').value = 'tutti';
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
    
<form onsubmit="event.preventDefault(); filterAppointments();" class="pure-form" style="margin: 0 auto;">
    <div 
        id="filtro-riga-unica"
        style="
            display: flex;
            flex-wrap: nowrap;
            align-items: center;
            gap: 14px;
            margin-bottom: 15px;
            background-color: #f9f9f9;
            padding: 15px 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            overflow-x: auto;
            width: 100%;
            box-sizing: border-box;
            min-width: 1000px;
        ">

        <div style="display: flex; align-items: center; min-width: 170px;">
            <label for="date" style="margin-right: 5px; font-weight: bold; white-space: nowrap;">Data:</label>
            <input type="text" id="date" name="date" class="flatpickr" value="<?php echo htmlspecialchars($filter['date']); ?>" style="width: 110px;">
        </div>

        <!-- **NUOVO FILTRO STATUS** -->
<div style="display: flex; align-items: center; min-width: 150px;">
    <label for="status" style="margin-right: 5px; font-weight: bold; white-space: nowrap;">Stato:</label>
    <select id="status" name="status" style="width: 100px;">
    <option value="tutti"<?php echo (empty($filter['status']) || $filter['status'] === 'tutti') ? ' selected' : ''; ?>>Tutti</option>
    <option value="attivo"<?php echo ($filter['status'] === 'attivo') ? ' selected' : ''; ?>>Solo attivi</option>
</select>
</div>

        <div style="display: flex; align-items: center; min-width: 180px;">
            <label for="search" style="margin-right: 5px; font-weight: bold; white-space: nowrap;">Nome/Cognome:</label>
            <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" style="width: 120px;">
        </div>

        <div style="display: flex; align-items: center; min-width: 170px;">
            <label for="phone_search" style="margin-right: 5px; font-weight: bold; white-space: nowrap;">Telefono:</label>
            <input type="text" id="phone_search" name="phone_search" value="<?php echo htmlspecialchars($phone_search); ?>" style="width: 110px;">
        </div>

        <div style="display: flex; align-items: center; min-width: 200px;">
            <label for="address_search" style="margin-right: 5px; font-weight: bold; white-space: nowrap;">Indirizzo:</label>
            <input type="text" id="address_search" name="address_search" value="<?php echo htmlspecialchars($address_search); ?>" style="width: 150px;">
        </div>

<div style="display: flex; align-items: center; min-width: 170px;">
            <label for="zone" style="margin-right: 5px; font-weight: bold; white-space: nowrap;">Zona:</label>
            <select id="zone" name="zone" style="width: 110px;">
                <option value="">Seleziona</option>
                <?php foreach ($zones as $zone) { ?>
                    <option value="<?php echo htmlspecialchars($zone); ?>"<?php echo ($filter['zone'] === $zone) ? ' selected' : ''; ?>><?php echo htmlspecialchars($zone); ?></option>
                <?php } ?>
            </select>
        </div>
        
        <!-- Pulsanti -->
        <div style="display: flex; align-items: center; min-width: 220px;">
            <button type="button" id="search-button" class="pure-button button-primary" style="margin-right: 8px;">Cerca</button>
            <button type="button" id="clear-filters" class="pure-button">Cancella Filtri</button>
            <span id="loading-indicator" style="display: none; margin-left: 10px;">
                <span class="spinner"></span> Caricamento...
            </span>
        </div>
    </div>
</form>
        
        
   
</div>

<p id="no-appointments-message" class="<?php echo $showTable ? 'hidden' : ''; ?> centrato aria">Non ci sono appuntamenti</p>

    
   
    
    
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
        <th>Stato</th> <!-- **NUOVA COLONNA** -->
        <th>Azioni</th>
    </tr>
</thead>
            <tbody>
    <?php foreach ($appointments as $appointment) { 
        // **NUOVO: Applica stile per appuntamenti disdetti**
        $rowClass = ($appointment['status'] === 'disdetto') ? 'status-disdetto' : '';
    ?>
    <tr class="<?php echo $rowClass; ?>">
        <td><?php echo htmlspecialchars($appointment['name']); ?></td>
        <td><?php echo htmlspecialchars($appointment['surname']); ?></td>
        <td><?php echo htmlspecialchars($appointment['phone']); ?></td>
        <td class="notes-cell"><?php echo htmlspecialchars($appointment['notes']); ?></td>
        <td><?php echo date('d/m/Y', strtotime($appointment['appointment_date'])); ?></td>
        <td><?php echo htmlspecialchars($appointment['appointment_time']); ?></td>
        <td><?php echo htmlspecialchars($appointment['address']); ?></td>
        <td><?php echo htmlspecialchars($appointment['zone']); ?></td>
        
        <!-- **NUOVA COLONNA STATO** -->
        <td>
            <?php if ($appointment['status'] === 'disdetto'): ?>
                <span class="badge-disdetto">DISDETTO</span>
                <!-- **NUOVO: Link per riprendere appuntamento** -->
                <div class="riprendi-btns">
                    <a href="add_appointment.php?copy_appointment=<?php echo $appointment['id']; ?>&from_page=manage" class="btn-smart">Copia Smart</a>
                    <a href="add_appointment.php?direct_restore=<?php echo $appointment['id']; ?>&from_page=manage" class="btn-direct">Ripristina Diretto</a>
                </div>
            <?php else: ?>
                <span style="color: green; font-weight: bold;">ATTIVO</span>
            <?php endif; ?>
        </td>
        
        <!-- **COLONNA AZIONI MODIFICATA** -->
        <td>
            <?php if ($appointment['status'] === 'disdetto'): ?>
                <!-- **APPUNTAMENTO DISDETTO: Pulsante ripristina** -->
                <form method="post" action="manage_appointments.php" style="display:inline;" id="restore-form-<?php echo $appointment['id']; ?>">
                    <input type="hidden" name="appointment_id" value="<?php echo $appointment['id']; ?>">
                    <button type="button" class="ripristina-btn pure-button button-small" onclick="confirmRestore(<?php echo htmlspecialchars(json_encode($appointment)); ?>)">Ripristina</button>
                    <input type="submit" name="restore_appointment" value="Conferma Ripristina" style="display:none;" id="confirm-restore-<?php echo $appointment['id']; ?>">
                </form>
            <?php else: ?>
                <!-- **APPUNTAMENTO ATTIVO: Pulsanti normali** -->
                <button class="modifica-btn pure-button button-small button-green" onclick="showActions(<?php echo $appointment['id']; ?>)">Modifica</button>
                
                <button class="disdici-btn pure-button button-small" id="cancel-btn-<?php echo $appointment['id']; ?>" onclick="confirmCancel(<?php echo htmlspecialchars(json_encode($appointment)); ?>)">Disdici</button>
                
                <form method="post" action="manage_appointments.php" style="display:inline;">
                    <input type="hidden" name="appointment_id" value="<?php echo $appointment['id']; ?>">
                    <input type="submit" name="cancel_appointment" value="Conferma Disdetta" class="confirm-btn pure-button button-small button-red" id="confirm-cancel-<?php echo $appointment['id']; ?>" style="display:none;">
                </form>
                
                <!-- **MANTIENI anche il vecchio sistema di cancellazione definitiva** -->
                <button class="cancella-btn pure-button button-small button-red" id="delete-btn-<?php echo $appointment['id']; ?>" onclick="confirmDelete(<?php echo htmlspecialchars(json_encode($appointment)); ?>)" style="display:none;">Cancella</button>
                
                <form method="post" action="manage_appointments.php" style="display:inline;">
                    <input type="hidden" name="appointment_id" value="<?php echo $appointment['id']; ?>">
                    <input type="submit" name="delete_confirm" value="Conferma cancella" class="confirm-btn pure-button button-small button-red" id="confirm-delete-<?php echo $appointment['id']; ?>" style="display:none;">
                </form>
            <?php endif; ?>
        </td>
    </tr>
    
    <!-- **RIGA DI MODIFICA** (solo per appuntamenti attivi) -->
    <?php if ($appointment['status'] !== 'disdetto'): ?>
    <tr id="action-<?php echo $appointment['id']; ?>" class="action-row" style="display:none;">
        <td colspan="10"> <!-- **AUMENTATO colspan da 9 a 10** -->
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
    <?php endif; ?>
    <?php } ?>
</tbody>
        </table>
    </div>
    <div class="pure-g aria centrato pagination">
    <?php if ($page > 1) { ?>
        <a href="manage_appointments.php?page=<?php echo $page - 1; ?>&date=<?php echo urlencode($filter['date'] ?? ''); ?>&zone=<?php echo urlencode($filter['zone'] ?? ''); ?>&search=<?php echo urlencode($search); ?>&phone_search=<?php echo urlencode($phone_search); ?>&address_search=<?php echo urlencode($address_search); ?>&status=<?php echo urlencode($filter['status'] ?? ''); ?>" class="pure-button">← Precedente</a>
    <?php } ?>
    
    <!-- Mostra info pagina corrente -->
    <span style="margin: 0 15px; padding: 8px;">Pagina <?php echo $page; ?> di <?php echo $total_pages; ?></span>
    
    <?php if ($page < $total_pages) { ?>
        <a href="manage_appointments.php?page=<?php echo $page + 1; ?>&date=<?php echo urlencode($filter['date'] ?? ''); ?>&zone=<?php echo urlencode($filter['zone'] ?? ''); ?>&search=<?php echo urlencode($search); ?>&phone_search=<?php echo urlencode($phone_search); ?>&address_search=<?php echo urlencode($address_search); ?>&status=<?php echo urlencode($filter['status'] ?? ''); ?>" class="pure-button">Successivo →</a>
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
