<!DOCTYPE html>
<html>
<head>
    <title>View Appointments</title>
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js'></script>
    <style>
        #calendar {
            max-width: 900px;
            margin: 0 auto;
        }
    </style>
</head>
<body>
    <?php include_once 'menu.php'; ?>
    <?php
    include_once 'db.php';
    include_once 'manage_appointments.php';
    ?>

    <form id="filters" onsubmit="return false;">
        <label for="search">Search by Name:</label>
        <input type="text" id="search" name="search">
        <label for="date_filter">Filter by Date:</label>
        <input type="date" id="date_filter" name="date_filter">
        <label for="zone_filter">Filter by Zone:</label>
        <select id="zone_filter" name="zone_filter">
            <option value="">Select Zone</option>
            <?php
            $zones = getZones();
            foreach ($zones as $zone) {
                echo '<option value="' . htmlspecialchars($zone) . '">' . htmlspecialchars($zone) . '</option>';
            }
            ?>
        </select>
        <button id="clear-filters">Clear Filters</button>
    </form>

    <div id="calendar"></div>

    <script>
      document.addEventListener('DOMContentLoaded', function() {
        var calendarEl = document.getElementById('calendar');
        var calendar = new FullCalendar.Calendar(calendarEl, {
          initialView: 'dayGridMonth',
          headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
          },
          events: function(fetchInfo, successCallback, failureCallback) {
            const search = document.getElementById('search').value;
            const date_filter = document.getElementById('date_filter').value;
            const zone_filter = document.getElementById('zone_filter').value;

            const xhr = new XMLHttpRequest();
            xhr.open('GET', `fetch_appointments.php?search=${encodeURIComponent(search)}&date=${encodeURIComponent(date_filter)}&zone=${encodeURIComponent(zone_filter)}`, true);
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4 && xhr.status === 200) {
                    const appointments = JSON.parse(xhr.responseText);
                    const events = appointments.map(appointment => ({
                        id: appointment.id,
                        title: `${appointment.name} ${appointment.surname}`,
                        start: `${appointment.appointment_date}T${appointment.appointment_time}`,
                        extendedProps: {
                            phone: appointment.phone,
                            address: appointment.address,
                            notes: appointment.notes,
                            zone: appointment.zone
                        }
                    }));
                    successCallback(events);
                } else if (xhr.readyState === 4) {
                    failureCallback(xhr.statusText);
                }
            };
            xhr.send();
          },
          eventDidMount: function(info) {
            const tooltip = new Tooltip(info.el, {
              title: `${info.event.extendedProps.phone} - ${info.event.extendedProps.address} - ${info.event.extendedProps.notes}`,
              placement: 'top',
              trigger: 'hover',
              container: 'body'
            });
          }
        });
        calendar.render();

        document.getElementById('search').addEventListener('input', refreshCalendar);
        document.getElementById('date_filter').addEventListener('change', refreshCalendar);
        document.getElementById('zone_filter').addEventListener('change', refreshCalendar);
        document.getElementById('clear-filters').addEventListener('click', clearFilters);

        function refreshCalendar() {
          calendar.refetchEvents();
        }

        function clearFilters() {
          document.getElementById('search').value = '';
          document.getElementById('date_filter').value = '';
          document.getElementById('zone_filter').value = '';
          refreshCalendar();
        }
      });
    </script>
</body>
</html>
