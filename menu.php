<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
}
?>
<div class="pure-menu pure-menu-horizontal">
<nav>
    <ul class="pure-menu-list">
        <li class="pure-menu-item"><a class="pure-menu-link" href="create_zone_form.html">Crea Zona</a></li>
        <li class="pure-menu-item"><a class="pure-menu-link" href="list_zones.php">Gestisci Zone</a></li> <!-- Updated to point to list_zones.php -->
        <li class="pure-menu-item"><a class="pure-menu-link" href="book_appointment_form.php">Prenota Appuntamento</a></li>
        <li class="pure-menu-item"><a class="pure-menu-link" href="manage_appointments.php">Gestisci Appuntamenti</a></li>
        
        <li class="pure-menu-item"><a class="pure-menu-link" href="view_appointments.php">Vedi Appuntamenti</a></li>
        <li class="pure-menu-item"><a class="pure-menu-link" href="logout.php">Logout</a></li>
    </ul>
</nav>
</div>
