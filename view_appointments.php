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

    <div id="calendar"></div>

    <script>
      document.addEventListener('DOMContentLoaded', function() {
        var calendarEl = document.getElementById('calendar');
        var calendar = new FullCalendar.Calendar(calendarEl, {
          initialView: 'timeGridWeek',
          headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay'
          },
          events: function(fetchInfo, successCallback, failureCallback) {
            const xhr = new XMLHttpRequest();
            xhr.open('GET', `fetch_appointments.php`, true);
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
            info.el.title = `Phone: ${info.event.extendedProps.phone}\nAddress: ${info.event.extendedProps.address}\nNotes: ${info.event.extendedProps.notes}`;
          }
        });
        calendar.render();
      });
    </script>
</body>
</html>
