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
            var input = document.getElementById('address');
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
                fetchZones(input.value);
            });
        }

        function fetchZones(address) {
            fetch('calculate_zones.php?address=' + encodeURIComponent(address))
                .then(response => response.json())
                .then(data => {
                    if (data.zones && data.zones.length > 0) {
                        displayZones(data.zones);
                    } else {
                        alert('No zones found for this address.');
                    }
                })
                .catch(error => {
                    console.error('Error fetching zones:', error);
                });
        }

        function displayZones(zones) {
            const zoneDetails = document.getElementById('zoneDetails');
            zoneDetails.innerHTML = '<h3>Zones for the Address:</h3>';
            zones.forEach(zone => {
                const zoneDiv = document.createElement('div');
                zoneDiv.textContent = `Zone: ${zone.name}`;
                zoneDetails.appendChild(zoneDiv);
            });
            zoneDetails.style.display = 'block';
        }

        function checkAddress() {
            const address = document.getElementById('address').value;
            if (address) {
                fetchZones(address);
            } else {
                alert('Please enter an address.');
            }
        }
    </script>
</head>
<body>
    <h2>Book Appointment</h2>
    <form id="appointmentForm" action="book_appointment.php" method="post">
        <label for="address">Address:</label><br>
        <input type="text" id="address" name="address" required><br><br>
        <button type="button" onclick="checkAddress()">Check Address</button><br><br>
        
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
