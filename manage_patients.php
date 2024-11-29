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
    $sql = "SELECT p.id, p.name, p.surname, p.phone
            FROM cp_patients p";
    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(' AND ', $conditions);
    }
    $sql .= " ORDER BY p.id";
    $result = mysqli_query($conn, $sql);

    // Debugging: Log SQL query and result
    error_log("SQL Query: ".$sql);
    if (!$result) {
        error_log("SQL Error: " . mysqli_error($conn));
        return [];
    }

    $patients = mysqli_fetch_all($result, MYSQLI_ASSOC);
    return $patients;
}

// Function to get appointments for a specific patient
function getAppointments($patient_id) {
    global $conn;
    $sql = "SELECT a.id AS appointment_id, a.appointment_date, a.appointment_time, a.notes, z.name AS zone, z.address AS zone_address
            FROM cp_appointments a
            LEFT JOIN cp_zones z ON a.zone_id = z.id
            WHERE a.patient_id = $patient_id";
    $result = mysqli_query($conn, $sql);
    $appointments = mysqli_fetch_all($result, MYSQLI_ASSOC);
    return $appointments;
}

// Function to update a patient
if (isset($_POST['update'])) {
    $id = $_POST['patient_id'];
    $name = $_POST['name'];
    $surname = $_POST['surname'];
    $phone = $_POST['phone'];
    $sql = "UPDATE cp_patients
            SET name='$name', surname='$surname', phone='$phone'
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/purecss@3.0.0/build/pure-min.css" integrity="sha384-X38yfunGUhNzHpBaEBsWLO+A0HDYOQi8ufWDkZ0k9e0eXz/tH3II7uKZ9msv++Ls" crossorigin="anonymous">
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
            <th>Appointments</th>
            <th>Actions</th>
        </tr>
        <?php if (!empty($patients)) { ?>
            <?php foreach ($patients as $patient) { ?>
            <tr>
                <td><?php echo htmlspecialchars($patient['name']); ?></td>
                <td><?php echo htmlspecialchars($patient['surname']); ?></td>
                <td><?php echo htmlspecialchars($patient['phone']); ?></td>
                <td>
                    <?php
                    $appointments = getAppointments($patient['id']);
                    if (!empty($appointments)) { ?>
                    <ul>
                        <?php foreach ($appointments as $appointment) { ?>
                        <li><?php echo htmlspecialchars($appointment['appointment_date']) . ' ' . htmlspecialchars($appointment['appointment_time']) . ' (' . htmlspecialchars($appointment['zone']) . ' - ' . htmlspecialchars($appointment['zone_address']) . ')'; ?> - <?php echo htmlspecialchars($appointment['notes']); ?></li>
                        <?php } ?>
                    </ul>
                    <?php } else { ?>
                    No appointments
                    <?php } ?>
                </td>
                <td>
                    <button class="modifica-btn" onclick="showActions(<?php echo $patient['id']; ?>)">Modifica</button>
                    <form method="post" action="manage_patients.php" style="display:inline;">
                        <input type="hidden" name="patient_id" value="<?php echo $patient['id']; ?>">
                        <input type="submit" name="delete" value="Cancella" class="cancella-btn">
                    </form>
                </td>
            </tr>
            <tr id="action-<?php echo $patient['id']; ?>" class="action-row" style="display:none;">
                <td colspan="6">
                    <form method="post" action="manage_patients.php" style="display:inline;">
                        <input type="hidden" name="patient_id" value="<?php echo $patient['id']; ?>">
                        <input type="text" name="name" value="<?php echo htmlspecialchars($patient['name']); ?>" required>
                        <input type="text" name="surname" value="<?php echo htmlspecialchars($patient['surname']); ?>" required>
                        <input type="text" name="phone" value="<?php echo htmlspecialchars($patient['phone']); ?>" required>
                        <input type="submit" name="update" value="Conferma Modifica" class="modifica-btn">
                    </form>
                </td>
            </tr>
            <?php } ?>
        <?php } else { ?>
            <tr>
                <td colspan="5">No patients found</td>
            </tr>
        <?php } ?>
    </table>
</body>
</html>
