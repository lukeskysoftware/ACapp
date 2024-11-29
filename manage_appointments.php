<?php
include 'db.php';

// Function to get all appointments with patient and zone information
function getAppointments($filter = [], $search = '') {
    global $conn;
    $conditions = [];
    if (!empty($filter['date'])) {
        $conditions[] = "a.appointment_date = '" . mysqli_real_escape_string($conn, $filter['date']) . "'";
    }
    if (!empty($filter['zone'])) {
        $conditions[] = "z.name = '" . mysqli_real_escape_string($conn, $filter['zone']) . "'";
    }
    if (!empty($search)) {
        $conditions[] = "(p.name LIKE '%" . mysqli_real_escape_string($conn, $search) . "%' OR p.surname LIKE '%" . mysqli_real_escape_string($conn, $search) . "%')";
    }
    $sql = "SELECT a.id, p.name, p.surname, p.phone, a.notes, a.appointment_date, a.appointment_time, a.address, z.name as zone
            FROM cp_appointments a
            JOIN cp_patients p ON a.patient_id = p.id
            JOIN cp_zones z ON a.zone_id = z.id";
    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(' AND ', $conditions);
    }
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        die('Error: ' . mysqli_error($conn));
    }
    $appointments = mysqli_fetch_all($result, MYSQLI_ASSOC);
    return $appointments;
}

// Function to get distinct zones
function getZones() {
    global $conn;
    $sql = "SELECT DISTINCT z.name FROM cp_appointments a JOIN cp_zones z ON a.zone_id = z.id";
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        die('Error: ' . mysqli_error($conn));
    }
    $zones = mysqli_fetch_all($result, MYSQLI_ASSOC);
    return array_column($zones, 'name');
}

// Function to update an appointment
if (isset($_POST['update'])) {
    $id = $_POST['appointment_id'];
    $name = $_POST['name'];
    $surname = $_POST['surname'];
    $phone = $_POST['phone'];
    $notes = $_POST['notes'];
    $appointment_date = $_POST['appointment_date'];
    $appointment_time = $_POST['appointment_time'];
    $address = $_POST['address'];
    $sql = "UPDATE cp_appointments a
            JOIN cp_patients p ON a.patient_id = p.id
            SET p.name='$name', p.surname='$surname', p.phone='$phone', a.notes='$notes', a.appointment_date='$appointment_date', a.appointment_time='$appointment_time', a.address='$address'
            WHERE a.id = $id";
    mysqli_query($conn, $sql);
    header('Location: manage_appointments.php');
}

// Function to delete an appointment
if (isset($_POST['delete_confirm'])) {
    $id = $_POST['appointment_id'];
    $sql = "DELETE FROM cp_appointments WHERE id = $id";
    mysqli_query($conn, $sql);
    header('Location: manage_appointments.php');
}

