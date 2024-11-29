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
        .fc-event-title {
            white-space: normal !important;
        }
        .fc-daygrid-event {
            height: auto !important;
        }
    </style>
</head>
<body>
    <?php include_once 'menu.php'; ?>
    <?php
    include_once 'db.php';

    // Function to get all appointments with patient and zone information
    function getAppointments($conn) {
        $sql = "SELECT a.id, p.name, p.surname, p.phone, a.notes, a.appointment_date, a.appointment_time, a.address, z.name as zone
                FROM cp_appointments a
                JOIN cp_patients p ON a.patient_id = p.id
                JOIN cp_zones z ON a.zone_id = z.id";
        $result = mysqli_query($conn, $sql);
        if (!$result) {
            die('Error: ' . mysqli_error($conn));
        }
        return mysqli_fetch_all($result, MYSQLI_ASSOC);
    }

    $appointments = getAppointments($conn);
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
          views: {
            timeGridWeek: {
              eventContent: function(arg) {
                let italicEl = document.createElement('div');
                italicEl.innerHTML = `
                  <b>${arg.event.title}</b><br/>
                  <i>Phone: ${arg.event.extendedProps.phone}</i><br/>
                  <i>Address: ${arg.event.extendedProps.address}</i><br/>
                  <i>Notes: ${arg.event.extendedProps.notes}</i>
                `;
                let arrayOfDomNodes = [ italicEl ]
                return { domNodes: arrayOfDomNodes }
              }
            },
            timeGridDay: {
              eventContent: function(arg) {
                let italicEl = document.createElement('div');
                italicEl.innerHTML = `
                  <b>${arg.event.title}</b><br/>
                  <i>Phone: ${arg.event.extendedProps.phone}</i><br/>
                  <i>Address: ${arg.event.extendedProps.address}</i><br/>
                  <i>Notes: ${arg.event.extendedProps.notes}</i>
                `;
                let arrayOfDomNodes = [ italicEl ]
                return { domNodes: arrayOfDomNodes }
              }
            }
          },
          events: function(fetchInfo, successCallback, failureCallback) {
            const appointments = <?php echo json_encode($appointments); ?>;
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
          },
          eventTimeFormat: { // like '14:30'
            hour: '2-digit',
            minute: '2-digit',
            meridiem: false
          },
          headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay'
          }
        });
        calendar.render();
      });
    </script>
</body>
</html>
