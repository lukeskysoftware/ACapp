<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione delle Zone</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
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
    </script>
</head>
<body>
    <?php include 'menu.php'; ?>
    <div class="container">
        <h2 class="text-center my-4">Crea Zona</h2>
        <form action="create_zone.php" method="post" class="needs-validation" novalidate>
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
                <div class="invalid-feedback">Inserisci il raggio in metri.</div>
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

            <div class="mb-3">
                <label for="start_time" class="form-label">Ora di Inizio:</label>
                <input type="time" id="start_time" name="start_time" class="form-control" required>
                <div class="invalid-feedback">Inserisci l'ora di inizio.</div>
            </div>

            <div class="mb-3">
                <label for="end_time" class="form-label">Ora di Fine:</label>
                <input type="time" id="end_time" name="end_time" class="form-control" required>
                <div class="invalid-feedback">Inserisci l'ora di fine.</div>
            </div>

            <div class="mb-3">
                <label for="duration" class="form-label">Durata dell'Appuntamento (minuti):</label>
                <input type="number" id="duration" name="duration" class="form-control" min="1" required>
                <div class="invalid-feedback">Inserisci la durata dell'appuntamento.</div>
            </div>

            <div class="mb-3">
                <label for="latitude" class="form-label">Latitudine:</label>
                <input type="text" id="latitude" name="latitude" class="form-control" readonly>
            </div>

            <div class="mb-3">
                <label for="longitude" class="form-label">Longitudine:</label>
                <input type="text" id="longitude" name="longitude" class="form-control" readonly>
            </div>

            <button type="submit" class="btn btn-primary text-center mt-4">Crea Zona</button>
        </form>

        <script>
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
        </script>
    </div>
    <div class="text-center mt-4">
        <a href="dashboard.php" class="btn btn-secondary">Torna alla dashboard</a>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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
