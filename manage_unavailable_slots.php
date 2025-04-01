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
    
    $sql = "SELECT u.*, z.name as zone_name, CONCAT(us.firstname, ' ', us.lastname) as created_by_name
            FROM cp_unavailable_slots u
            LEFT JOIN cp_zones z ON u.zone_id = z.id 
            LEFT JOIN users us ON u.created_by = us.id
            ORDER BY u.date_start DESC, u.time_start ASC";
    
    $result = mysqli_query($conn, $sql);
    
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $slots[] = $row;
        }
    }
    
    return $slots;
}

// Funzione per verificare se c'è una sovrapposizione di date e orari
function checkOverlap($date_start, $date_end, $time_start, $time_end, $all_day, $zone_id) {
    global $conn;

    // Costruisci la query di base
    $sql = "SELECT COUNT(*) as count FROM cp_unavailable_slots WHERE ";
    
    if ($zone_id !== NULL) {
        // Se è specificata una zona, controlla solo gli slot per quella zona o per tutte le zone
        $sql .= "(zone_id = ? OR zone_id IS NULL) AND ";
    }
    
    // Se è un blocco per l'intero giorno
    if ($all_day) {
        // Verifica sovrapposizione con qualsiasi data nel range specificato
        $sql .= "((date_start <= ? AND date_end >= ?) OR 
                (date_start <= ? AND date_end >= ?) OR 
                (date_start >= ? AND date_end <= ?))";
        
        $stmt = $conn->prepare($sql);
        
        if ($zone_id !== NULL) {
            $stmt->bind_param("isssss", $zone_id, $date_end, $date_start, $date_start, $date_end, $date_start, $date_end);
        } else {
            $stmt->bind_param("ssssss", $date_end, $date_start, $date_start, $date_end, $date_start, $date_end);
        }
    } else {
        // Per blocchi parziali, controlla anche gli orari
        $sql .= "((date_start = ? AND time_start <= ? AND time_end >= ?) OR 
                (date_end = ? AND time_start <= ? AND time_end >= ?) OR 
                (all_day = 1 AND date_start <= ? AND date_end >= ?))";
        
        $stmt = $conn->prepare($sql);
        
        if ($zone_id !== NULL) {
            $stmt->bind_param("issssssss", $zone_id, $date_start, $time_end, $time_start, $date_end, $time_end, $time_start, $date_end, $date_start);
        } else {
            $stmt->bind_param("ssssssss", $date_start, $time_end, $time_start, $date_end, $time_end, $time_start, $date_end, $date_start);
        }
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    return $row['count'] > 0;
}

// Gestione dell'aggiunta di un nuovo slot non disponibile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $date_start = $_POST['date_start'];
    $date_end = !empty($_POST['date_end']) ? $_POST['date_end'] : $date_start; // Se non è specificata una data di fine, usa la data di inizio
    $zone_id = !empty($_POST['zone_id']) ? $_POST['zone_id'] : NULL;
    $reason = $_POST['reason'];
    $all_day = isset($_POST['all_day']) ? 1 : 0;
    $time_start = !empty($_POST['time_start']) ? $_POST['time_start'] : NULL;
    $time_end = !empty($_POST['time_end']) ? $_POST['time_end'] : NULL;
    $created_by = $_SESSION['user_id'];
    
    // Validazione
    if (empty($date_start)) {
        $error = "La data di inizio è obbligatoria!";
    } elseif (strtotime($date_end) < strtotime($date_start)) {
        $error = "La data di fine non può essere precedente alla data di inizio!";
    } elseif (!$all_day && (empty($time_start) || empty($time_end))) {
        $error = "Per blocchi parziali, specificare sia l'ora di inizio che di fine!";
    } else {
        // Se è un periodo di date, imposta tutti i giorni come interi giorni
        if ($date_start != $date_end) {
            $all_day = 1; // Forza "Intero giorno" per periodi di date
        }
        
        // Verifica se c'è una sovrapposizione con date/orari esistenti
        if (checkOverlap($date_start, $date_end, $time_start, $time_end, $all_day, $zone_id)) {
            $error = "Attenzione: esiste già un blocco per questa data/orario nella zona selezionata!";
        } else {
            // Inserimento nel DB
            $sql = "INSERT INTO cp_unavailable_slots (date_start, date_end, time_start, time_end, all_day, zone_id, reason, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssssiisi", $date_start, $date_end, $time_start, $time_end, $all_day, $zone_id, $reason, $created_by);
            
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
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        $success = "Blocco orario eliminato con successo!";
    } else {
        $error = "Errore nell'eliminazione del blocco orario: " . $stmt->error;
    }
    
    $stmt->close();
}

