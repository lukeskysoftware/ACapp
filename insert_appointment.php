<!DOCTYPE html>
<html>
<head>
    <title>Visualizza Appuntamenti</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/purecss@3.0.0/build/pure-min.css" integrity="sha384-X38yfunGUhNzHpBaEBsWLO+A0HDYOQi8ufWDkZ0k9e0eXz/tH3II7uKZ9msv++Ls" crossorigin="anonymous">
    <link rel="stylesheet" href="styles.css">
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
        $sql = "SELECT a.id, p.name, p.surname, CONCAT('+39', p.phone) as phone, a.notes, a.appointment_date, a.appointment_time, a.address, 
                CASE WHEN z.name IS NULL THEN 'N/A' ELSE z.name END as zone
                FROM cp_appointments a
                JOIN cp_patients p ON a.patient_id = p.id
                LEFT JOIN cp_zones z ON a.zone_id = z.id";
        $result = mysqli_query($conn, $sql);
        if (!$result) {
            die('Error: ' . mysqli_error($conn));
        }
        return mysqli_fetch_all($result, MYSQLI_ASSOC);
    }

    function getFutureAppointmentDates($appointments) {
        $today = date('Y-m-d');
        $dates = array_unique(array_map(function($appointment) {
            return $appointment['appointment_date'];
        }, $appointments));
        $dates = array_filter($dates, function($date) use ($today) {
            return $date >= $today;
        });
        sort($dates);
        return $dates;
    }

    function getAllAppointmentDates($appointments) {
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
    $futureAppointmentDates = getFutureAppointmentDates($appointments);
    $allAppointmentDates = getAllAppointmentDates($appointments);
    ?>

    <div class="dropdown mt-3 pure-g aria centrato centro">
        <button class="btn btn-primary dropdown-toggle pure-button centrato centro" type="button" id="itineraryButton" data-bs-toggle="dropdown" aria-expanded="false">
            Vedi l'itinerario per gli appuntamenti del
            <select id="itineraryDropdown" class="form-select">
                <option selected>Scegli data</option>
                <?php foreach ($futureAppointmentDates as $date): ?>
                    <option value="<?php echo $date; ?>"><?php echo formatItalianDate($date); ?></option>
                <?php endforeach; ?>
            </select>
        </button>
    </div>
    <button id="openMapButton"  class="btn btn-success mt-3" style="display: none; margin: 0 auto 2rem auto;" >Apri in Mappe</button>

    <div id="calendar"></div>
    <div id="detailsPanel"></div>

    <script>
      document.addEventListener('DOMContentLoaded', function() {
        var calendarEl = document.getElementById('calendar');
        var detailsPanel = document.getElementById('detailsPanel');
        var openMapButton = document.getElementById('openMapButton');
        var mapUrl = '';

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
            openMapButton.style.display = 'none';
            return;
          }

          let waypoints = todaysAppointments.map(appointment => appointment.address);
          
          // Ensure at least a start and end point
          let start = "Current+Location";
          let end = waypoints.pop(); // Last waypoint as end
          let intermediateWaypoints = waypoints.map(waypoint => `&daddr=${encodeURIComponent(waypoint)}`).join('');

          // Generate Apple Maps URL using +to: format
          let appleMapsUrl = `maps://?saddr=${start}&daddr=${waypoints.map(waypoint => encodeURIComponent(waypoint)).join('+to:')}+to:${encodeURIComponent(end)}&dirflg=d`;
          let googleMapsUrl = `https://www.google.com/maps/dir/?api=1&origin=${start}&destination=${encodeURIComponent(end)}&waypoints=${waypoints.map(waypoint => encodeURIComponent(waypoint)).join('|')}`;

          // Determine which URL to use based on the device
          if (/iPhone|iPad|iPod/i.test(navigator.userAgent)) {
            mapUrl = appleMapsUrl;
          } else {
            mapUrl = googleMapsUrl;
          }

          openMapButton.style.display = 'block';
        });

        openMapButton.addEventListener('click', function() {
          if (mapUrl) {
            window.open(mapUrl, '_blank');
          }
        });
      });
    </script>
</body>
</html>
