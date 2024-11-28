<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calcolo Indirizzo</title>
    <link rel="stylesheet" href="styles.css">
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
                    document.getElementById('latitude').value = place.geometry.location.lat();
                    document.getElementById('longitude').value = place.geometry.location.lng();
                    checkAddressInRadius();
                }
            });
        }

        function checkAddressInRadius() {
            const address = document.getElementById('address').value;
            const longitude = document.getElementById('longitude').value;

            const formData = new FormData();
            formData.append('address', address);
            formData.append('longitude', longitude);

            fetch('address_calculate.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text()) // Read response as text first
            .then(text => {
                try {
                    const data = JSON.parse(text); // Attempt to parse JSON
                    if (data.zones_in_radius && data.zones_in_radius.length > 0) {
                        displayZonesInRadius(data.zones_in_radius);
                    } else {
                        displayMessage('No zones within the radius found for this location.');
                    }
                    if (data.debug_info) {
                        displayDebugInfo(data.debug_info);
                    } else {
                        displayMessage('No debug information available.');
                    }
                } catch (error) {
                    console.error('Error parsing JSON:', error);
                    displayMessage('Error parsing JSON: ' + error.message);
                }
            })
            .catch(error => {
                console.error('Error fetching zones:', error);
                displayMessage('Error fetching zones: ' + error.message);
            });
        }

        function displayZonesInRadius(zones) {
            const zoneDetails = document.getElementById('zoneDetails');
            zoneDetails.innerHTML = '<h3>Zones within Radius:</h3>';
            zones.forEach(zone => {
                const zoneDiv = document.createElement('div');
                zoneDiv.textContent = `Zone: ${zone.zone_name}`;
                zoneDetails.appendChild(zoneDiv);
            });
            zoneDetails.style.display = 'block';
        }

        function displayDebugInfo(debugInfo) {
            const debugDetails = document.getElementById('debugDetails');
            debugDetails.innerHTML = '<h3>Debug Information:</h3>';
            debugInfo.forEach(info => {
                const infoDiv = document.createElement('div');
                infoDiv.innerHTML = `
                    <strong>Zone Name:</strong> ${info.zone_name}<br>
                    <strong>Zone Latitude:</strong> ${info.zone_latitude}<br>
                    <strong>Zone Longitude:</strong> ${info.zone_longitude}<br>
                    <strong>Distance:</strong> ${info.distance} km<br>
                    <strong>Radius:</strong> ${info.radius_km} km<br><br>
                `;
                debugDetails.appendChild(infoDiv);
            });
            debugDetails.style.display = 'block';
        }

        function displayMessage(message) {
            const messageContainer = document.getElementById('messageContainer');
            messageContainer.innerHTML = `<p>${message}</p>`;
            messageContainer.style.display = 'block';
        }
    </script>
</head>
<body>
    <div class="container">
        <h2>Calcolo Indirizzo</h2>
        <form>
            <label for="address">Indirizzo:</label>
            <input type="text" id="address" name="address" required><br>

            <input type="hidden" id="latitude" name="latitude">
            <input type="hidden" id="longitude" name="longitude">
        </form>

        <div id="messageContainer" style="display:none;"></div>
        <div id="zoneDetails" style="display:none;"></div>
        <div id="debugDetails" style="display:none;"></div>
    </div>
    <div class="container">
        <a href="dashboard.php">Torna alla dashboard</a>
    </div>
</body>
</html>
