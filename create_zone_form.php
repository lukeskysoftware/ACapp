<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione delle Zone</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/purecss@3.0.0/build/pure-min.css">
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
    <div class="pure-menu pure-menu-horizontal">
        <!-- <nav>
            <ul class="pure-menu-list">
                <li class="pure-menu-item"><a class="pure-menu-link" href="create_zone_form.php">Crea Zona</a></li>
                <li class="pure-menu-item"><a class="pure-menu-link" href="view_zones.php">Visualizza Zone</a></li>
            </ul>
        </nav> -->
    </div>
    <div class="pure-g aria">
        <h2 class="centrato centro">Crea Zona</h2>
    </div>
    <div class="pure-g aria">
        <form action="create_zone.php" method="post" class="pure-form pure-form-aligned centrato centro">
            <label for="zone_name">Nome della Zona:</label>
            <input type="text" id="zone_name" name="name" required><br>

            <label for="zone_address">Indirizzo della Zona:</label>
            <input type="text" id="zone_address" name="address" required><br>

            <label for="radius">Raggio (metri):</label>
            <input type="number" id="radius" name="radius_km" required><br>

            <label for="days">Giorni della Settimana:</label>
            <select id="days" name="days[]" multiple required>
                <option value="Monday">Lunedì</option>
                <option value="Tuesday">Martedì</option>
                <option value="Wednesday">Mercoledì</option>
                <option value="Thursday">Giovedì</option>
                <option value="Friday">Venerdì</option>
                <option value="Saturday">Sabato</option>
                <option value="Sunday">Domenica</option>
            </select><br>

            <label for="start_time">Ora di Inizio:</label>
            <input type="time" id="start_time" name="start_time" required><br>

            <label for="end_time">Ora di Fine:</label>
            <input type="time" id="end_time" name="end_time" required><br>

            <label for="duration">Durata dell'Appuntamento (minuti):</label>
            <input type="number" id="duration" name="duration" min="1" required><br>

            <label for="latitude">Latitudine:</label>
            <input type="text" id="latitude" name="latitude" readonly><br>

            <label for="longitude">Longitudine:</label>
            <input type="text" id="longitude" name="longitude" readonly><br>

            <button type="submit" class="pure-button button-green button-small">Crea Zona</button>
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
    <div class="pure-g aria centrato centro">
        <a class="centrato centro" href="dashboard.php">Torna alla dashboard</a>
    </div>
</body>
</html>