$filter = [
    'date' => isset($_GET['date']) ? $_GET['date'] : '',
    'zone' => isset($_GET['zone']) ? $_GET['zone'] : '',
];
$search = isset($_GET['search']) ? $_GET['search'] : '';
$appointments = getAppointments($filter, $search);
$zones = getZones();
$showTable = !empty($appointments);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Gestione Appuntamenti</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/purecss@3.0.0/build/pure-min.css" integrity="sha384-X38yfunGUhNzHpBaEBsWLO+A0HDYOQi8ufWDkZ0k9e0eXz/tH3II7uKZ9msv++Ls" crossorigin="anonymous">
    <link rel="stylesheet" href="styles.css">
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
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('date').addEventListener('change', filterAppointments);
            document.getElementById('zone').addEventListener('input', filterAppointments);
            document.getElementById('search').addEventListener('input', filterAppointments);
            document.getElementById('clear-filters').addEventListener('click', clearFilters);
        });

        function filterAppointments() {
            const date = document.getElementById('date').value;
            const zone = document.getElementById('zone').value;
            const search = document.getElementById('search').value;

            const xhr = new XMLHttpRequest();
            xhr.open('GET', `manage_appointments.php?date=${encodeURIComponent(date)}&zone=${encodeURIComponent(zone)}&search=${encodeURIComponent(search)}`, true);
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4 && xhr.status === 200) {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(xhr.responseText, 'text/html');
                    const newTable = doc.querySelector('table');
                    const appointmentsMessage = doc.querySelector('#no-appointments-message');
                    
                    const tableElement = document.querySelector('table');
                    if (newTable && newTable.querySelector('tbody').children.length > 0) {
                        tableElement.innerHTML = newTable.innerHTML;
                        tableElement.classList.remove('hidden');
                        appointmentsMessage.classList.add('hidden');
                    } else {
                        tableElement.classList.add('hidden');
                        appointmentsMessage.classList.remove('hidden');
                    }
                }
            };
            xhr.send();
        }

        function clearFilters() {
            document.getElementById('date').value = '';
            document.getElementById('zone').value = '';
            document.getElementById('search').value = '';
            filterAppointments();
        }

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
            if (confirm(`Sei sicuro di voler cancellare l'appuntamento in zona ${appointment.zone} ${appointment.address} con ${appointment.name} ${appointment.surname} ${appointment.phone} ${appointment.notes}?`)) {
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
    </script>
</head>
<body>
    <?php include 'menu.php'; ?>
    <div class="pure-g aria">
    <h2 class="centrato">Gestione Appuntamenti</h2>
    </div>
    <div class="pure-g aria">
    <form onsubmit="return false;" class="pure-form centrato aria">
        <label for="date">Filtra per Data:</label>
        <input type="date" id="date" name="date" value="<?php echo htmlspecialchars($filter['date']); ?>">
        <label for="zone">Filtra per Zona:</label>
        <select id="zone" name="zone">
            <option value="">Seleziona Zona</option>
            <?php foreach ($zones as $zone) { ?>
                <option value="<?php echo htmlspecialchars($zone); ?>"><?php echo htmlspecialchars($zone); ?></option>
            <?php } ?>
        </select>
        <label for="search">Cerca per Nome:</label>
        <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>">
        <button id="clear-filters" class="pure-button button-small">Cancella Filtri</button>
    </form>
    
    <p id="no-appointments-message" class="<?php echo $showTable ? 'hidden' : ''; ?> centrato aria" >Non ci sono appuntamenti</p>
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
            <td><?php echo htmlspecialchars($appointment['notes']); ?></td>
            <td><?php echo date('d/m/Y', strtotime($appointment['appointment_date'])); ?></td>
            <td><?php echo htmlspecialchars($appointment['appointment_time']); ?></td>
            <td><?php echo htmlspecialchars($appointment['address']); ?></td>
            <td><?php echo htmlspecialchars($appointment['zone']); ?></td>
            <td>
                <button class="modifica-btn pure-button button-small button-green" onclick="showActions(<?php echo $appointment['id']; ?>)">Modifica</button>
                <button class="cancella-btn pure-button buttton-small button-red" id="delete-btn-<?php echo $appointment['id']; ?>" onclick="confirmDelete(<?php echo htmlspecialchars(json_encode($appointment)); ?>)">Cancella</button>
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
                    <input type="text" name="name" value="<?php echo htmlspecialchars($appointment['name']); ?>" required>
                    <input type="text" name="surname" value="<?php echo htmlspecialchars($appointment['surname']); ?>" required>
                    <input type="text" name="phone" value="<?php echo htmlspecialchars($appointment['phone']); ?>" required>
                    <input type="text" name="address" value="<?php echo htmlspecialchars($appointment['address']); ?>" required>
                    <input type="text" name="notes" value="<?php echo htmlspecialchars($appointment['notes']); ?>">
                    <!--<input type="date" name="appointment_date" value="<?php echo htmlspecialchars($appointment['appointment_date']); ?>" required>
                    <input type="time" name="appointment_time" value="<?php echo htmlspecialchars($appointment['appointment_time']); ?>" required> -->
                    <input type="submit" name="update" value="Conferma Modifica" class="modifica-btn pure-button button-small button-green">
                    <button type="button" class="chiudi-btn pure-button button-small" onclick="hideActions(<?php echo $appointment['id']; ?>)">Chiudi</button>
                </form>
            </td>
        </tr>
        <?php } ?>
        </tbody>
    </table>
    </div>
</body>
</html>
