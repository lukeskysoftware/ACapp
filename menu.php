<?php
// Start output buffering to prevent any output before headers are sent
ob_start();
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit(); // Ensure the script stops executing after the redirect
}
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

<nav class="navbar navbar-expand-lg navbar-light bg-light">
    <div class="container-fluid">
        <a class="navbar-brand" href="#">ACapp</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav">
                <li class="nav-item">
                    <a class="nav-link" href="create_zone_form.html">Crea Zona</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="list_zones.php">Gestisci Zone</a> <!-- Updated to point to list_zones.php -->
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="address_calculate.html">Prenota Appuntamento</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="manage_appointments.php">Gestisci Appuntamenti</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="insert_appointment.php">Inserisci Appuntamento</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="view_appointments.php">Vedi Appuntamenti</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="logout.php">Logout</a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<?php
// End output buffering and flush the output
ob_end_flush();
?>