$zones = getAllZones();
$unavailable_slots = getUnavailableSlots();
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
            padding: 10px;
            background-color: #ffeeee;
            border-radius: 4px;
        }
        .success {
            color: green;
            margin-bottom: 15px;
            padding: 10px;
            background-color: #eeffee;
            border-radius: 4px;
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
            display: grid;
            grid-template-columns: 1fr 30px 1fr;
            align-items: center;
            gap: 10px;
        }
        .date-separator {
            text-align: center;
            font-weight: bold;
        }
        .period-indicator {
            background-color: #e9ecef;
            padding: 5px;
            border-radius: 4px;
            margin-top: 5px;
            font-size: 0.9em;
            color: #495057;
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
                    <div class="form-input">
                        <div class="date-range-container">
                            <div>
                                <label for="date_start">Data inizio:</label>
                                <input type="text" id="date_start" name="date_start" class="date-picker pure-input-1" placeholder="Seleziona data inizio" required>
                            </div>
                            <div class="date-separator">-</div>
                            <div>
                                <label for="date_end">Data fine:</label>
                                <input type="text" id="date_end" name="date_end" class="date-picker pure-input-1" placeholder="Seleziona data fine">
                                <div class="period-indicator" id="period-message">Selezionare una data di inizio e una di fine per bloccare un periodo</div>
                            </div>
                        </div>
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
            <?php else: ?>
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
                                    $start_date = date('d/m/Y', strtotime($slot['date_start']));
                                    $end_date = date('d/m/Y', strtotime($slot['date_end']));
                                    if ($start_date === $end_date) {
                                        echo $start_date;
                                    } else {
                                        echo "Dal $start_date al $end_date";
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php if ($slot['all_day']): ?>
                                        <span class="all-day">Intero giorno</span>
                                    <?php else: ?>
                                        <span class="partial-day">
                                            <?php echo date('H:i', strtotime($slot['time_start'])); ?> - 
                                            <?php echo date('H:i', strtotime($slot['time_end'])); ?>
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
        // Inizializza i selettori di data
        let startDatePicker, endDatePicker;

        document.addEventListener('DOMContentLoaded', function() {
            // Inizializzazione date picker con selezione range
            startDatePicker = flatpickr("#date_start", {
                dateFormat: "Y-m-d",
                locale: "it",
                minDate: "today",
                onChange: function(selectedDates, dateStr) {
                    // Aggiorna la data minima per il date picker di fine
                    endDatePicker.set("minDate", dateStr);
                    checkDateRange();
                }
            });
            
            endDatePicker = flatpickr("#date_end", {
                dateFormat: "Y-m-d",
                locale: "it",
                minDate: "today",
                onChange: function() {
                    checkDateRange();
                }
            });

            // Inizializza i time picker
            flatpickr(".time-picker", {
                enableTime: true,
                noCalendar: true,
                dateFormat: "H:i:00",
                time_24hr: true
            });
            
            // Setup iniziale
            toggleTimeFields();
            checkDateRange();
        });
        
        // Funzione per controllare se è selezionato un intervallo di date
        function checkDateRange() {
            const startDate = document.getElementById('date_start').value;
            const endDate = document.getElementById('date_end').value;
            const allDayRow = document.getElementById('all_day_row');
            const allDayCheckbox = document.getElementById('all_day');
            const timeFields = document.getElementById('time_fields');
            const periodMessage = document.getElementById('period-message');
            
            if (startDate && endDate && startDate !== endDate) {
                // È selezionato un intervallo di date, forza "intero giorno"
                allDayRow.style.display = 'none';
                allDayCheckbox.checked = true;
                timeFields.style.display = 'none';
                periodMessage.innerHTML = `Periodo selezionato: tutti i giorni saranno bloccati per l'intera giornata`;
                periodMessage.style.display = 'block';
            } else {
                // È selezionata una singola data o nessuna data
                allDayRow.style.display = 'flex';
                toggleTimeFields();
                
                if (startDate && !endDate) {
                    document.getElementById('date_end').value = startDate;
                    periodMessage.style.display = 'none';
                } else {
                    periodMessage.style.display = 'none';
                }
            }
        }
        
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
    </script>
</body>
</html>
