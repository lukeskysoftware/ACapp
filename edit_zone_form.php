<?php
include 'db.php';
include 'menu.php';
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modifica Zona</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css">
    <script>
        async function loadAPIKey() {
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
                console.error('Error fetching API key:', error);
            }
        }

        window.addEventListener('load', loadAPIKey);

        function loadZoneData(zoneId) {
            fetch(`get_zone_data.php?zone_id=${zoneId}`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('zone_id').value = data.id;
                    document.getElementById('zone_name').value = data.name;
                    document.getElementById('zone_address').value = data.address;
                    document.getElementById('latitude').value = data.latitude;
                    document.getElementById('longitude').value = data.longitude;
                    document.getElementById('radius').value = data.radius_km;
                    const daysSelect = document.getElementById('days');
                    const slotsContainer = document.getElementById('slots_container');

                    // Clear existing slots
                    slotsContainer.innerHTML = '';

                    // Populate slots
                    data.slots.forEach(slot => {
                        const dayOption = Array.from(daysSelect.options).find(option => option.value === slot.day);
                        if (dayOption) {
                            dayOption.selected = true;
                        }

                        const slotDiv = document.createElement('div');
                        slotDiv.classList.add('mb-3');
                        slotDiv.innerHTML = `
                            <label for="slot_${slot.day}_${slot.time}">Orario per ${slot.day}:</label>
                            <input type="time" id="slot_${slot.day}_${slot.time}" name="slots[${slot.day}][]" value="${slot.time}" class="form-control" required>
                        `;
                        slotsContainer.appendChild(slotDiv);
                    });
                })
                .catch(error => console.error('Error fetching zone data:', error));
        }

        function initAutocomplete() {
            var input = document.getElementById('zone_address');
            var options = {
                types: ['geocode'],
                strictBounds: true,
                bounds: {
                    north: 42.1,
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

        function getParameterByName(name) {
            const url = window.location.href;
            const nameRegex = name.replace(/[\[\]]/g, '\\$&');
            const regex = new RegExp('[?&]' + name + '(=([^&#]*)|&|#|$)');
            const results = regex.exec(url);
            if (!results) return null;
            if (!results[2]) return '';
            return decodeURIComponent(results[2].replace(/\+/g, ' '));
        }

        function validateForm() {
            const days = document.getElementById('days').selectedOptions;

            if (days.length === 0) {
                alert("Tutti i campi devono essere compilati.");
                return false;
            }

            return true;
        }
    </script>
</head>
<body onload="loadZoneData(getParameterByName('zone_id'));">
    <div class="container">
        <h2 class="text-center my-4">Modifica Zona</h2>
        <form action="edit_zone.php" method="post" onsubmit="return validateForm();" class="needs-validation" novalidate>
            <input type="hidden" id="zone_id" name="zone_id">
            
            <div class="mb-3">
                <label for="zone_name" class="form-label">Nome della Zona:</label>
                <input type="text" id="zone_name" name="name" class="form-control" required>
                <div class="invalid-feedback">Inserisci il nome della zona.</div>
            </div>

            <div class="mb-3">
                <label for="zone_address" class="form-label">Indirizzo della Zona:</label>
                <input type="text" id="zone_address" name="address" class="form-control" required>
                <div class="invalid-feedback">Inserisci l'indirizzo della zona.</div>
            </div>

            <div class="mb-3">
                <label for="radius" class="form-label">Raggio (km):</label>
                <input type="number" id="radius" name="radius_km" class="form-control" required>
                <div class="invalid-feedback">Inserisci il raggio in km.</div>
            </div>

            <div class="mb-3">
                <label for="days" class="form-label">Giorni della Settimana:</label>
                <select id="days" name="days[]" class="form-select" multiple required>
                    <option value="Monday">Lunedì</option>
                    <option value="Tuesday">Martedì</option>
                    <option value="Wednesday">Mercoledì</option>
                    <option value="Thursday">Giovedì</option>
                    <option value="Friday">Venerdì</option>
                    <option value="Saturday">Sabato</option>
                    <option value="Sunday">Domenica</option>
                </select>
                <div class="invalid-feedback">Seleziona almeno un giorno.</div>
            </div>

            <div id="slots_container" class="mb-3">
                <!-- Slots will be dynamically populated here -->
            </div>

            <div class="mb-3">
                <label for="latitude" class="form-label">Latitudine:</label>
                <input type="text" id="latitude" name="latitude" class="form-control" readonly>
            </div>

            <div class="mb-3">
                <label for="longitude" class="form-label">Longitudine:</label>
                <input type="text" id="longitude" name="longitude" class="form-control" readonly>
            </div>

            <button type="submit" class="btn btn-primary">Aggiorna Zona</button>
        </form>
        <div class="text-center mt-4">
            <a href="dashboard.php" class="btn btn-secondary">Torna alla dashboard</a>
        </div>
    </div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        (function () {
            'use strict'

            var forms = document.querySelectorAll('.needs-validation')

            Array.prototype.slice.call(forms)
                .forEach(function (form) {
                    form.addEventListener('submit', function (event) {
                        if (!form.checkValidity()) {
                            event.preventDefault()
                            event.stopPropagation()
                        }

                        form.classList.add('was-validated')
                    }, false)
                })
        })()
    </script>
</body>
</html>