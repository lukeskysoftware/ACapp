<?php
include_once 'db.php';

// Verifica che la connessione al database sia stata stabilita correttamente
if (!$conn) {
    die("Connessione al database non riuscita: " . mysqli_connect_error());
}

session_start();

// Gestisci la disconnessione
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: today.php");
    exit;
}

// Gestisci la sottomissione del modulo di login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    // Verifica le credenziali
    $sql = "SELECT * FROM cp_users WHERE username = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $username);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result);

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
    } else {
        $login_error = "Credenziali non valide.";
    }
    mysqli_stmt_close($stmt);
}

// Verifica se l'utente è loggato
if (!isset($_SESSION['user_id'])) {
    // Mostra il modulo di login
    echo '<!DOCTYPE html>
<html>
<head>
    <title>Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css">
    <meta name="format-detection" content="telephone=no">
</head>
<body>
    <div class="container d-flex justify-content-center align-items-center" style="height: 100vh;">
        <div class="card p-4 shadow-sm" style="width: 100%; max-width: 400px;">
            <h2 class="text-center">Login</h2>
            <form method="post" action="today.php">
                <div class="mb-3">
                    <label for="username" class="form-label">Username:</label>
                    <input type="text" id="username" name="username" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Password:</label>
                    <input type="password" id="password" name="password" class="form-control" required>
                </div>
                <div class="d-grid">
                    <button type="submit" class="btn btn-primary">Login</button>
                </div>
            </form>';
    if (isset($login_error)) {
        echo '<p class="text-danger text-center mt-3">' . $login_error . '</p>';
    }
    echo '  </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>';
    exit;
}

// Verifica se l'utente loggato ha i permessi corretti
if (!in_array($_SESSION['user_id'], [6, 9])) {
    echo "<p>Non hai permessi per accedere alla risorsa.</p>";
    exit;
}

function getAppointmentsByDate($conn, $date) {
    $sql = "SELECT a.id, p.name, p.surname, CONCAT('+39', p.phone) as phone, a.notes, a.appointment_date, a.appointment_time, a.address
            FROM cp_appointments a
            JOIN cp_patients p ON a.patient_id = p.id
            WHERE a.appointment_date = '$date'
            ORDER BY a.appointment_time ASC";
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        die('Error: ' . mysqli_error($conn));
    }
    return mysqli_fetch_all($result, MYSQLI_ASSOC);
}

$selectedDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$appointments = getAppointmentsByDate($conn, $selectedDate);
$today = date('Y-m-d');
$isToday = $selectedDate === $today;
$displayDate = $isToday ? "Oggi" : date('d-m-Y', strtotime($selectedDate));

// Check if we need to generate a PDF using FPDF
if (isset($_GET['pdf'])) {
    // Include FPDF library
    require('ext_parts/fpdf/fpdf.php');

    // Extend FPDF to create custom header and footer
    class PDF extends FPDF {
        // Variables to store date information
        protected $pageDate;
        protected $isToday;

        // Constructor to set date information
        function __construct($orientation='P', $unit='mm', $size='A4', $isToday=false, $displayDate='') {
            parent::__construct($orientation, $unit, $size);
            $this->isToday = $isToday;
            $this->pageDate = $displayDate;
        }

        // Page header
        function Header() {
            // Set font
            $this->SetFont('Times', 'B', 15);
            // Title
            $title = $this->isToday ? "Appuntamenti di Oggi" : "Appuntamenti del " . $this->pageDate;
            $this->Cell(0, 10, utf8_decode($title), 0, 1, 'C');
            // Line break
            $this->Ln(5);
        }

        // Page footer
        function Footer() {
            // Position at 1.5 cm from bottom
            $this->SetY(-15);
            // Set font
            $this->SetFont('Times', 'I', 8);
            // Page number
            $this->Cell(0, 10, 'Pagina '.$this->PageNo().'/{nb}', 0, 0, 'C');
        }
    }

    // Create PDF instance
    $pdf = new PDF('P', 'mm', 'A4', $isToday, $displayDate);
    $pdf->AliasNbPages();
    $pdf->AddPage();
    $pdf->SetFont('Times', '', 12);
    
    // Check if there are appointments
    if (empty($appointments)) {
        $pdf->Cell(0, 10, utf8_decode('Nessun appuntamento registrato'), 0, 1, 'C');
    } else {
        // Add each appointment to the PDF
        foreach ($appointments as $appointment) {
            // Time
            $pdf->SetFont('Times', 'B', 14);
            $pdf->Cell(0, 10, date('H:i', strtotime($appointment['appointment_time'])), 0, 1);
            
            // Name and surname
            $pdf->SetFont('Times', 'B', 12);
            $fullName = utf8_decode($appointment['name'] . ' ' . $appointment['surname']);
            $pdf->Cell(0, 8, $fullName, 0, 1);
            
            // Phone
            $pdf->SetFont('Times', '', 11);
            $pdf->Cell(0, 6, $appointment['phone'], 0, 1);
            
            // Address
            $pdf->Cell(0, 6, utf8_decode($appointment['address']), 0, 1);
            
            // Notes if any
            if (!empty($appointment['notes'])) {
                $pdf->SetFont('Times', 'B', 10);
                $pdf->Cell(20, 6, 'Note:', 0, 0);
                $pdf->SetFont('Times', '', 10);
                $pdf->MultiCell(0, 6, utf8_decode($appointment['notes']));
            }
            
            // Add separator
            $pdf->Ln(5);
            $pdf->Line($pdf->GetX(), $pdf->GetY(), $pdf->GetX() + 190, $pdf->GetY());
            $pdf->Ln(5);
        }
    }
    
    // Output the PDF based on requested type
    $pdfFileName = 'appuntamenti-' . $displayDate . '.pdf';
    $pdf->Output('I', $pdfFileName); // 'I' sends to browser
    exit;
}

