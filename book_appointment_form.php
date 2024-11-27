
<!DOCTYPE html>
<html>
<head>
    <title>Book Appointment</title>
    <?php include 'config.php'; ?>
    <?php include 'menu.php'; ?>

    <script>
        async function loadAPIKey() {
            try {
                const response = await fetch('get_api_key.php');
                if (!response.ok) throw new Error('Failed to load API key');
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

        document.addEventListener('DOMContentLoaded', function() {
            loadAPIKey();
        });

        function initAutocomplete() {
            const input = document.getElementById('address');
            const options = { types: ['geocode'] };
            const autocomplete = new google.maps.places.Autocomplete(input, options);

            autocomplete.addListener('place_changed', function() {
                const place = autocomplete.getPlace();
                if (place.geometry) {
                    const latitude = place.geometry.location.lat();
                    const longitude = place.geometry.location.lng();
                    document.getElementById('latitude').value = latitude;
                    document.getElementById('longitude').value = longitude;
                    fetchZones(latitude, longitude);
                }
            });
        }

        function fetchZones(latitude, longitude) {
            const formData = new FormData();
            formData.append('latitude', latitude);
            formData.append('longitude', longitude);

            fetch('fetch_zones.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.zones && data.zones.length > 0) {
                    displayZones(data.zones);
                } else {
                    alert('No zones found for this location.');
                }
            })
            .catch(error => {
                console.error('Error fetching zones:', error);
                alert('Error fetching zones: ' + error.message);
            });
        }

        function displayZones(zones) {
            const zoneDetails = document.getElementById('zoneDetails');
            zoneDetails.innerHTML = '<h3>Zones for the Location:</h3>';
            zones.forEach(zone => {
                const zoneDiv = document.createElement('div');
                zoneDiv.textContent = `Zone: ${zone.zone_name}`;
                zoneDiv.innerHTML += `<br>Next available times: <br>`;
                zone.next_available_times.forEach(time => {
                    zoneDiv.innerHTML += `<a href="#" onclick="selectSlot('${zone.zone_id}', '${time}')">${time}</a><br>`;
                });
                zoneDetails.appendChild(zoneDiv);
            });
            zoneDetails.style.display = 'block';
        }

        function selectSlot(zoneId, time) {
            document.getElementById('zone_id').value = zoneId;
            document.getElementById('selectedSlot').value = time;
            document.getElementById('userDetails').style.display = 'block';
        }
    </script>
</head>
<body>
    <h2>Book Appointment</h2>
    <form id="appointmentForm" action="book_appointment.php" method="post">
        <label for="address">Address:</label><br>
        <input type="text" id="address" name="address" required><br><br>
        <input type="hidden" id="latitude" name="latitude">
        <input type="hidden" id="longitude" name="longitude">
        
        <div id="zoneDetails" style="display:none;"></div>

        <div id="userDetails" style="display:none;">
            <label for="name">Name:</label><br>
            <input type="text" id="name" name="name" required><br><br>
            
            <label for="surname">Surname:</label><br>
            <input type="text" id="surname" name="surname" required><br><br>
            
            <label for="phone">Phone:</label><br>
            <input type="tel" id="phone" name="phone" required><br><br>
            
            <label for="notes">Notes:</label><br>
            <textarea id="notes" name="notes"></textarea><br><br>
            
            <input type="hidden" id="selectedSlot" name="selectedSlot">
            <input type="hidden" id="zone_id" name="zone_id">
            <input type="submit" value="Confirm Appointment">
        </div>
    </form>
</body>
</html>
