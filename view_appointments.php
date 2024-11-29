<!DOCTYPE html>
<html>
<head>
    <title>Visualizza Appuntamenti</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css' rel='stylesheet'>
    <script src='https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js'></script>
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
        .tooltip-inner {
            max-width: 350px;
        }
        #detailsPanel {
            position: fixed;
            right: 0;
            top: 0;
            width: 300px;
            height: 100%;
            overflow-y: auto;
            background: #f8f9fa;
            box-shadow: -2px 0 5px rgba(0,0,0,0.1);
            padding: 20px;
            display: none;
        }
    </style>
</head>
<body>
    <?php include_once 'menu.php'; ?>
    <?php
    include_once 'db.php';

    // Function to get all appointments with patient and zone information
    function getAppointments($conn) {
        $sql = "SELECT a.id, p.name, p.surname, CONCAT('+39', p.phone) as phone, a.notes, a.appointment_date, a.appointment_time, a.address, z.name as zone
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
    <div id="detailsPanel"></div>

    <script>
      document.addEventListener('DOMContentLoaded', function() {
        var calendarEl = document.getElementById('calendar');
        var detailsPanel = document.getElementById('detailsPanel');
        var calendar = new FullCalendar.Calendar(calendarEl, {
          locale: 'it', // Set the locale to Italian
          initialView: 'timeGridWeek',
          themeSystem: 'bootstrap5',
          headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay'
          },
          views: {
            dayGridMonth: {
              eventContent: function(arg) {
                let italicEl = document.createElement('div');
                italicEl.innerHTML = `<b>${arg.event.title}</b>`;
                let arrayOfDomNodes = [ italicEl ];
                return { domNodes: arrayOfDomNodes };
              }
            },
            timeGridWeek: {
              eventContent: function(arg) {
                let italicEl = document.createElement('div');
                italicEl.innerHTML = `<b>${arg.event.title}</b>`;
                let arrayOfDomNodes = [ italicEl ];
                return { domNodes: arrayOfDomNodes };
              }
            },
            timeGridDay: {
              eventContent: function(arg) {
                let italicEl = document.createElement('div');
                italicEl.innerHTML = `<b>${arg.event.title}</b>`;
                let arrayOfDomNodes = [ italicEl ];
                return { domNodes: arrayOfDomNodes };
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
          slotLabelFormat: { // time labels in 24-hour format
            hour: '2-digit',
            minute: '2-digit',
            hour12: false
          },
          height: 'auto',
          eventClick: function(info) {
            detailsPanel.innerHTML = `
              <h5>${info.event.title}</h5>
              <p><a href="tel:${info.event.extendedProps.phone}">${info.event.extendedProps.phone}</a></p>
              <p>${info.event.extendedProps.address}</p>
              <p>${info.event.extendedProps.notes}</p>
              <p><strong>Zona:</strong> ${info.event.extendedProps.zone}</p>
            `;
            detailsPanel.style.display = 'block';
          }
        });
        calendar.render();
      });
    </script>
</body>
</html>
