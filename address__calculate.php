<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calcolo Indirizzo</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/purecss@3.0.0/build/pure-min.css" integrity="sha384-X38yfunGUhNzHpBaEBsWLO+A0HDYOQi8ufWDkZ0k9e0eXz/tH3II7uKZ9msv++Ls" crossorigin="anonymous">
    <link rel="stylesheet" href="styles.css">
    <style>
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            text-align: center;
        }
        .menu {
            margin-bottom: 20px;
        }
    </style>
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
                displayMessage('Error fetching API key: ' + error.message);
            }
        }

        window.addEventListener('load', loadAPIKey);

        function initAutocomplete() {
            var input = document.getElementById('address');
            var autocomplete = new google.maps.places.Autocomplete(input, { types: ['geocode'] });

            autocomplete.addListener('place_changed', function() {
                var place = autocomplete.getPlace();
                if (place.geometry) {
                    var lat = place.geometry.location.lat();
                    var lng = place.geometry.location.lng();
                    document.getElementById('latitude').value = lat;
                    document.getElementById('longitude').value = lng;
                    displayCoordinates(lat, lng);
                }
            });
        }

        function displayCoordinates(lat, lng) {
            document.getElementById('coordinates').innerText = `Latitudine: ${lat}, Longitudine: ${lng}`;
        }

        function displayMessage(message) {
            const messageContainer = document.getElementById('messageContainer');
            messageContainer.innerHTML = `<p>${message}</p>`;
            messageContainer.style.display = 'block';
        }
    </script>
</head>
<body>
    <div class="menu">
        <?php include 'menu.php'; ?>
    </div>
    <div class="container">
        <h2>A quale indirizzo fare la visita?</h2>
        <form id="addressForm" method="POST" action="address_calculate.php" class="pure-form pure-form-stacked">
            <label for="address">Indirizzo:</label>
            <input type="text" id="address" name="address" required><br>
            <label for="latitude">Latitudine:</label>
            <input type="text" id="latitude" name="latitude" readonly><br>
            <label for="longitude">Longitudine:</label>
            <input type="text" id="longitude" name="longitude" readonly><br>
            <button type="submit" class="pure-button pure-button-primary">Avanti</button>
        </form>
        <div id="coordinates" style="margin-top: 10px;"></div>
        <div id="messageContainer" style="display:none;"></div>
        <a href="dashboard.php">Torna alla dashboard</a>
    </div>
</body>
</html>