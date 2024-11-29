<!DOCTYPE html>
<html>
<head>
    <!-- Removed the FullCalendar CSS link -->
</head>
<body>
    <?php include 'menu.php'; ?>
    <?php include 'db.php'; // Include database connection ?>

    <form id="filters" onsubmit="return false;">
        <label for="search">Search by Name:</label>
        <input type="text" id="search" name="search">
        <label for="date_filter">Filter by Date:</label>
        <input type="date" id="date_filter" name="date_filter">
        <label for="zone_filter">Filter by Zone:</label>
        <select id="zone_filter" name="zone_filter">
            <option value="">Select Zone</option>
            <?php
            // Fetch distinct zones from the database
            $zonesQuery = "SELECT DISTINCT z.name FROM cp_appointments a JOIN cp_zones z ON a.zone_id = z.id";
            $zonesResult = mysqli_query($conn, $zonesQuery);
            while ($zone = mysqli_fetch_assoc($zonesResult)) {
                echo '<option value="' . htmlspecialchars($zone['name']) . '">' . htmlspecialchars($zone['name']) . '</option>';
            }
            ?>
        </select>
        <button id="clear-filters">Clear Filters</button>
    </form>

    <div id="appointments-list"></div>
    <div id='calendar'></div>

    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js'></script>

    <script>
      function fetchAppointments() {
        const search = document.getElementById('search').value;
        const date_filter = document.getElementById('date_filter').value;
        const zone_filter = document.getElementById('zone_filter').value;

        const xhr = new XMLHttpRequest();
        xhr.open('GET', `view_appointments.php?search=${encodeURIComponent(search)}&date=${encodeURIComponent(date_filter)}&zone=${encodeURIComponent(zone_filter)}`, true);
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4 && xhr.status === 200) {
                document.getElementById('appointments-list').innerHTML = xhr.responseText;
                calendar.refetchEvents();
            }
        };
        xhr.send();
      }

      document.addEventListener('DOMContentLoaded', function() {
        var calendarEl = document.getElementById('calendar');
        var calendar = new FullCalendar.Calendar(calendarEl, {
          initialView: 'dayGridMonth',
          events: function(fetchInfo, successCallback, failureCallback) {
            const search = document.getElementById('search').value;
            const date_filter = document.getElementById('date_filter').value;
            const zone_filter = document.getElementById('zone_filter').value;

            const xhr = new XMLHttpRequest();
            xhr.open('GET', `view_appointments.php?search=${encodeURIComponent(search)}&date=${encodeURIComponent(date_filter)}&zone=${encodeURIComponent(zone_filter)}`, true);
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4 && xhr.status === 200) {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(xhr.responseText, 'text/html');
                    const events = [];
                    doc.querySelectorAll('table#appointmentsTable tbody tr').forEach(row => {
                        const cells = row.querySelectorAll('td');
                        events.push({
                            id: cells[0].innerText,
                            title: cells[1].innerText + ' ' + cells[2].innerText,
                            start: cells[5].innerText + 'T' + cells[6].innerText,
                            extendedProps: {
                                phone: cells[3].innerText,
                                notes: cells[4].innerText,
                                zone: cells[7].innerText
                            }
                        });
                    });
                    successCallback(events);
                } else if (xhr.readyState === 4) {
                    failureCallback(xhr.statusText);
                }
            };
            xhr.send();
          }
        });
        calendar.render();

        document.getElementById('search').addEventListener('input', fetchAppointments);
        document.getElementById('date_filter').addEventListener('change', fetchAppointments);
        document.getElementById('zone_filter').addEventListener('change', fetchAppointments);
        document.getElementById('clear-filters').addEventListener('click', () => {
          document.getElementById('search').value = '';
          document.getElementById('date_filter').value = '';
          document.getElementById('zone_filter').value = '';
          fetchAppointments();
        });

        // Initial fetch
        fetchAppointments();
      });
    </script>
</body>
</html>

<?php
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $filter = [
        'date' => isset($_GET['date']) ? $_GET['date'] : '',
        'zone' => isset($_GET['zone']) ? $_GET['zone'] : '',
    ];
    $search = isset($_GET['search']) ? $_GET['search'] : '';

    // Get appointments based on filters
    $appointmentsQuery = "SELECT a.id, p.name, p.surname, a.appointment_date, a.appointment_time, a.notes, p.phone, z.name as zone
                          FROM cp_appointments a
                          JOIN cp_patients p ON a.patient_id = p.id
                          JOIN cp_zones z ON a.zone_id = z.id
                          WHERE 1=1";
    if (!empty($filter['date'])) {
        $appointmentsQuery .= " AND a.appointment_date = '" . mysqli_real_escape_string($conn, $filter['date']) . "'";
    }
    if (!empty($filter['zone'])) {
        $appointmentsQuery .= " AND z.name = '" . mysqli_real_escape_string($conn, $filter['zone']) . "'";
    }
    if (!empty($search)) {
        $appointmentsQuery .= " AND (p.name LIKE '%" . mysqli_real_escape_string($conn, $search) . "%' OR p.surname LIKE '%" . mysqli_real_escape_string($conn, $search) . "%')";
    }
    $appointmentsResult = mysqli_query($conn, $appointmentsQuery);
    if ($appointmentsResult) {
        echo '<table id="appointmentsTable" style="display:none;"><tbody>';
        while ($appointment = mysqli_fetch_assoc($appointmentsResult)) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($appointment['id']) . '</td>';
            echo '<td>' . htmlspecialchars($appointment['name']) . '</td>';
            echo '<td>' . htmlspecialchars($appointment['surname']) . '</td>';
            echo '<td>' . htmlspecialchars($appointment['phone']) . '</td>';
            echo '<td>' . htmlspecialchars($appointment['notes']) . '</td>';
            echo '<td>' . htmlspecialchars($appointment['appointment_date']) . '</td>';
            echo '<td>' . htmlspecialchars($appointment['appointment_time']) . '</td>';
            echo '<td>' . htmlspecialchars($appointment['zone']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
}
?>
