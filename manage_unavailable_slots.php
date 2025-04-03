<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

include 'db.php';

// Funzione per ottenere tutte le zone
function getAllZones() {
    global $conn;
    $zones = [];
    $sql = "SELECT id, name FROM cp_zones ORDER BY name";
    $result = mysqli_query($conn, $sql);
    
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $zones[] = $row;
        }
    }
    
    return $zones;
}

// Funzione per ottenere tutti gli slot non disponibili
function getUnavailableSlots() {
    global $conn;
    $slots = [];
    
    // Prima query: contare quanti record ci sono nella tabella
    $count_sql = "SELECT COUNT(*) as total FROM cp_unavailable_slots";
    $count_result = mysqli_query($conn, $count_sql);
    
    if (!$count_result) {
        error_log("Errore SQL nel conteggio: " . mysqli_error($conn));
        return ["error" => "Errore SQL nel conteggio: " . mysqli_error($conn)];
    }
    
    $count_row = mysqli_fetch_assoc($count_result);
    $total_rows = $count_row['total'];
    
    if ($total_rows == 0) {
        return ["message" => "Nessun dato trovato nella tabella cp_unavailable_slots", "count" => 0];
    }
    
    // Seconda query: recuperare i dati con cp_users invece di users
    $sql = "SELECT 
                u.id, 
                u.date_start, 
                u.date_end, 
                u.start_time, 
                u.end_time, 
                u.all_day, 
                u.zone_id, 
                u.reason, 
                u.created_at,
                u.created_by,
                z.name as zone_name,
                cu.username as created_by_name
            FROM cp_unavailable_slots u
            LEFT JOIN cp_zones z ON u.zone_id = z.id 
            LEFT JOIN cp_users cu ON u.created_by = cu.id
            ORDER BY u.date_start DESC, u.start_time ASC";
    
    $result = mysqli_query($conn, $sql);
    
    if (!$result) {
        error_log("Errore SQL: " . mysqli_error($conn));
        return ["error" => "Errore SQL: " . mysqli_error($conn), "count" => 0];
    }
    
    while ($row = mysqli_fetch_assoc($result)) {
        // Assicuriamoci che i campi essenziali siano inizializzati correttamente
        if (!isset($row['zone_name']) || empty($row['zone_name'])) {
            $row['zone_name'] = 'Tutte le zone';
        }
        
        // Se l'username non è disponibile, usa l'ID
        if (!isset($row['created_by_name']) || empty(trim($row['created_by_name']))) {
            $row['created_by_name'] = 'Utente ID: ' . $row['created_by'];
        }
        
        $slots[] = $row;
    }
    
    return ["slots" => $slots, "count" => count($slots)];
}

// Funzione di debug per verificare la struttura della tabella
function checkTableStructure() {
    global $conn;
    $debug_info = [];
    
    // Verifica se la tabella esiste
    $check_table = mysqli_query($conn, "SHOW TABLES LIKE 'cp_unavailable_slots'");
    if (mysqli_num_rows($check_table) == 0) {
        return ["error" => "La tabella cp_unavailable_slots non esiste"];
    }
    
    // Ottieni la struttura della tabella
    $structure = mysqli_query($conn, "DESCRIBE cp_unavailable_slots");
    if (!$structure) {
        return ["error" => "Impossibile leggere la struttura della tabella: " . mysqli_error($conn)];
    }
    
    $columns = [];
    while ($row = mysqli_fetch_assoc($structure)) {
        $columns[] = $row;
    }
    
    // Verifica il numero di righe nella tabella
    $count = mysqli_query($conn, "SELECT COUNT(*) as total FROM cp_unavailable_slots");
    $count_result = mysqli_fetch_assoc($count);
    
    $debug_info["columns"] = $columns;
    $debug_info["row_count"] = $count_result["total"];
    
    // Recupera un esempio di record se ce ne sono
    if ($count_result["total"] > 0) {
        $sample = mysqli_query($conn, "SELECT * FROM cp_unavailable_slots LIMIT 1");
        if ($sample && mysqli_num_rows($sample) > 0) {
            $debug_info["sample_record"] = mysqli_fetch_assoc($sample);
        }
    }
    
    return $debug_info;
}
// Recupera le informazioni di debug
$debug_table_info = checkTableStructure();

