<?php
include 'db.php';
include 'menu.php';

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

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visualizza Appuntamenti</title>
    <link rel="stylesheet" href="styles.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/6.1.5/fullcalendar.min.css" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.29.1/moment.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/6.1.5/fullcalendar.min.js"></script>
    <style>
        #calendar {
            max-width: 900px;
            margin: 0 auto;
        }
        .timeslot {
            font-size: 1.5rem;
            font-weight: bold;
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
    <div class="container">
        <h2 class="text-center my-4">Visualizza Appuntamenti</h2>
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
      
        <div id="emailGroup" class="container mt-3">
            <div class="row">
                <div class="col-md-12 mb-3">
                    <input type="email" id="email" placeholder="Inserisci email" class="form-control">
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" id="formatGoogle" value="google">
                        <label class="form-check-label" for="formatGoogle">Google Maps</label>
                    </div>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" id="formatApple" value="apple">
                        <label class="form-check-label" for="formatApple">Apple Maps</label>
                    </div>
                    <div class="input-group mt-2">
                        <button id="sendEmail" class="btn btn-primary">Invia URL</button>
                    </div>
                </div>
            </div>
        </div>

        <button id="openMapButton" style="margin:0 auto 2rem auto; display:none;" class="btn btn-success mt-3">Apri in Mappe</button>

        <div id="calendar"></div>
        <div id="detailsPanel"></div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var calendarEl = document.getElementById('calendar');
            var detailsPanel = document.getElementById('detailsPanel');
            var openMapButton = document.getElementById('openMapButton');
            var emailGroup = document.getElementById('emailGroup');
            var mapUrlGoogle = '';
            var mapUrlApple = '';
            var selectedDate = '';

            var appointments = <?php echo json_encode($appointments); ?>;

            $('#calendar').fullCalendar({
                locale: 'it',
                defaultView: 'agendaDay',
                header: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'month,agendaWeek,agendaDay'
                },
                events: appointments.map(function(appointment) {
                    return {
                        id: appointment.id,
                        title: appointment.name + ' ' + appointment.surname,
                        start: appointment.appointment_date + 'T' + appointment.appointment_time,
                        extendedProps: {
                            phone: appointment.phone,
                            address: appointment.address,
                            notes: appointment.notes,
                            zone: appointment.zone
                        }
                    };
                }),
                eventClick: function(info) {
                    detailsPanel.innerHTML = `
                        <h5>${info.title}</h5>
                        <p class="timeslot">${moment(info.start).format('HH:mm')}</p>
                        <p><a href="tel:${info.extendedProps.phone}">${info.extendedProps.phone}</a></p>
                        <p>${info.extendedProps.address}</p>
                        <p>${info.extendedProps.notes}</p>
                        <p><strong>Zona:</strong> ${info.extendedProps.zone}</p>
                    `;
                    detailsPanel.style.display = 'block';
                }
            });

            document.getElementById('itineraryDropdown').addEventListener('change', function() {
                selectedDate = this.value;
                const todaysAppointments = appointments.filter(appointment => appointment.appointment_date === selectedDate).sort((a, b) => a.appointment_time.localeCompare(b.appointment_time));
                if (todaysAppointments.length === 0) {
                    alert('Nessun appuntamento per questa data.');
                    openMapButton.style.display = 'none';
                    emailGroup.style.display = 'none';
                    return;
                }

                let waypoints = todaysAppointments.map(appointment => appointment.address);
                
                // Ensure at least a start and end point
                let start = "Current+Location";
                let end = waypoints.pop(); // Last waypoint as end
                let intermediateWaypoints = waypoints.map(waypoint => `&daddr=${encodeURIComponent(waypoint)}`).join('');

                // Generate Apple Maps URL using +to: format
                mapUrlApple = `maps://?saddr=${start}&daddr=${waypoints.map(waypoint => encodeURIComponent(waypoint)).join('+to:')}+to:${encodeURIComponent(end)}&dirflg=d`;
                mapUrlGoogle = `https://www.google.com/maps/dir/?api=1&origin=${start}&destination=${encodeURIComponent(end)}&waypoints=${waypoints.map(waypoint => encodeURIComponent(waypoint)).join('|')}`;

                openMapButton.style.display = 'block';
                emailGroup.style.display = 'block';
            });

            document.getElementById('sendEmail').addEventListener('click', function() {
                const email = document.getElementById('email').value;
                const formatGoogle = document.getElementById('formatGoogle').checked;
                const formatApple = document.getElementById('formatApple').checked;

                if (!email) {
                    alert('Inserisci un indirizzo email valido.');
                    return;
                }

                if (!formatGoogle && !formatApple) {
                    alert('Seleziona almeno un formato per l\'URL delle mappe.');
                    return;
                }

                let message = "Ciao,\n\nEcco l'URL dell'itinerario per i tuoi appuntamenti del giorno " + selectedDate + ":\n\n";
                if (formatGoogle) {
                    message += "**APRI IN GOOGLE MAPS**\n" + mapUrlGoogle + "\n\n";
                }
                if (formatApple) {
                    message += "**APRI IN MAPPE APPLE**\n" + mapUrlApple + "\n\n";
                }
                message += "Cordiali saluti,\nIl Team degli Appuntamenti";

                sendEmail(email, "Itinerario per gli appuntamenti del giorno " + selectedDate, message);
            });

            function sendEmail(email, subject, message) {
                fetch('send_email.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ email: email, subject: subject, message: message })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Email inviata con successo.');
                    } else {
                        alert('Errore nell\'invio dell\'email.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Errore nell\'invio dell\'email.');
                });
            }

            openMapButton.addEventListener('click', function() {
                if (mapUrlGoogle) {
                    window.open(mapUrlGoogle, '_blank');
                }
            });
        });
    </script>
</body>
</html>