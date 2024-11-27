<?php
include 'db.php';
include 'menu.php';

// Funzione per ottenere tutti gli appuntamenti
function getAppointments() {
    global $conn;
    $sql = "SELECT * FROM cp_appointments";
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
    $phone = $_POST['phone'];
    $notes = $_POST['notes'];
    $appointment_date = $_POST['appointment_date'];
    $appointment_time = $_POST['appointment_time'];
    $sql = "UPDATE cp_appointments SET name='$name', phone='$phone', notes='$notes', appointment_date='$appointment_date', appointment_time='$appointment_time' WHERE id = $id";
    mysqli_query($conn, $sql);
    header('Location: manage_appointments.php');
}

$appointments = getAppointments();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Appointments</title>
</head>
<body>
    <h2>Manage Appointments</h2>
    <table border="1">
        <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Phone</th>
            <th>Notes</th>
            <th>Appointment Date</th>
            <th>Appointment Time</th>
            <th>Actions</th>
        </tr>
        <?php foreach ($appointments as $appointment) { ?>
        <tr>
            <td><?php echo $appointment['id']; ?></td>
            <td><?php echo $appointment['name']; ?></td>
            <td><?php echo $appointment['phone']; ?></td>
            <td><?php echo $appointment['notes']; ?></td>
            <td><?php echo $appointment['appointment_date']; ?></td>
            <td><?php echo $appointment['appointment_time']; ?></td>
            <td>
                <form method="post" action="manage_appointments.php">
                    <input type="hidden" name="appointment_id" value="<?php echo $appointment['id']; ?>">
                    <input type="submit" name="delete" value="Delete">
                </form>
                <form method="post" action="manage_appointments.php">
                    <input type="hidden" name="appointment_id" value="<?php echo $appointment['id']; ?>">
                    <input type="text" name="name" value="<?php echo $appointment['name']; ?>" required>
                    <input type="text" name="phone" value="<?php echo $appointment['phone']; ?>" required>
                    <input type="text" name="notes" value="<?php echo $appointment['notes']; ?>">
                    <input type="date" name="appointment_date" value="<?php echo $appointment['appointment_date']; ?>" required>
                    <input type="time" name="appointment_time" value="<?php echo $appointment['appointment_time']; ?>" required>
                    <input type="submit" name="update" value="Update">
                </form>
            </td>
        </tr>
        <?php } ?>
    </table>
</body>
</html>
