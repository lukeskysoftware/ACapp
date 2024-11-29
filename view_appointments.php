<!DOCTYPE html>
<html>
<head>
    <!-- Removed the FullCalendar CSS link -->
</head>
<body>
    <?php include 'menu.php'; ?>

    <form id="filters" onsubmit="return false;">
        <label for="search">Search by Name:</label>
        <input type="text" id="search" name="search">
        <label for="date_filter">Filter by Date:</label>
        <input type="date" id="date_filter" name="date_filter">
        <label for="zone_filter">Filter by Zone:</label>
        <select id="zone_filter" name="zone_filter">
            <option value="">Select Zone</option>
            <!-- Populate zones dynamically from the server -->
        </select>
        <button id="clear-filters">Clear Filters</button>
    </form>

    <div id='calendar'></div>

    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js'></script>

    <script>
      document.addEventListener('DOMContentLoaded', function() {
        var calendarEl = document.getElementById('calendar');
        var calendar = new FullCalendar.Calendar(calendarEl, {
          initialView: 'dayGridMonth',
          events: function(fetchInfo, successCallback, failureCallback) {
            const search = document.getElementById('search').value;
            const date_filter = document.getElementById('date_filter').value;
            const zone_filter = document.getElementById('zone_filter').value;

            fetch(`manage_appointments.php?search=${encodeURIComponent(search)}&date=${encodeURIComponent(date_filter)}&zone=${encodeURIComponent(zone_filter)}`)
              .then(response => response.json())
              .then(data => {
                const events = data.map(appointment => ({
                  id: appointment.id,
                  title: appointment.name + ' ' + appointment.surname,
                  start: appointment.appointment_date + 'T' + appointment.appointment_time,
                  extendedProps: {
                    phone: appointment.phone,
                    notes: appointment.notes,
                    zone: appointment.zone
                  }
                }));
                successCallback(events);
              })
              .catch(error => failureCallback(error));
          }
        });
        calendar.render();

        document.getElementById('search').addEventListener('input', () => calendar.refetchEvents());
        document.getElementById('date_filter').addEventListener('change', () => calendar.refetchEvents());
        document.getElementById('zone_filter').addEventListener('change', () => calendar.refetchEvents());
        document.getElementById('clear-filters').addEventListener('click', () => {
          document.getElementById('search').value = '';
          document.getElementById('date_filter').value = '';
          document.getElementById('zone_filter').value = '';
          calendar.refetchEvents();
        });
      });
    </script>
</body>
</html>
