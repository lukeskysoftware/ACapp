<script>
    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('date').addEventListener('change', filterAppointments);
        document.getElementById('zone').addEventListener('input', filterAppointments);
        document.getElementById('search').addEventListener('input', filterAppointments);
        document.getElementById('clear-filters').addEventListener('click', clearFilters);
    });

    function filterAppointments() {
        const date = document.getElementById('date').value;
        const zone = document.getElementById('zone').value;
        const search = document.getElementById('search').value;

        const xhr = new XMLHttpRequest();
        xhr.open('GET', `manage_appointments.php?date=${encodeURIComponent(date)}&zone=${encodeURIComponent(zone)}&search=${encodeURIComponent(search)}`, true);
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4 && xhr.status === 200) {
                const parser = new DOMParser();
                const doc = parser.parseFromString(xhr.responseText, 'text/html');
                const newTable = doc.querySelector('table');
                const appointmentsMessage = doc.querySelector('p');
                
                if (newTable) {
                    document.querySelector('table').innerHTML = newTable.innerHTML;
                } else if (appointmentsMessage) {
                    document.querySelector('table').innerHTML = '';
                    document.querySelector('table').insertAdjacentHTML('afterend', appointmentsMessage.outerHTML);
                }
            }
        };
        xhr.send();
    }

    function clearFilters() {
        document.getElementById('date').value = '';
        document.getElementById('zone').value = '';
        document.getElementById('search').value = '';
        filterAppointments();
    }

    function confirmDelete(appointment) {
        if (confirm(`Sei sicuro di voler cancellare l'appuntamento in zona ${appointment.zone} ${appointment.address} con ${appointment.name} ${appointment.surname} ${appointment.phone} ${appointment.notes} il ${appointment.appointment_date} all'ora ${appointment.appointment_time}?`)) {
            document.getElementById(`confirm-delete-${appointment.id}`).style.display = 'inline';
            document.getElementById(`delete-btn-${appointment.id}`).style.display = 'none';
        }
    }
</script>
