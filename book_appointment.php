<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Prenota Appuntamento</title>
    <script>
        function searchSurname() {
            const surnameInput = document.getElementById('surname_search').value;
            if (surnameInput.length > 2) {
                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'book_appointment.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onreadystatechange = function () {
                    if (xhr.readyState === 4 && xhr.status === 200) {
                        document.getElementById('patientsList').innerHTML = xhr.responseText;
                    }
                };
                xhr.send('surname_search=' + encodeURIComponent(surnameInput));
            } else {
                document.getElementById('patientsList').innerHTML = '';
            }
        }

        function selectPatient(name, surname, phone) {
            document.getElementById('name').value = name;
            document.getElementById('surname').value = surname;
            document.getElementById('phone').value = phone;
            document.getElementById('patientsList').innerHTML = '';
        }
    </script>
</head>
<body>
    <h1>Prenota Appuntamento</h1>
    <label for="surname_search">Cerca Paziente per Cognome:</label>
    <input type="text" id="surname_search" name="surname_search" oninput="searchSurname()"><br><br>
    <div id="patientsList"></div>

    <form method="POST" action="submit_appointment.php">
        <input type="hidden" name="zone_id" value="<?php echo htmlspecialchars($zone_id); ?>">
        <input type="hidden" name="date" value="<?php echo htmlspecialchars($date); ?>">
        <input type="hidden" name="time" value="<?php echo htmlspecialchars($time); ?>">

        <label for="name">Nome:</label>
        <input type="text" id="name" name="name" required><br><br>

        <label for="surname">Cognome:</label>
        <input type="text" id="surname" name="surname" required><br><br>

        <label for="phone">Telefono:</label>
        <input type="text" id="phone" name="phone" required><br><br>

        <label for="address">Indirizzo:</label>
        <input type="text" id="address" name="address" required><br><br>

        <label for="notes">Note:</label>
        <textarea id="notes" name="notes"></textarea><br><br>

        <button type="submit">Prenota</button>
    </form>
</body>
</html>