// Continue with the normal page display
?>
    
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title><?php echo $isToday ? "Appuntamenti di Oggi" : "Appuntamenti del $displayDate"; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css">
    <meta name="format-detection" content="telephone=no">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        body {
            padding: 20px;
        }
        .appointment-time {
            font-weight: bold;
            font-size: 1.5rem;
        }
        .appointment-details {
            margin-bottom: 20px;
        }
        hr {
            border-top: 2px solid #bbb;
        }
        .navigation {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .btn {
            flex-grow: 1;
            margin: 0 5px;
        }
        .logout-button {
            margin-left: auto;
        }
        .dashboard-button {
            margin-right: auto;
        }
        .map-link {
            color: #007bff;
        }
        .map-button {
            margin-left: 10px;
            background-color: #17a2b8;
            color: #fff;
            border: none;
        }
        .map-button .bi {
            margin-right: 5px;
        }
        .name, .surname {
            font-weight: bold;
            font-size: 120%;
        }
        .call-button {
            background-color: #fd7e14;
            color: #fff;
            border: none;
            transition: background-color 0.3s ease;
        }
        .call-button .bi {
            margin-right: 5px;
        }
        .call-button:hover {
            background-color: #e8590c;
        }
        .email-button .bi {
            margin-right: 5px;
        }
        .print-button {
            background-color: #6c757d;
            color: #fff;
            border: none;
            margin-right: 5px;
        }
        .print-button .bi {
            margin-right: 5px;
        }
        .email-pdf-button {
            background-color: #28a745;
            color: #fff;
            border: none;
        }
        .email-pdf-button .bi {
            margin-right: 5px;
        }


.email-button {
        min-width: auto;
        width: auto;
        display: inline-block;
        max-width:220px;
        margin:0 auto;
    }
        @media (max-width: 576px) {
            .appointment-time {
                font-size: 1.2rem;
            }
            .name, .surname {
                font-size: 100%;
            }
            .btn {
                flex-grow: 1;
                margin: 5px 0;
            }
        }
        
        /* Stile per la versione di stampa */
        @media print {
            .no-print {
                display: none !important;
            }
            .container {
                width: 100%;
                max-width: 100%;
                padding: 0;
                margin: 0;
            }
            body {
                padding: 0;
                margin: 0;
                font-size: 12pt;
            }
            .appointment-details {
                page-break-inside: avoid;
            }
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.8.1/font/bootstrap-icons.min.css">
</head>
<body>
    <div class="container">
        <div class="row mb-3 no-print">
            <div class="col text-start">
                <a href="dashboard.php" class="btn btn-light dashboard-button">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </a>
            </div>
            <div class="col text-end">
                <a href="today.php?logout=true" class="btn btn-light logout-button">
                    <i class="bi bi-x-circle"></i> Esci
                </a>
            </div>
        </div>
        <div class="navigation no-print">
            <a href="today.php?date=<?php echo date('Y-m-d', strtotime($selectedDate . ' -1 day')); ?>" class="btn btn-secondary">&lt;</a>
            <h1 style="padding:0 5rem;"><?php echo $isToday ? "Appuntamenti di Oggi" : "Appuntamenti del $displayDate"; ?></h1>
            <a href="today.php?date=<?php echo date('Y-m-d', strtotime($selectedDate . ' +1 day')); ?>" class="btn btn-secondary">&gt;</a>
        </div>
        
        <!-- Pulsanti per PDF e invio email -->
        <div class="row mb-3 no-print">
            <div class="col-12 text-end">
                <a id="printPdfButton" href="today.php?date=<?php echo $selectedDate; ?>&pdf=1" target="_blank" class="btn print-button">
                    <i class="bi bi-printer-fill"></i> Scarica PDF
                </a>
                <button id="emailPdfButton" class="btn email-pdf-button" data-bs-toggle="modal" data-bs-target="#emailPdfModal">
                    <i class="bi bi-envelope-fill"></i> Invia PDF via email
                </button>
            </div>
        </div>
        
        <div id="appointments-content">
            <!-- Aggiungiamo un titolo visibile nelle stampe -->
            <div class="d-none d-print-block text-center mb-4">
                <h2><?php echo $isToday ? "Appuntamenti di Oggi" : "Appuntamenti del $displayDate"; ?></h2>
            </div>
            
            <?php if (empty($appointments)): ?>
                <p class="text-center">Nessun appuntamento registrato</p>
            <?php else: ?>
                <?php foreach ($appointments as $appointment): ?>
                    <div class="appointment-details">
                        <p class="appointment-time"><?php echo date('H:i', strtotime($appointment['appointment_time'])); ?></p>
                        <p><span class="name"><?php echo $appointment['name']; ?></span> <span class="surname"><?php echo $appointment['surname']; ?></span></p>
                        <p>
                            <span><?php echo $appointment['phone']; ?></span>
                            <a href="tel:<?php echo $appointment['phone']; ?>" class="btn call-button no-print"><i class="bi bi-telephone-fill"></i>Chiama</a>
                        </p>
                        <p>
                            <?php echo $appointment['address']; ?>
                            <a href="#" class="btn map-button no-print" data-address="<?php echo urlencode($appointment['address']); ?>"><i class="bi bi-geo-alt-fill"></i>Apri in Mappe</a>
                        </p>
                        <?php if (!empty($appointment['notes'])): ?>
                            <p><strong>Note:</strong> <?php echo $appointment['notes']; ?></p>
                        <?php endif; ?>
                    </div>
                    <hr>
                <?php endforeach; ?>
                <div class="container mt-5 no-print">
                    <h2>Genera l'itinerario per oggi</h2>
                    <button id="openMapButton" style="margin:0 auto 2rem auto;" class="btn btn-success mt-3"><i class="bi bi-geo-alt-fill"></i>Apri l'itinerario in Mappe</button>
                    <hr>
                    <h2>Invia l'itinerario per email</h2>
                    <div id="emailGroup" class="container mt-3">
                        <?php if ($_SESSION['user_id'] == 9 || $_SESSION['user_id'] == 6): ?>
                            <!-- Versione semplificata per utenti specifici -->
                          <div class="row">
  <div class="col-md-12 mb-3" id="userSpecificSection">
    <?php if ($_SESSION['user_id'] == 9): ?>
        <p class="lead">Antonella, puoi inviarti l'itinerario cliccando sul pulsante INVIA.</p>
        <input type="hidden" id="email" value="corbellini@tiscali.it">
        <input type="hidden" id="formatGoogle" value="0">
        <input type="hidden" id="formatApple" value="1">
        <!-- Aggiungi debug hidden per monitorare i valori delle URL -->
        <input type="hidden" id="debugMapGoogle" value="">
        <input type="hidden" id="debugMapApple" value="">
    <?php elseif ($_SESSION['user_id'] == 6): ?>
        <p class="lead">Angelo, puoi inviarti l'itinerario cliccando sul pulsante INVIA.</p>
        <input type="hidden" id="email" value="aleonforte@tiscali.it">
        <input type="hidden" id="formatGoogle" value="1">
        <input type="hidden" id="formatApple" value="0">
        <!-- Aggiungi debug hidden per monitorare i valori delle URL -->
        <input type="hidden" id="debugMapGoogle" value="">
        <input type="hidden" id="debugMapApple" value="">
    <?php endif; ?>
    <div class="input-group mt-2">
        <button id="sendEmail" class="btn btn-primary email-button" style="width: auto; padding: 0.375rem 0.75rem;">
            <i class="bi bi-envelope-fill"></i>INVIA
        </button>
    </div>
    <div class="mt-3">
        <a href="#" id="showFullForm">Vuoi inviare l'itinerario a qualcun altro?</a>
    </div>
</div>
</div>
                            <!-- Form completa nascosta -->
                            <div class="row" id="fullFormSection" style="display: none;">
                                <div class="col-md-12 mb-3">
                                    <input type="email" id="fullEmail" placeholder="Inserisci email" class="form-control">
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" id="fullFormatGoogle" value="google">
                                        <label class="form-check-label" for="fullFormatGoogle">Google Maps</label>
                                    </div>
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" id="fullFormatApple" value="apple">
                                        <label class="form-check-label" for="fullFormatApple">Apple Maps</label>
                                    </div>
                                    <div class="input-group mt-2">
                                        <button id="sendFullEmail" class="btn btn-primary email-button">
                                            <i class="bi bi-envelope-fill"></i>Invia URL
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <!-- Versione originale per altri utenti -->
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
                                        <button id="sendEmail" class="btn btn-primary email-button">
                                            <i class="bi bi-envelope-fill"></i>Invia URL
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal per l'invio del PDF via email -->
    <div class="modal fade" id="emailPdfModal" tabindex="-1" aria-labelledby="emailPdfModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="emailPdfModalLabel">Invia PDF via email</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="pdfEmailAddress" class="form-label">Indirizzo email</label>
                        <input type="email" class="form-control" id="pdfEmailAddress" placeholder="nome@esempio.it" required>
                    </div>
                    <div class="mb-3">
                        <label for="pdfEmailSubject" class="form-label">Oggetto</label>
                        <input type="text" class="form-control" id="pdfEmailSubject" value="Appuntamenti del <?php echo $displayDate; ?>">
                    </div>
                    <div class="mb-3">
                        <label for="pdfEmailMessage" class="form-label">Messaggio</label>
                        <textarea class="form-control" id="pdfEmailMessage" rows="3">In allegato trovi il PDF degli appuntamenti del giorno <?php echo $displayDate; ?>.</textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="button" class="btn btn-primary" id="sendPdfEmail">Invia</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    
    document.addEventListener('DOMContentLoaded', function() {
    // Creiamo una funzione che è sicuro eseguire solo quando ci sono appuntamenti
    function initializeMapUrls() {
    var appointments = <?php echo json_encode($appointments); ?>;
    if (appointments.length > 0) {
        let waypoints = appointments.map(appointment => appointment.address);
        
        // Ensure at least a start and end point
        let start = "Current+Location";
        let end = waypoints.pop(); // Last waypoint as end
        
        // Generate Apple Maps URL using +to: format
        window.mapUrlApple = `maps://?saddr=${start}&daddr=${waypoints.map(waypoint => encodeURIComponent(waypoint)).join('+to:')}+to:${encodeURIComponent(end)}&dirflg=d`;
        
        // Generate Google Maps URL - FIXED COMPLETE URL
        window.mapUrlGoogle = `https://www.google.com/maps/dir/?api=1&origin=${start}&destination=${encodeURIComponent(end)}&waypoints=${waypoints.map(waypoint => encodeURIComponent(waypoint)).join('|')}&travelmode=driving`;
        
        // Usare le stesse variabili anche nell'ambito principale
        mapUrlGoogle = window.mapUrlGoogle;
        mapUrlApple = window.mapUrlApple;
        
        console.log("URL mappe generate:");
        console.log("Google:", window.mapUrlGoogle);
        console.log("Apple:", window.mapUrlApple);
        
        // Aggiorniamo i campi debug hidden
        if (document.getElementById('debugMapGoogle')) {
            document.getElementById('debugMapGoogle').value = window.mapUrlGoogle;
        }
        if (document.getElementById('debugMapApple')) {
            document.getElementById('debugMapApple').value = window.mapUrlApple;
        }
        
        // Abilita i pulsanti ora che le URL sono pronte
        document.getElementById('openMapButton').style.display = 'block';
        document.getElementById('emailGroup').style.display = 'block';
    } else {
        document.getElementById('openMapButton').style.display = 'none';
        document.getElementById('emailGroup').style.display = 'none';
    }
}
    
    // Chiama la funzione di inizializzazione
    initializeMapUrls();
    
    // Configura tutti i listener degli eventi dei pulsanti
    function setupButtonListeners() {
        // Codice esistente per i listener...
    }
});
    document.addEventListener('DOMContentLoaded', function() {
        var appointments = <?php echo json_encode($appointments); ?>;
        var mapUrlGoogle = '';
        var mapUrlApple = '';

        // Gestione della visualizzazione della form completa per utenti specifici
        if (document.getElementById('showFullForm')) {
            document.getElementById('showFullForm').addEventListener('click', function(e) {
                e.preventDefault();
                document.getElementById('userSpecificSection').style.display = 'none';
                document.getElementById('fullFormSection').style.display = 'block';
                
                // Azzera i campi della form completa
                document.getElementById('fullEmail').value = '';
                document.getElementById('fullFormatGoogle').checked = false;
                document.getElementById('fullFormatApple').checked = false;
            });
        }

        // Gestione del bottone "sendFullEmail" per la form completa
        // Sostituire la funzione sendFullEmail esistente (righe 514-563) con questa versione aggiornata:
if (document.getElementById('sendFullEmail')) {
    document.getElementById('sendFullEmail').addEventListener('click', function() {
        const email = document.getElementById('fullEmail').value;
        const formatGoogle = document.getElementById('fullFormatGoogle').checked;
        const formatApple = document.getElementById('fullFormatApple').checked;

        if (!email) {
            alert('Inserisci un indirizzo email valido.');
            return;
        }

        if (!formatGoogle && !formatApple) {
            alert('Seleziona almeno un formato per l\'URL delle mappe.');
            return;
        }
        
        // Variabili per il testo dinamico
        let emailSubject;
        let dateText;
        
        // Controlla se è oggi o un altro giorno
        if ("<?php echo $isToday; ?>" === "1") {
            emailSubject = "Itinerario per gli appuntamenti di oggi";
            dateText = "di oggi";
        } else {
            // Formato del giorno e mese in italiano
            const dateParts = "<?php echo $displayDate; ?>".split('-');
            const day = parseInt(dateParts[0]);
            
            // Array dei nomi dei mesi in italiano
            const mesi = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 
                        'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];
            
            // Il mese in PHP è 1-based, quindi sottraiamo 1 per ottenere l'indice corretto
            const month = mesi[parseInt(dateParts[1]) - 1];
            
            const formattedDate = day + " " + month;
            emailSubject = "Itinerario per gli appuntamenti del giorno " + formattedDate;
            dateText = "del giorno " + formattedDate;
        }
        
       // Costruisci il messaggio usando il testo dinamico
let message = "Ciao,\n\nEcco l'URL dell'itinerario per i tuoi appuntamenti " + dateText + ":\n\n";
if (formatGoogle) {
    // Usa la variabile globale window.mapUrlGoogle
    message += "**APRI IN GOOGLE MAPS**\n" + window.mapUrlGoogle + "\n\n";
}
if (formatApple) {
    // Usa la variabile globale window.mapUrlApple
    message += "**APRI IN MAPPE APPLE**\n" + window.mapUrlApple + "\n\n";
}
        message += "Cordiali saluti,\nIl Team degli Appuntamenti";

        // Send email using fetch API
        fetch('send_email.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ 
                email: email, 
                subject: emailSubject, 
                message: message 
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Email inviata con successo.');
                // Ripristina la vista originale
                document.getElementById('userSpecificSection').style.display = 'block';
                document.getElementById('fullFormSection').style.display = 'none';
            } else {
                alert('Errore nell\'invio dell\'email.');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Errore nell\'invio dell\'email.');
        });
    });
}

        if (appointments.length > 0) {
    let waypoints = appointments.map(appointment => appointment.address);
    
    // Ensure at least a start and end point
    let start = "Current+Location";
    let end = waypoints.pop(); // Last waypoint as end
    
    // Generate Apple Maps URL using +to: format
    mapUrlApple = `maps://?saddr=${start}&daddr=${waypoints.map(waypoint => encodeURIComponent(waypoint)).join('+to:')}+to:${encodeURIComponent(end)}&dirflg=d`;
    
    // Complete Google Maps URL
    mapUrlGoogle = `https://www.google.com/maps/dir/?api=1&origin=${start}&destination=${encodeURIComponent(end)}&waypoints=${waypoints.map(waypoint => encodeURIComponent(waypoint)).join('|')}&travelmode=driving`;

    console.log("DEBUG - URL generate:");
    console.log("Google:", mapUrlGoogle);
    console.log("Apple:", mapUrlApple);
    
    document.getElementById('openMapButton').style.display = 'block';
    document.getElementById('emailGroup').style.display = 'block';
} else {
    document.getElementById('openMapButton').style.display = 'none';
    document.getElementById('emailGroup').style.display = 'none';
}


document.getElementById('sendEmail').addEventListener('click', function() {
    const emailField = document.getElementById('email');
    const email = emailField.value;
    
    // Debug aggiuntivo
    console.log("Tipo elemento email:", emailField.type);
    console.log("Valore email:", email);
    
    // Gestione checkbox per utenti specifici (ID 6 e 9)
    let formatGoogle, formatApple;
    if (emailField.type === 'hidden') {
        // Per utenti con form precompilata, leggi i valori dagli input hidden
        formatGoogle = document.getElementById('formatGoogle').value === '1';
        formatApple = document.getElementById('formatApple').value === '1';
        console.log("Form precompilata - valori originali:", 
                  document.getElementById('formatGoogle').value,
                  document.getElementById('formatApple').value);
    } else {
        // Per tutti gli altri utenti, leggi lo stato dei checkbox
        formatGoogle = document.getElementById('formatGoogle').checked;
        formatApple = document.getElementById('formatApple').checked;
    }

    console.log("Debug - Email:", email);
    console.log("Debug - Format Google:", formatGoogle);
    console.log("Debug - Format Apple:", formatApple);

    // Verifiche dei dati
    if (!email) {
        alert('Inserisci un indirizzo email valido.');
        console.error("Email mancante");
        return;
    }

    if (!formatGoogle && !formatApple) {
        alert('Seleziona almeno un formato per l\'URL delle mappe.');
        console.error("Nessun formato selezionato");
        return;
    }
    
    // Variabili per il testo dinamico
    let emailSubject;
    let dateText;
    
    // Controlla se è oggi o un altro giorno
    if ("<?php echo $isToday; ?>" === "1") {
        emailSubject = "Itinerario per gli appuntamenti di oggi";
        dateText = "di oggi";
    } else {
        // Formato del giorno e mese in italiano
        const dateParts = "<?php echo $displayDate; ?>".split('-');
        const day = parseInt(dateParts[0]);
        
        // Array dei nomi dei mesi in italiano
        const mesi = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 
                     'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];
        
        // Il mese in PHP è 1-based, quindi sottraiamo 1 per ottenere l'indice corretto
        const month = mesi[parseInt(dateParts[1]) - 1];
        
        const formattedDate = day + " " + month;
        emailSubject = "Itinerario per gli appuntamenti del giorno " + formattedDate;
        dateText = "del giorno " + formattedDate;
    }
    
    // Debug del mapUrl - verifichiamo che le variabili mapUrlGoogle e mapUrlApple esistano
    console.log("mapUrlGoogle disponibile:", typeof mapUrlGoogle !== 'undefined');
    console.log("mapUrlApple disponibile:", typeof mapUrlApple !== 'undefined');
    
    if (typeof mapUrlGoogle === 'undefined' || typeof mapUrlApple === 'undefined') {
        console.error("ERRORE: URL mappe non disponibili");
        alert("Errore: URL mappe non disponibili. Riprovare più tardi.");
        return;
    }
    
    // Costruisci il messaggio usando il testo dinamico
    let message = "Ciao,\n\nEcco l'URL dell'itinerario per i tuoi appuntamenti " + dateText + ":\n\n";
    if (formatGoogle) {
        message += "**APRI IN GOOGLE MAPS**\n" + mapUrlGoogle + "\n\n";
    }
    if (formatApple) {
        message += "**APRI IN MAPPE APPLE**\n" + mapUrlApple + "\n\n";
    }
    message += "Cordiali saluti,\nIl Team degli Appuntamenti";

    // Debug dei dati da inviare
    const emailData = { 
        email: email, 
        subject: emailSubject, 
        message: message 
    };
    console.log("Dati da inviare:", emailData);

    // Send email using fetch API
    fetch('send_email.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(emailData)
    })
    .then(response => {
        console.log("Status risposta:", response.status);
        return response.json();
    })
    .then(data => {
        console.log("Risposta completa dal server:", data);
        if (data.success) {
            alert('Email inviata con successo.');
        } else {
            alert('Errore nell\'invio dell\'email: ' + (data.error || 'motivo sconosciuto'));
        }
    })
    .catch(error => {
        console.error('Error completo:', error);
        alert('Errore nell\'invio dell\'email: ' + error.message);
    });
});



        document.getElementById('openMapButton').addEventListener('click', function() {
            openMap(mapUrlGoogle, mapUrlApple);
        });

        document.querySelectorAll('.map-button').forEach(button => {
            button.addEventListener('click', function() {
                const address = this.getAttribute('data-address');
                const googleUrl = `https://www.google.com/maps/search/?api=1&query=${address}`;
                const appleUrl = `maps://?q=${address}`;
                openMap(googleUrl, appleUrl);
            });
        });

        function openMap(googleUrl, appleUrl) {
            const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
            if (isIOS) {
                window.open(appleUrl, '_blank');
            } else {
                window.open(googleUrl, '_blank');
            }
        }

        // Email PDF function
        document.getElementById('sendPdfEmail').addEventListener('click', function() {
            const email = document.getElementById('pdfEmailAddress').value;
            const subject = document.getElementById('pdfEmailSubject').value;
            const message = document.getElementById('pdfEmailMessage').value;
            
            if (!email) {
                alert('Inserisci un indirizzo email valido.');
                return;
            }
            
            // Close the modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('emailPdfModal'));
            modal.hide();
            
            // Show loading message
            const loadingDiv = document.createElement('div');
            loadingDiv.id = 'loadingMessage';
            loadingDiv.style.position = 'fixed';
            loadingDiv.style.top = '50%';
            loadingDiv.style.left = '50%';
            loadingDiv.style.transform = 'translate(-50%, -50%)';
            loadingDiv.style.padding = '20px';
            loadingDiv.style.backgroundColor = 'rgba(0,0,0,0.7)';
            loadingDiv.style.color = 'white';
            loadingDiv.style.borderRadius = '5px';
            loadingDiv.style.zIndex = '9999';
            loadingDiv.innerHTML = '<div class="text-center"><div class="spinner-border text-light" role="status"></div><div class="mt-2">Generazione e invio del PDF in corso...</div></div>';
            document.body.appendChild(loadingDiv);

            // First generate the PDF and then send it via email
            fetch('today.php?date=<?php echo $selectedDate; ?>&pdf=1')
                .then(response => response.blob())
                .then(blob => {
                    // Create form data to send the PDF
                    const formData = new FormData();
                    formData.append('pdf', new File([blob], 'appuntamenti-<?php echo $displayDate; ?>.pdf', { type: 'application/pdf' }));
                    formData.append('email', email);
                    formData.append('subject', subject);
                    formData.append('message', message);
                    
                    // Send the PDF via email
                    return fetch('send_pdf_email.php', {
                        method: 'POST',
                        body: formData
                    });
                })
                .then(response => response.json())
                .then(data => {
                    // Remove loading message
                    document.getElementById('loadingMessage').remove();
                    
                    if (data.success) {
                        alert('PDF inviato con successo via email.');
                    } else {
                        alert('Errore nell\'invio del PDF: ' + (data.error || 'errore sconosciuto'));
                    }
                })
                .catch(error => {
                    // Remove loading message
                    document.getElementById('loadingMessage').remove();
                    console.error('Error:', error);
                    alert('Errore nella generazione o nell\'invio del PDF.');
                });
        });
    });
    </script>
</body>
</html>
