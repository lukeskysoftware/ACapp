<!DOCTYPE html>
<html>
<head>
    <link href='https://fullcalendar.io/releases/fullcalendar/4.0.0/core/main.css' rel='stylesheet' />
    <link href='https://fullcalendar.io/releases/fullcalendar/4.0.0/daygrid/main.css' rel='stylesheet' />
    <link href='https://fullcalendar.io/releases/fullcalendar/4.0.0/timegrid/main.css' rel='stylesheet' />
    <script src='https://fullcalendar.io/releases/fullcalendar/4.0.0/core/main.js'></script>
    <script src='https://fullcalendar.io/releases/fullcalendar/4.0.0/daygrid/main.js'></script>
    <script src='https://fullcalendar.io/releases/fullcalendar/4.0.0/timegrid/main.js'></script>
    <script src='https://fullcalendar.io/releases/fullcalendar/4.0.0/interaction/main.js'></script>
</head>
<body>
    <div id='calendar'></div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var calendarEl = document.getElementById('calendar');
            var calendar = new FullCalendar.Calendar(calendarEl, {
                plugins: [ 'dayGrid', 'timeGrid', 'interaction' ],
                header: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,timeGridDay'
                },
                defaultView: 'dayGridMonth',
                events: 'fetch_appointments.php', // URL to fetch appointments in JSON format
            });
            calendar.render();
        });
    </script>
</body>
</html>
