<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'db.php';
include 'menu.php';
include 'utils_appointment.php';

// --- INCLUDES MODULARI ---
include 'includes/distance.php';
include 'includes/appointments.php';
include 'includes/ui_modes.php';
include 'includes/ui_results.php';

// Modalità distanza elastica (7/10/15km)
$distance_mode = get_distance_mode();
show_distance_mode_buttons($distance_mode);

// GESTIONE FORM POST
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['address'])) {
    $address = $_POST['address'];
    $latitude = $_POST['latitude'];
    $longitude = $_POST['longitude'];
    $name = isset($_POST['name']) ? $_POST['name'] : '';
    $surname = isset($_POST['surname']) ? $_POST['surname'] : '';
    $phone = isset($_POST['phone']) ? $_POST['phone'] : '';

    $nearby_appointments = findNearbyAppointmentsElastic($address, $latitude, $longitude, $distance_mode);
    render_nearby_appointments($nearby_appointments, $distance_mode);
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Calcolo Indirizzo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .container { max-width: 600px; margin: 0 auto; padding: 20px; text-align: center; }
    </style>
    <script>
    // Carica la chiave API dal backend (get_api_key.php) e Google Maps con Places
    async function loadAPIKeyAndGoogleMaps() {
        try {
            const response = await fetch('get_api_key.php');
            const data = await response.json();
            const apiKey = data.api_key;
            const script = document.createElement('script');
            script.src = `https://maps.googleapis.com/maps/api/js?key=${apiKey}&libraries=places&language=it`;
            script.async = true;
            script.onload = initAutocomplete;
            document.head.appendChild(script);
        } catch (error) {
            alert('Errore caricamento chiave API Google Maps');
        }
    }
    window.addEventListener('DOMContentLoaded', loadAPIKeyAndGoogleMaps);

    function initAutocomplete() {
        var input = document.getElementById('address');
        var options = {
            types: ['geocode'],
            strictBounds: true,
            bounds: {
                north: 42.1,  // Modifica questi valori per la tua area
                south: 40.8,
                west: 11.5,
                east: 13.0
            }
        };
        var autocomplete = new google.maps.places.Autocomplete(input, options);

        autocomplete.addListener('place_changed', function() {
            var place = autocomplete.getPlace();
            if (place.geometry) {
                document.getElementById('latitude').value = place.geometry.location.lat();
                document.getElementById('longitude').value = place.geometry.location.lng();
            }
        });
    }
    </script>
</head>
<body>
<div class="container">
    <h2>A quale indirizzo fare la visita?</h2>
    <form id="addressForm" method="POST" action="magic_address_calculate_V2.php" class="mb-4">
        <div class="mb-3">
            <label for="address" class="form-label fw-bold">Indirizzo:</label>
            <input type="text" id="address" name="address" class="form-control" required autocomplete="off">
        </div>
        <div class="mb-3">
            <label for="latitude" class="form-label fw-bold">Latitudine:</label>
            <input type="text" id="latitude" name="latitude" class="form-control" readonly>
        </div>
        <div class="mb-3">
            <label for="longitude" class="form-label fw-bold">Longitudine:</label>
            <input type="text" id="longitude" name="longitude" class="form-control" readonly>
        </div>
        <input type="hidden" id="name" name="name" value="">
        <input type="hidden" id="surname" name="surname" value="">
        <input type="hidden" id="phone" name="phone" value="">
        <button type="submit" class="btn btn-primary">Avanti</button>
    </form>
</div>
</body>
</html>