<?php
include 'db.php';
include 'menu.php';

// Funzione per ottenere tutti gli appuntamenti con informazioni sul paziente e la zona
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
    $sql = "SELECT a.id, p.name, p.surname, p.phone, a.notes, a.appointment_date, a.appointment_time, z.name as zone
            FROM cp_appointments a
            JOIN cp_patients p ON a.patient_id = p.id
            JOIN cp_zones z ON a.zone_id = z.id";
    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(' AND ', $conditions);
    }
    $result = mysqli_query($conn, $sql);
    $appointments = mysqli_fetch_all($result, MYSQLI_ASSOC);
    return $appointments;
}

// Funzione per eliminare un appuntamento
if (isset($_POST['delete'])) {
    $id = $_POST['appointment_id'];
    $sql = "DELETE FROM cp_appointments WHERE id = $id";
    mysqli_query($conn, $sql);
    header('Location: manage_appointments.php');
}

// Funzione per aggiornare un appuntamento
if (isset($_POST['update'])) {
    $id = $_POST['appointment_id'];
    $name = $_POST['name'];
    $surname = $_POST['surname'];
    $phone = $_POST['phone'];
    $notes = $_POST['notes'];
    $appointment_date = $_POST['appointment_date'];
    $appointment_time = $_POST['appointment_time'];
    $sql = "UPDATE cp_appointments a
            JOIN cp_patients p ON a.patient_id = p.id
            SET p.name='$name', p.surname='$surname', p.phone='$phone', a.notes='$notes', a.appointment_date='$appointment_date', a.appointment_time='$appointment_time'
            WHERE a.id = $id";
    mysqli_query($conn, $sql);
    header('Location: manage_appointments.php');
}

$filter = [
    'date' => isset($_GET['date']) ? $_GET['date'] : '',
    'zone' => isset($_GET['zone']) ? $_GET['zone'] : '',
];
$search = isset($_GET['search']) ? $_GET['search'] : '';
$appointments = getAppointments($filter, $search);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Appointments</title>
    <script>
        function showActions(id) {
            var rows = document.querySelectorAll('.action-row');
            rows.forEach(function(row) {
                row.style.display = 'none';
            });
            var row = document.getElementById('action-' + id);
            if (row) {
                row.style.display = 'table-row';
            }
        }
    </script>
</head>
<body>
    <h2>Manage Appointments</h2>
    <form method="get" action="manage_appointments.php">
        <label for="date">Filter by Date:</label>
        <input type="date" id="date" name="date" value="<?php echo htmlspecialchars($filter['date']); ?>">
        <label for="zone">Filter by Zone:</label>
        <input type="text" id="zone" name="zone" value="<?php echo htmlspecialchars($filter['zone']); ?>">
        <label for="search">Search by Name:</label>
        <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>">
        <button type="submit">Apply</button>
    </form>
    <table border="1">
        <tr>
            <th>Name</th>
            <th>Surname</th>
            <th>Phone</th>
            <th>Notes</th>
            <th>Appointment Date</th>
            <th>Appointment Time</th>
            <th>Zone</th>
            <th>Actions</th>
        </tr>
        <?php foreach ($appointments as $appointment) { ?>
        <tr onclick="showActions(<?php echo $appointment['id']; ?>)">
            <td><?php echo htmlspecialchars($appointment['name']); ?></td>
            <td><?php echo htmlspecialchars($appointment['surname']); ?></td>
            <td><?php echo htmlspecialchars($appointment['phone']); ?></td>
            <td><?php echo htmlspecialchars($appointment['notes']); ?></td>
            <td><?php echo htmlspecialchars($appointment['appointment_date']); ?></td>
            <td><?php echo htmlspecialchars($appointment['appointment_time']); ?></td>
            <td><?php echo htmlspecialchars($appointment['zone']); ?></td>
            <td>Click to show actions</td>
        </tr>
        <tr id="action-<?php echo $appointment['id']; ?>" class="action-row" style="display:none;">
            <td colspan="8">
                <form method="post" action="manage_appointments.php" style="display:inline;">
                    <input type="hidden" name="appointment_id" value="<?php echo $appointment['id']; ?>">
                    <input type="submit" name="delete" value="Delete">
                </form>
                <form method="post" action="manage_appointments.php" style="display:inline;">
                    <input type="hidden" name="appointment_id" value="<?php echo $appointment['id']; ?>">
                    <input type="text" name="name" value="<?php echo htmlspecialchars($appointment['name']); ?>" required>
                    <input type="text" name="surname" value="<?php echo htmlspecialchars($appointment['surname']); ?>" required>
                    <input type="text" name="phone" value="<?php echo htmlspecialchars($appointment['phone']); ?>" required>
                    <input type="text" name="notes" value="<?php echo htmlspecialchars($appointment['notes']); ?>">
                    <input type="date" name="appointment_date" value="<?php echo htmlspecialchars($appointment['appointment_date']); ?>" required>
                    <input type="time" name="appointment_time" value="<?php echo htmlspecialchars($appointment['appointment_time']); ?>" required>
                    <input type="submit" name="update" value="Update">
                </form>
            </td>
        </tr>
        <?php } ?>
    </table>
</body>
</html>