// Gestione dell'aggiunta di un nuovo slot non disponibile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $date_start = $_POST['date_start'];
    $date_end = !empty($_POST['date_end']) ? $_POST['date_end'] : $date_start; // Se non c'è data fine, usa data inizio
    $zone_id = !empty($_POST['zone_id']) ? $_POST['zone_id'] : NULL;
    $reason = $_POST['reason'];
    $all_day = isset($_POST['all_day']) ? 1 : 0;
    $start_time = !empty($_POST['start_time']) ? $_POST['start_time'] : NULL;
    $end_time = !empty($_POST['end_time']) ? $_POST['end_time'] : NULL;
    $created_by = $_SESSION['user_id'];
    
    // Validazione
    if (empty($date_start)) {
        $error = "La data di inizio è obbligatoria!";
    } elseif (strtotime($date_end) < strtotime($date_start)) {
        $error = "La data di fine non può essere precedente alla data di inizio!";
    } elseif (!$all_day && (empty($start_time) || empty($end_time))) {
        $error = "Per blocchi parziali, specificare sia l'ora di inizio che di fine!";
    } else {
        // Se è selezionato un intervallo di date, impostiamo all_day = 1
        if ($date_start != $date_end) {
            $all_day = 1;
            $start_time = NULL;
            $end_time = NULL;
        }
        
        // Inserimento nel DB
        $sql = "INSERT INTO cp_unavailable_slots (date_start, date_end, start_time, end_time, all_day, zone_id, reason, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            $error = "Errore nella preparazione della query: " . $conn->error;
        } else {
            $stmt->bind_param("ssssiisi", $date_start, $date_end, $start_time, $end_time, $all_day, $zone_id, $reason, $created_by);
            
            if ($stmt->execute()) {
                $success = "Blocco orario aggiunto con successo!";
            } else {
                $error = "Errore nell'aggiunta del blocco orario: " . $stmt->error;
            }
            
            $stmt->close();
        }
    }
}

// Gestione dell'eliminazione di uno slot non disponibile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $id = $_POST['id'];
    
    $sql = "DELETE FROM cp_unavailable_slots WHERE id = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        $error = "Errore nella preparazione della query di eliminazione: " . $conn->error;
    } else {
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            $success = "Blocco orario eliminato con successo!";
        } else {
            $error = "Errore nell'eliminazione del blocco orario: " . $stmt->error;
        }
        
        $stmt->close();
    }
}

$zones = getAllZones();
$unavailable_slots_data = getUnavailableSlots();

