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
        .timeslot {
            font-size: 1.5rem;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <?php include_once 'menu.php'; ?>
    <?php
    include_once 'db.php';

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

    // Function to get unique dates with appointments
    function getAppointmentDates($appointments) {
        $dates = array_unique(array_map(function($appointment) {
            return $appointment['appointment_date'];
        }, $appointments));
        sort($dates);
        return $dates;
    }

    function formatItalianDate($date) {
        setlocale(LC_TIME, 'it_IT.UTF-8');
        $timestamp = strtotime($date);
        return strftime('%d %B %Y', $timestamp);
    }

    $appointments = getAppointments($conn);
    $appointmentDates = getAppointmentDates($appointments);
    ?>

    <div id="calendar"></div>
    <div id="detailsPanel"></div>
    <div class="dropdown mt-3">
        <button class="btn btn-primary dropdown-toggle" type="button" id="itineraryButton" data-bs-toggle="dropdown" aria-expanded="false">
            Vuoi l'itinerario per gli appuntamenti del
            <select id="itineraryDropdown" class="form-select">
                <option selected>Scegli data</option>
                <?php foreach ($appointmentDates as $date): ?>
                    <option value="<?php echo $date; ?>"><?php echo formatItalianDate($date); ?></option>
                <?php endforeach; ?>
            </select>
        </button>
    </div>

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
                italicEl.innerHTML = `<div><b>${arg.event.start.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'})}</b></div><div>${arg.event.title}</div>`;
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
              <p class="timeslot">${info.event.start.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'})}</p>
              <p><a href="tel:${info.event.extendedProps.phone}">${info.event.extendedProps.phone}</a></p>
              <p>${info.event.extendedProps.address}</p>
              <p>${info.event.extendedProps.notes}</p>
              <p><strong>Zona:</strong> ${info.event.extendedProps.zone}</p>
            `;
            detailsPanel.style.display = 'block';
          }
        });
        calendar.render();

        document.getElementById('itineraryDropdown').addEventListener('change', function() {
          const selectedDate = this.value;
          const appointments = <?php echo json_encode($appointments); ?>;
          const todaysAppointments = appointments.filter(appointment => appointment.appointment_date === selectedDate).sort((a, b) => a.appointment_time.localeCompare(b.appointment_time));
          if (todaysAppointments.length === 0) {
            alert('Nessun appuntamento per questa data.');
            return;
          }
          let waypoints = todaysAppointments.map(appointment => ({
              location: appointment.address,
              stopover: true
          }));
          waypoints.unshift({ location: '', stopover: true }); // Add a blank address as the first position
          let destination = waypoints.length > 0 ? waypoints.pop().location : ''; // Last address as the destination
          let mapUrl;

          if (/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent)) {
            // For mobile devices
            mapUrl = `https://maps.google.com/maps?saddr=${encodeURIComponent('')}&daddr=${encodeURIComponent(destination)}&waypoints=${waypoints.map(waypoint => encodeURIComponent(waypoint.location)).join('|')}&dirflg=d`;
          } else {
            // For desktop browsers
            mapUrl = `https://www.google.com/maps/dir/?api=1&origin=${encodeURIComponent('')}&destination=${encodeURIComponent(destination)}&waypoints=${waypoints.map(waypoint => encodeURIComponent(waypoint.location)).join('|')}&travelmode=driving`;
          }

          window.open(mapUrl, '_blank');
        });
      });
    </script>
</body>
</html>
