<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
}
?>

<nav>
    <ul>
        <li><a href="create_zone_form.html">Create Zone</a></li>
        <li><a href="edit_zone_form.html">Edit Zone</a></li> <!-- Added Edit Zone link -->
        <li><a href="manage_appointments.php">Manage Appointments</a></li>
        <li><a href="book_appointment_form.html">Book Appointment</a></li>
        <li><a href="view_appointments.php">View Appointments</a></li>
        <li><a href="logout.php">Logout</a></li>
    </ul>
</nav>