if (isset($unavailable_slots_data['error'])) {
    $error = $unavailable_slots_data['error'];
    $unavailable_slots = [];
} else {
    $unavailable_slots = isset($unavailable_slots_data['slots']) ? $unavailable_slots_data['slots'] : [];
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Date Non Disponibili</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/purecss@3.0.0/build/pure-min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="styles.css">
    <style>
        .container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
        }
        .form-container {
            background-color: #f9f9f9;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .form-row {
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
        }
        .form-label {
            width: 150px;
            text-align: right;
            padding-right: 10px;
            font-weight: bold;
        }
        .form-input {
            flex: 1;
            min-width: 200px;
        }
        .form-group {
            margin-bottom: 10px;
        }
        .checkbox-group {
            display: flex;
            align-items: center;
        }
        .error {
            color: red;
            margin-bottom: 15px;
        }
        .success {
            color: green;
            margin-bottom: 15px;
        }
        .table-container {
            overflow-x: auto;
        }
        table {
            width: 100%;
            margin-top: 20px;
        }
        th {
            background-color: #f2f2f2;
        }
        th, td {
            padding: 8px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .btn-delete {
            background-color: #d9534f;
            color: white;
        }
        .all-day {
            color: #d9534f;
            font-weight: bold;
        }
        .partial-day {
            color: #f0ad4e;
        }
        .date-range-container {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .date-range-separator {
            font-weight: bold;
            font-size: 18px;
        }
        .debug-info {
            margin-top: 30px;
            padding: 15px;
            background-color: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .debug-info details {
            margin-bottom: 10px;
        }
        .debug-info summary {
            font-weight: bold;
            cursor: pointer;
        }
        .debug-info pre {
            white-space: pre-wrap;
            background-color: #eee;
            padding: 10px;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <?php include 'menu.php'; ?>
    
    <div class="container">
        <h1>Gestione Date Non Disponibili</h1>
        <p>Utilizzare questo modulo per impostare date e orari che non saranno disponibili per la prenotazione degli appuntamenti.</p>
        
        <?php if (isset($error)): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if (isset($success)): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <div class="form-container">
            <h2>Aggiungi Nuovo Blocco</h2>
            <form class="pure-form" method="POST" action="">
                <input type="hidden" name="action" value="add">
                
                <div class="form-row">
                    <div class="form-label">Periodo:</div>
                    <div class="form-input date-range-container">
                        <input type="text" id="date_start" name="date_start" class="date-picker pure-input-1" placeholder="Data inizio" required>
                        <span class="date-range-separator">-</span>
                        <input type="text" id="date_end" name="date_end" class="date-picker pure-input-1" placeholder="Data fine">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-label">Zona:</div>
                    <div class="form-input">
                        <select name="zone_id" class="pure-input-1">
                            <option value="">Tutte le zone</option>
                            <?php foreach ($zones as $zone): ?>
                                <option value="<?php echo $zone['id']; ?>"><?php echo htmlspecialchars($zone['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-row" id="all_day_row">
                    <div class="form-label">Intero giorno:</div>
                    <div class="form-input checkbox-group">
                        <input type="checkbox" id="all_day" name="all_day" onchange="toggleTimeFields()">
                        <label for="all_day">Blocca l'intero giorno</label>
                    </div>
                </div>
                
                <div id="time_fields">
                    <div class="form-row">
                        <div class="form-label">Ora inizio:</div>
                        <div class="form-input">
                            <input type="text" id="start_time" name="start_time" class="time-picker pure-input-1" placeholder="Seleziona ora inizio">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-label">Ora fine:</div>
                        <div class="form-input">
                            <input type="text" id="end_time" name="end_time" class="time-picker pure-input-1" placeholder="Seleziona ora fine">
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-label">Motivo (opzionale):</div>
                    <div class="form-input">
                        <input type="text" name="reason" class="pure-input-1" placeholder="Motivo del blocco">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-label"></div>
                    <div class="form-input">
                        <button type="submit" class="pure-button pure-button-primary">Aggiungi Blocco</button>
                    </div>
                </div>
            </form>
        </div>
        
        <div class="table-container">
            <h2>Blocchi Esistenti</h2>
            <?php if (empty($unavailable_slots)): ?>
                <p>Non ci sono blocchi orari impostati.</p>
                
                <!-- Sezione di debug per l'amministratore -->
                <?php if ($_SESSION['user_id'] == 1): ?>
                <div class="debug-info">
                    <h3>Informazioni di Debug</h3>
                    <p>Informazioni sulla struttura della tabella cp_unavailable_slots:</p>
                    <?php if (isset($debug_table_info['error'])): ?>
                        <div class="error"><?php echo $debug_table_info['error']; ?></div>
                    <?php else: ?>
                        <details>
                            <summary>Struttura della tabella</summary>
                            <pre><?php print_r($debug_table_info['columns']); ?></pre>
                        </details>
                        <p>Numero di record nella tabella: <strong><?php echo $debug_table_info['row_count']; ?></strong></p>
                        
                        <?php if (isset($unavailable_slots_data['message'])): ?>
                            <p><em><?php echo $unavailable_slots_data['message']; ?></em></p>
                        <?php endif; ?>
                        
                        <?php if (isset($unavailable_slots_data) && isset($unavailable_slots_data['count'])): ?>
                            <p>Conteggio record dalla query: <strong><?php echo $unavailable_slots_data['count']; ?></strong></p>
                            
                            <!-- Se ci sono record ma non vengono visualizzati, mostra di più informazioni -->
                            <?php if ($unavailable_slots_data['count'] > 0 && empty($unavailable_slots)): ?>
                                <details>
                                    <summary>Dettagli della query</summary>
                                    <pre>
SELECT 
    u.id, 
    u.date_start, 
    u.date_end, 
    u.start_time, 
    u.end_time, 
    u.all_day, 
    u.zone_id, 
    u.reason, 
    u.created_at,
    u.created_by,
    z.name as zone_name, 
    CONCAT(IFNULL(us.firstname, ''), ' ', IFNULL(us.lastname, '')) as created_by_name
FROM cp_unavailable_slots u
LEFT JOIN cp_zones z ON u.zone_id = z.id 
LEFT JOIN cp_users us ON u.created_by = us.id
ORDER BY u.date_start DESC, u.start_time ASC
                                    </pre>
                                </details>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
            <?php else: ?>
                <p>Totale blocchi: <?php echo count($unavailable_slots); ?></p>
                <table class="pure-table pure-table-bordered">
                    <thead>
                        <tr>
                            <th>Periodo</th>
                            <th>Orario</th>
                            <th>Zona</th>
                            <th>Motivo</th>
                            <th>Creato da</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($unavailable_slots as $slot): ?>
                            <tr>
                                <td>
                                    <?php 
                                    echo date('d/m/Y', strtotime($slot['date_start']));
                                    if ($slot['date_start'] != $slot['date_end']) {
                                        echo ' - ' . date('d/m/Y', strtotime($slot['date_end']));
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php if ($slot['all_day']): ?>
                                        <span class="all-day">Intero giorno</span>
                                    <?php else: ?>
                                        <span class="partial-day">
                                            <?php echo date('H:i', strtotime($slot['start_time'])); ?> - 
                                            <?php echo date('H:i', strtotime($slot['end_time'])); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $slot['zone_id'] ? htmlspecialchars($slot['zone_name']) : 'Tutte le zone'; ?></td>
                                <td><?php echo htmlspecialchars($slot['reason']); ?></td>
                                <td><?php echo htmlspecialchars($slot['created_by_name']); ?></td>
                                <td>
                                    <form method="POST" action="" onsubmit="return confirm('Sei sicuro di voler eliminare questo blocco?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $slot['id']; ?>">
                                        <button type="submit" class="pure-button btn-delete">Elimina</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/it.js"></script>
    <script>
        // Inizializza i selettori di data e ora
        const dateStartPicker = flatpickr("#date_start", {
            dateFormat: "Y-m-d",
            locale: "it",
            minDate: "today",
            allowInput: true,
            onChange: function(selectedDates, dateStr) {
                // Quando la data di inizio viene modificata, aggiorna la data di fine per essere uguale
                if (selectedDates.length > 0) {
                    document.getElementById('date_end').value = dateStr;
                    dateEndPicker.setDate(dateStr);
                }
            }
        });
        
        const dateEndPicker = flatpickr("#date_end", {
            dateFormat: "Y-m-d",
            locale: "it",
            minDate: "today",
            allowInput: true,
            onChange: function(selectedDates, dateStr) {
                // Se l'utente seleziona una data finale diversa dalla iniziale, imposta automaticamente intero giorno
                const dateStartValue = document.getElementById('date_start').value;
                if (dateStartValue && dateStr && dateStartValue !== dateStr) {
                    document.getElementById('all_day').checked = true;
                    document.getElementById('all_day_row').style.display = 'none';
                    toggleTimeFields();
                } else {
                    document.getElementById('all_day_row').style.display = 'flex';
                }
            }
        });
        
        flatpickr(".time-picker", {
            enableTime: true,
            noCalendar: true,
            dateFormat: "H:i:00",
            time_24hr: true,
            allowInput: true
        });
        
        // Funzione per mostrare/nascondere i campi dell'ora in base alla selezione "Intero giorno"
        function toggleTimeFields() {
            const allDayCheckbox = document.getElementById('all_day');
            const timeFields = document.getElementById('time_fields');
            
            if (allDayCheckbox.checked) {
                timeFields.style.display = 'none';
            } else {
                timeFields.style.display = 'block';
            }
        }
        
        // Inizializzazione
        document.addEventListener('DOMContentLoaded', function() {
            toggleTimeFields();
            
            // Assicurati che data_end sia inizialmente uguale a date_start
            const dateStart = document.getElementById('date_start');
            const dateEnd = document.getElementById('date_end');
            
            if (dateStart.value && !dateEnd.value) {
                dateEnd.value = dateStart.value;
            }
        });
    </script>
</body>
</html>
