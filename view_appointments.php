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
                },
                description: `Phone: ${appointment.phone}\nAddress: ${appointment.address}\nNotes: ${appointment.notes}`
            }));
            successCallback(events);
          },
          eventDidMount: function(info) {
            info.el.title = info.event.extendedProps.description;
          }
        });
        calendar.render();
      });
    </script>
</body>
</html>
