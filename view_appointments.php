<!DOCTYPE html>
<html>
<head>
    <title>View Appointments</title>
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js'></script>
</head>
<body>
    <?php include 'menu.php'; ?>
    <?php include 'db.php'; // Include database connection ?>

    <form id="filters" onsubmit="return false;">
        <label for="search">Search by Name:</label>
        <input type="text" id="search" name="search">
        <label for="date_filter">Filter by Date:</label>
        <input type="date" id="date_filter" name="date_filter">
        <label for="zone_filter">Filter by Zone:</label>
        <select id="zone_filter" name="zone_filter">
            <option value="">Select Zone</option>
            <?php
            // Fetch distinct zones from the database
            $zones = getZones();
            foreach ($zones as $zone) {
                echo '<option value="' . htmlspecialchars($zone) . '">' . htmlspecialchars($zone) . '</option>';
            }
            ?>
        </select>
        <button id="clear-filters">Clear Filters</button>
    </form>

    <div id="appointments-list"></div>
    <div id='calendar'></div>

    <script>
      function fetchAppointments() {
        const search = document.getElementById('search').value;
        const date_filter = document.getElementById('date_filter').value;
        const zone_filter = document.getElementById('zone_filter').value;

        const xhr = new XMLHttpRequest();
        xhr.open('GET', `manage_appointments.php?search=${encodeURIComponent(search)}&date=${encodeURIComponent(date_filter)}&zone=${encodeURIComponent(zone_filter)}`, true);
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4 && xhr.status === 200) {
                document.getElementById('appointments-list').innerHTML = xhr.responseText;
                calendar.refetchEvents();
            }
        };
        xhr.send();
      }

      document.addEventListener('DOMContentLoaded', function() {
        var calendarEl = document.getElementById('calendar');
        var calendar = new FullCalendar.Calendar(calendarEl, {
          initialView: 'dayGridMonth',
          events: function(fetchInfo, successCallback, failureCallback) {
            const search = document.getElementById('search').value;
            const date_filter = document.getElementById('date_filter').value;
            const zone_filter = document.getElementById('zone_filter').value;

            const xhr = new XMLHttpRequest();
            xhr.open('GET', `manage_appointments.php?search=${encodeURIComponent(search)}&date=${encodeURIComponent(date_filter)}&zone=${encodeURIComponent(zone_filter)}`, true);
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4 && xhr.status === 200) {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(xhr.responseText, 'text/html');
                    const events = [];
                    doc.querySelectorAll('table tr').forEach(row => {
                        const cells = row.querySelectorAll('td');
                        events.push({
                            id: cells[0].innerText,
                            title: cells[1].innerText + ' ' + cells[2].innerText,
                            start: cells[5].innerText + 'T' + cells[6].innerText,
                            extendedProps: {
                                phone: cells[3].innerText,
                                notes: cells[4].innerText,
                                zone: cells[7].innerText
                            }
                        });
                    });
                    successCallback(events);
                } else if (xhr.readyState === 4) {
                    failureCallback(xhr.statusText);
                }
            };
            xhr.send();
          }
        });
        calendar.render();

        document.getElementById('search').addEventListener('input', fetchAppointments);
        document.getElementById('date_filter').addEventListener('change', fetchAppointments);
        document.getElementById('zone_filter').addEventListener('change', fetchAppointments);
        document.getElementById('clear-filters').addEventListener('click', () => {
          document.getElementById('search').value = '';
          document.getElementById('date_filter').value = '';
          document.getElementById('zone_filter').value = '';
          fetchAppointments();
        });

        // Initial fetch
        fetchAppointments();
      });
    </script>
</body>
</html>
