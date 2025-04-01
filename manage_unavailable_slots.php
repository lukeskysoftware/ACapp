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
            ORDER BY u.date DESC, u.start_time ASC";
    
    $result = mysqli_query($conn, $sql);
    
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $slots[] = $row;
        }
    }
    
    return $slots;
}

// Gestione dell'aggiunta di un nuovo slot non disponibile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $date = $_POST['date'];
    $zone_id = !empty($_POST['zone_id']) ? $_POST['zone_id'] : NULL;
    $reason = $_POST['reason'];
    $all_day = isset($_POST['all_day']) ? 1 : 0;
    $start_time = !empty($_POST['start_time']) ? $_POST['start_time'] : NULL;
    $end_time = !empty($_POST['end_time']) ? $_POST['end_time'] : NULL;
    $created_by = $_SESSION['user_id'];
    
    // Validazione
    if (empty($date)) {
        $error = "La data è obbligatoria!";
    } elseif (!$all_day && (empty($start_time) || empty($end_time))) {
        $error = "Per blocchi parziali, specificare sia l'ora di inizio che di fine!";
    } else {
        // Inserimento nel DB
        $sql = "INSERT INTO cp_unavailable_slots (date, start_time, end_time, all_day, zone_id, reason, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssisii", $date, $start_time, $end_time, $all_day, $zone_id, $reason, $created_by);
        
        if ($stmt->execute()) {
            $success = "Blocco orario aggiunto con successo!";
        } else {
            $error = "Errore nell'aggiunta del blocco orario: " . $stmt->error;
        }
        
        $stmt->close();
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
                    <div class="form-label">Data:</div>
                    <div class="form-input">
                        <input type="text" id="date" name="date" class="date-picker pure-input-1" placeholder="Seleziona una data" required>
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
                
                <div class="form-row">
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
                            <th>Data</th>
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
                                <td><?php echo date('d/m/Y', strtotime($slot['date'])); ?></td>
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
        flatpickr(".date-picker", {
            dateFormat: "Y-m-d",
            locale: "it",
            minDate: "today"
        });
        
        flatpickr(".time-picker", {
            enableTime: true,
            noCalendar: true,
            dateFormat: "H:i:00",
            time_24hr: true
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
        });
    </script>
</body>
</html>