<?php
include 'db.php';
include 'menu.php';

// Function to get all patients and their appointments
function getPatients($search = '') {
    global $conn;
    $conditions = [];
    if (!empty($search)) {
        $conditions[] = "(p.name LIKE '%" . mysqli_real_escape_string($conn, $search) . "%' OR p.surname LIKE '%" . mysqli_real_escape_string($conn, $search) . "%')";
    }
    $sql = "SELECT p.id, p.name, p.surname, p.phone, p.email, a.id AS appointment_id, a.appointment_date, a.appointment_time, a.notes, z.name AS zone
            FROM cp_patients p
            LEFT JOIN cp_appointments a ON p.id = a.patient_id
            LEFT JOIN cp_zones z ON a.zone_id = z.id";
    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(' AND ', $conditions);
    }
    $sql .= " ORDER BY p.id, a.appointment_date";
    $result = mysqli_query($conn, $sql);
    $patients = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $patients[$row['id']]['info'] = [
            'name' => $row['name'],
            'surname' => $row['surname'],
            'phone' => $row['phone'],
            'email' => $row['email']
        ];
        if (!empty($row['appointment_id'])) {
            $patients[$row['id']]['appointments'][] = [
                'appointment_id' => $row['appointment_id'],
                'appointment_date' => $row['appointment_date'],
                'appointment_time' => $row['appointment_time'],
                'notes' => $row['notes'],
                'zone' => $row['zone']
            ];
        }
    }
    return $patients;
}

// Function to update a patient
if (isset($_POST['update'])) {
    $id = $_POST['patient_id'];
    $name = $_POST['name'];
    $surname = $_POST['surname'];
    $phone = $_POST['phone'];
    $email = $_POST['email'];
    $sql = "UPDATE cp_patients
            SET name='$name', surname='$surname', phone='$phone', email='$email'
            WHERE id = $id";
    mysqli_query($conn, $sql);
    header('Location: manage_patients.php');
}

// Function to delete a patient
if (isset($_POST['delete'])) {
    $id = $_POST['patient_id'];
    $sql = "DELETE FROM cp_patients WHERE id = $id";
    mysqli_query($conn, $sql);
    header('Location: manage_patients.php');
}

$search = isset($_GET['search']) ? $_GET['search'] : '';
$patients = getPatients($search);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Patients</title>
    <style>
        .modifica-btn {
            background-color: green;
            color: white;
        }
        .cancella-btn {
            background-color: red;
            color: white;
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('search').addEventListener('input', filterPatients);
            document.getElementById('clear-filters').addEventListener('click', clearFilters);
        });

        function filterPatients() {
            const search = document.getElementById('search').value;

            const xhr = new XMLHttpRequest();
            xhr.open('GET', `manage_patients.php?search=${encodeURIComponent(search)}`, true);
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4 && xhr.status === 200) {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(xhr.responseText, 'text/html');
                    const newTable = doc.querySelector('table');
                    document.querySelector('table').innerHTML = newTable.innerHTML;
                }
            };
            xhr.send();
        }

        function clearFilters() {
            document.getElementById('search').value = '';
            filterPatients();
        }

        function showActions(id) {
            const rows = document.querySelectorAll('tr');
            rows.forEach(function(row) {
                if (!row.classList.contains('action-row') && row.id !== 'action-' + id) {
                    row.style.display = 'none';
                }
            });
            const actionRow = document.getElementById('action-' + id);
            if (actionRow) {
                actionRow.style.display = 'table-row';
                // Hide delete button and change edit button to "Conferma Modifica"
                const modificaBtn = actionRow.querySelector('.modifica-btn');
                if (modificaBtn) {
                    modificaBtn.value = 'Conferma Modifica';
                }
                const cancellaBtns = document.querySelectorAll('.cancella-btn');
                cancellaBtns.forEach(function(btn) {
                    btn.style.display = 'none';
                });
            }
        }
    </script>
</head>
<body>
    <h2>Manage Patients</h2>
    <form onsubmit="return false;">
        <label for="search">Search by Name:</label>
        <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>">
        <button id="clear-filters">Clear Filters</button>
    </form>
    <table border="1">
        <tr>
            <th>Name</th>
            <th>Surname</th>
            <th>Phone</th>
            <th>Email</th>
            <th>Appointments</th>
            <th>Actions</th>
        </tr>
        <?php foreach ($patients as $patient_id => $patient) { ?>
        <tr>
            <td><?php echo htmlspecialchars($patient['info']['name']); ?></td>
            <td><?php echo htmlspecialchars($patient['info']['surname']); ?></td>
            <td><?php echo htmlspecialchars($patient['info']['phone']); ?></td>
            <td><?php echo htmlspecialchars($patient['info']['email']); ?></td>
            <td>
                <?php if (isset($patient['appointments'])) { ?>
                <ul>
                    <?php foreach ($patient['appointments'] as $appointment) { ?>
                    <li><?php echo htmlspecialchars($appointment['appointment_date']) . ' ' . htmlspecialchars($appointment['appointment_time']) . ' (' . htmlspecialchars($appointment['zone']) . ')'; ?> - <?php echo htmlspecialchars($appointment['notes']); ?></li>
                    <?php } ?>
                </ul>
                <?php } else { ?>
                No appointments
                <?php } ?>
            </td>
            <td>
                <button class="modifica-btn" onclick="showActions(<?php echo $patient_id; ?>)">Modifica</button>
                <form method="post" action="manage_patients.php" style="display:inline;">
                    <input type="hidden" name="patient_id" value="<?php echo $patient_id; ?>">
                    <input type="submit" name="delete" value="Cancella" class="cancella-btn">
                </form>
            </td>
        </tr>
        <tr id="action-<?php echo $patient_id; ?>" class="action-row" style="display:none;">
            <td colspan="6">
                <form method="post" action="manage_patients.php" style="display:inline;">
                    <input type="hidden" name="patient_id" value="<?php echo $patient_id; ?>">
                    <input type="text" name="name" value="<?php echo htmlspecialchars($patient['info']['name']); ?>" required>
                    <input type="text" name="surname" value="<?php echo htmlspecialchars($patient['info']['surname']); ?>" required>
                    <input type="text" name="phone" value="<?php echo htmlspecialchars($patient['info']['phone']); ?>" required>
                    <input type="email" name="email" value="<?php echo htmlspecialchars($patient['info']['email']); ?>" required>
                    <input type="submit" name="update" value="Conferma Modifica" class="modifica-btn">
                </form>
            </td>
        </tr>
        <?php } ?>
    </table>
</body>
</html>
