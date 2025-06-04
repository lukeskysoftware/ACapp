<?php
ob_start();
session_start();
include 'db.php';

function searchPatients($conn, $search) {
    $param = "%$search%";
    $search = trim($search);
    $double = false;
    $name = $surname = '';
    if (strpos($search, ' ') !== false) {
        $parts = preg_split('/\s+/', $search, 2);
        $name = $parts[0];
        $surname = $parts[1];
        $double = true;
    }
    if ($double) {
        $sql = "SELECT * FROM cp_patients
            WHERE (name LIKE ? OR surname LIKE ? OR phone LIKE ?)
               OR (name LIKE ? AND surname LIKE ?)
               OR (surname LIKE ? AND name LIKE ?)
               OR id IN (SELECT patient_id FROM cp_appointments WHERE address LIKE ?)
            ORDER BY surname, name";
        $param2 = "%$name%";
        $param3 = "%$surname%";
        $param4 = "%$surname%";
        $param5 = "%$name%";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssssss", $param, $param, $param, $param2, $param3, $param4, $param5, $param);
    } else {
        $sql = "SELECT * FROM cp_patients
            WHERE name LIKE ? OR surname LIKE ? OR phone LIKE ? 
               OR id IN (SELECT patient_id FROM cp_appointments WHERE address LIKE ?)
            ORDER BY surname, name";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssss", $param, $param, $param, $param);
    }
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function getPatientAppointments($conn, $patient_id) {
    $sql = "SELECT a.*, z.name as zone_name
            FROM cp_appointments a
            LEFT JOIN cp_zones z ON a.zone_id = z.id
            WHERE a.patient_id = ?
            ORDER BY a.appointment_date DESC, a.appointment_time DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function searchAppointmentsByDate($conn, $date) {
    $sql = "SELECT a.*, p.name, p.surname, p.phone
            FROM cp_appointments a
            LEFT JOIN cp_patients p ON a.patient_id = p.id
            WHERE a.appointment_date = ?
            ORDER BY a.appointment_time";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $date);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$mode = isset($_GET['mode']) ? $_GET['mode'] : 'patient';
$query = isset($_GET['query']) ? trim($_GET['query']) : '';
$search_date = isset($_GET['search_date']) ? $_GET['search_date'] : '';
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Ricerca Pazienti e Appuntamenti</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .patient-card {margin-bottom: 30px;}
        .action-btns .btn {margin-right: 10px;}
        .appointment-list {font-size:0.96em;}
        .appointment-list li {margin-bottom:3px;}
    </style>
</head>
<body>
<?php include 'menu.php'; ?>
<div class="container mt-4">
    <h1 class="mb-4 text-center" style="color:#222;">Ricerca Pazienti e Appuntamenti</h1>
    <form method="GET" class="mb-4" id="searchForm">
        <div class="row mb-3">
            <div class="col-md-4">
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="mode" value="patient" id="typePatient" <?php if($mode=='patient') echo 'checked'; ?>>
                    <label class="form-check-label" for="typePatient">Cerca Paziente</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="mode" value="date" id="typeDate" <?php if($mode=='date') echo 'checked'; ?>>
                    <label class="form-check-label" for="typeDate">Cerca per Data</label>
                </div>
            </div>
            <div class="col-md-5" id="search_by_patient" style="<?php echo $mode=='patient'?'':'display:none;'; ?>">
                <input type="text" name="query" class="form-control" placeholder="Nome, Cognome, Telefono o Indirizzo" value="<?php echo htmlspecialchars($query); ?>">
            </div>
            <div class="col-md-3" id="search_by_date" style="<?php echo $mode=='date'?'':'display:none;'; ?>">
                <input type="date" name="search_date" class="form-control" value="<?php echo htmlspecialchars($search_date); ?>">
            </div>
        </div>
        <div class="row">
            <div class="col-md-10"></div>
            <div class="col-md-2 text-end" style="display: flex; gap: 6px;">
                <button type="submit" class="btn btn-primary">Cerca</button>
                <button type="button" class="btn btn-outline-secondary" id="clearFiltersBtn">Cancella filtri</button>
            </div>
        </div>
    </form>
    <script>
    document.querySelectorAll('input[name="mode"]').forEach(radio => {
        radio.addEventListener('change', function() {
            document.getElementById('search_by_patient').style.display = this.value == 'patient' ? '' : 'none';
            document.getElementById('search_by_date').style.display = this.value == 'date' ? '' : 'none';
        });
    });

    // Bottone cancella filtri
    document.getElementById('clearFiltersBtn').addEventListener('click', function() {
        // Svuota i campi della form
        if(document.querySelector('input[name="query"]')) document.querySelector('input[name="query"]').value = '';
        if(document.querySelector('input[name="search_date"]')) document.querySelector('input[name="search_date"]').value = '';
        // Reset radio su "Cerca Paziente"
        document.getElementById('typePatient').checked = true;
        document.getElementById('search_by_patient').style.display = '';
        document.getElementById('search_by_date').style.display = 'none';
        // Ricarica la pagina SENZA parametri GET
        window.location.href = window.location.pathname;
    });
    </script>

    <?php
    // Ricerca per paziente
    if ($mode == 'patient' && $query) {
        $patients = searchPatients($conn, $query);
        if ($patients) {
            foreach ($patients as $patient) {
                ?>
                <div class="card patient-card shadow-sm">
                    <div class="card-body">
                        <h5 class="card-title text-success"><?php echo htmlspecialchars($patient['surname'])." ".htmlspecialchars($patient['name']); ?></h5>
                        <div><span class="fw-bold">Telefono:</span> <?php echo htmlspecialchars($patient['phone']); ?></div>
                        <!-- ACTION BTNS START -->
                        <div class="mt-2 action-btns">
                            <?php
                            $appointments = getPatientAppointments($conn, $patient['id']);
                            $allAddresses = [];
                            if ($appointments && count($appointments) > 0) {
                                foreach ($appointments as $app) {
                                    if (!empty($app['address']) && !in_array($app['address'], $allAddresses)) {
                                        $allAddresses[] = $app['address'];
                                    }
                                }
                            }
                            ?>
                            <?php if (count($allAddresses) > 1): ?>
                                <div class="mb-2">
                                    <label class="form-label fw-bold">Seleziona indirizzo appuntamento:</label>
                                    <?php foreach ($allAddresses as $i => $address): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="address_select_<?php echo $patient['id']; ?>" id="address_<?php echo $patient['id'].'_'.$i; ?>" value="<?php echo htmlspecialchars($address); ?>">
                                            <label class="form-check-label" for="address_<?php echo $patient['id'].'_'.$i; ?>">
                                                <?php echo htmlspecialchars($address); ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <form method="GET" action="insert_appointment.php" style="display:inline;">
                                    <input type="hidden" name="name" value="<?php echo htmlspecialchars($patient['name']); ?>">
                                    <input type="hidden" name="surname" value="<?php echo htmlspecialchars($patient['surname']); ?>">
                                    <input type="hidden" name="phone" value="<?php echo htmlspecialchars($patient['phone']); ?>">
                                    <input type="hidden" name="address" id="hidden_address_insert_<?php echo $patient['id']; ?>" value="">
                                    <button type="submit" class="btn btn-outline-primary btn-sm" id="btn_insert_<?php echo $patient['id']; ?>" disabled>Inserisci Appuntamento</button>
                                </form>
                                <form method="GET" action="combined_address_calculate_v2.php" style="display:inline;">
                                    <input type="hidden" name="name" value="<?php echo htmlspecialchars($patient['name']); ?>">
                                    <input type="hidden" name="surname" value="<?php echo htmlspecialchars($patient['surname']); ?>">
                                    <input type="hidden" name="phone" value="<?php echo htmlspecialchars($patient['phone']); ?>">
                                    <input type="hidden" name="address" id="hidden_address_zone_<?php echo $patient['id']; ?>" value="">
                                    <button type="submit" class="btn btn-outline-success btn-sm" id="btn_zone_<?php echo $patient['id']; ?>" disabled>Cerca Data per Zona</button>
                                </form>
                                <script>
                                document.querySelectorAll('input[name="address_select_<?php echo $patient['id']; ?>"]').forEach(function(radio) {
                                    radio.addEventListener('change', function() {
                                        document.getElementById('hidden_address_insert_<?php echo $patient['id']; ?>').value = this.value;
                                        document.getElementById('hidden_address_zone_<?php echo $patient['id']; ?>').value = this.value;
                                        document.getElementById('btn_insert_<?php echo $patient['id']; ?>').disabled = false;
                                        document.getElementById('btn_zone_<?php echo $patient['id']; ?>').disabled = false;
                                    });
                                });
                                </script>
                            <?php else:
                                $addressToPass = isset($allAddresses[0]) ? $allAddresses[0] : "";
                            ?>
                                <form method="GET" action="insert_appointment.php" style="display:inline;">
                                    <input type="hidden" name="name" value="<?php echo htmlspecialchars($patient['name']); ?>">
                                    <input type="hidden" name="surname" value="<?php echo htmlspecialchars($patient['surname']); ?>">
                                    <input type="hidden" name="phone" value="<?php echo htmlspecialchars($patient['phone']); ?>">
                                    <input type="hidden" name="address" value="<?php echo htmlspecialchars($addressToPass); ?>">
                                    <button type="submit" class="btn btn-outline-primary btn-sm">Inserisci Appuntamento</button>
                                </form>
                                <form method="GET" action="combined_address_calculate_v2.php" style="display:inline;">
                                    <input type="hidden" name="name" value="<?php echo htmlspecialchars($patient['name']); ?>">
                                    <input type="hidden" name="surname" value="<?php echo htmlspecialchars($patient['surname']); ?>">
                                    <input type="hidden" name="phone" value="<?php echo htmlspecialchars($patient['phone']); ?>">
                                    <input type="hidden" name="address" value="<?php echo htmlspecialchars($addressToPass); ?>">
                                    <button type="submit" class="btn btn-outline-success btn-sm">Cerca Data per Zona</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <!-- ACTION BTNS END -->
                        <hr>
                        <div><span class="fw-bold">ID Paziente:</span> <?php echo $patient['id']; ?></div>
                        <div class="mb-2"><span class="fw-bold">Storico Appuntamenti:</span></div>
                       <ul class="appointment-list">
<?php
if ($appointments) {
    foreach ($appointments as $app) {
        echo "<li>";
        echo date('d/m/Y', strtotime($app['appointment_date'])) . " " . htmlspecialchars($app['appointment_time']) . " - ";
        echo htmlspecialchars($app['address']);
        if ($app['zone_name']) {
            echo " <span class='badge bg-info text-dark'>Zona: ".htmlspecialchars($app['zone_name'])."</span>";
        }
        // Calcola se l'appuntamento è futuro
        $appDateTime = strtotime($app['appointment_date'] . ' ' . $app['appointment_time']);
        $now = time();
        if ($appDateTime > $now) {
    echo " <a href='manage_appointments.php?highlight_appointment={$app['id']}' 
            class='btn btn-sm btn-outline-info px-2 py-0 ms-2' 
            style='font-size:0.8em;line-height:1.1;vertical-align:baseline;' 
            title='Gestisci Appuntamento'>
            <i class='bi bi-pencil-square'></i> Gestisci appuntamento
        </a>";
}
        echo "</li>";
    }
} else {
    echo "<li>Nessun appuntamento registrato</li>";
}
?>
</ul>
                    </div>
                </div>
                <?php
            }
        } else {
            echo '<div class="alert alert-warning">Nessun paziente trovato.</div>';
        }
    }

    // Ricerca per data appuntamento
    if ($mode == 'date' && $search_date) {
        $apps = searchAppointmentsByDate($conn, $search_date);
        if ($apps) {
            echo "<h4 class='mb-3 text-info'>Appuntamenti per il giorno ".date('d/m/Y', strtotime($search_date))."</h4>";
            foreach ($apps as $app) {
                $nomeCognome = htmlspecialchars($app['name']." ".$app['surname']);
                $querystr = urlencode(trim($app['name']).' '.trim($app['surname']));
                echo "<div class='mb-1'>";
                echo "<a href='?mode=patient&query={$querystr}' class='fw-bold'>{$nomeCognome}</a>";
                echo " <span class='text-muted' style='font-size:0.95em;'>(Tel: ".htmlspecialchars($app['phone']).")</span>";
                echo "</div>";
            }
        } else {
            echo '<div class="alert alert-warning">Nessun appuntamento trovato per questa data.</div>';
        }
    }
    ?>
</div>
<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php ob_end_flush(); ?>
