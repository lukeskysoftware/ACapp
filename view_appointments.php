<!DOCTYPE html>
<html>
<head>
    <link href='https://cdn.jsdelivr.net/npm/fullcalendar@4.0.0/core/main.min.css' rel='stylesheet' />
    <link href='https://cdn.jsdelivr.net/npm/fullcalendar@4.0.0/daygrid/main.min.css' rel='stylesheet' />
    <link href='https://cdn.jsdelivr.net/npm/fullcalendar@4.0.0/timegrid/main.min.css' rel='stylesheet' />
    <link href='https://cdn.jsdelivr.net/npm/fullcalendar@4.0.0/list/main.min.css' rel='stylesheet' />
</head>
<body>
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

    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@4.0.0/core/main.min.js'></script>
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@4.0.0/daygrid/main.min.js'></script>
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@4.0.0/timegrid/main.min.js'></script>
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@4.0.0/list/main.min.js'></script>
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@4.0.0/interaction/main.min.js'></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var calendarEl = document.getElementById('calendar');
            var calendar = new FullCalendar.Calendar(calendarEl, {
                plugins: [ 'dayGrid', 'timeGrid', 'list', 'interaction' ],
                header: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
                },
                defaultView: 'dayGridMonth',
                events: function(fetchInfo, successCallback, failureCallback) {
                    const search = document.getElementById('search').value;
                    const date_filter = document.getElementById('date_filter').value;
                    const zone_filter = document.getElementById('zone_filter').value;

                    fetch(`fetch_appointments.php?search=${encodeURIComponent(search)}&date_filter=${encodeURIComponent(date_filter)}&zone_filter=${encodeURIComponent(zone_filter)}`)
                        .then(response => response.json())
                        .then(events => successCallback(events))
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
