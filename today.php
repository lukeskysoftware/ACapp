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
    <!-- Aggiungiamo html2pdf.js -->
    <!-- Alternative with updated integrity hash -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" 
        integrity="sha512-GsLlZN/3F2ErC5ifS5QtgpiJtWd43JWSuIgh7mbzZ8zBps+dvLusV+eNQATqgA/HdeKFVgA5v3S/cIrLF7QnIg==" 
        crossorigin="anonymous"></script>
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
            <h1><?php echo $isToday ? "Appuntamenti di Oggi" : "Appuntamenti del $displayDate"; ?></h1>
            <a href="today.php?date=<?php echo date('Y-m-d', strtotime($selectedDate . ' +1 day')); ?>" class="btn btn-secondary">&gt;</a>
        </div>
        
        <!-- Aggiungiamo i pulsanti per stampa e invio email -->
        <div class="row mb-3 no-print">
            <div class="col-12 text-end">
                <button id="printPdfButton" class="btn print-button">
                    <i class="bi bi-printer-fill"></i> Stampa PDF
                </button>
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
                                    <button id="sendEmail" class="btn btn-primary email-button"><i class="bi bi-envelope-fill"></i>Invia URL</button>
                                </div>
                            </div>
                        </div>
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

    <!-- File PHP di supporto per generare il PDF (generatePDF.php) -->
    <?php
    // Devi creare questo file a parte
    ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var appointments = <?php echo json_encode($appointments); ?>;
            var mapUrlGoogle = '';
            var mapUrlApple = '';

            if (appointments.length > 0) {
                let waypoints = appointments.map(appointment => appointment.address);
                
                // Ensure at least a start and end point
                let start = "Current+Location";
                let end = waypoints.pop(); // Last waypoint as end
                let intermediateWaypoints = waypoints.map(waypoint => `&daddr=${encodeURIComponent(waypoint)}`).join('');

                // Generate Apple Maps URL using +to: format
                mapUrlApple = `maps://?saddr=${start}&daddr=${waypoints.map(waypoint => encodeURIComponent(waypoint)).join('+to:')}+to:${encodeURIComponent(end)}&dirflg=d`;
                // Fix for truncated Google Maps URL
                mapUrlGoogle = `https://www.google.com/maps/dir/?api=1&origin=${start}&destination=${encodeURIComponent(end)}&waypoints=${waypoints.map(waypoint => encodeURIComponent(waypoint)).join('|')}&travelmode=driving`;

                document.getElementById('openMapButton').style.display = 'block';
                document.getElementById('emailGroup').style.display = 'block';
            } else {
                document.getElementById('openMapButton').style.display = 'none';
                document.getElementById('emailGroup').style.display = 'none';
            }

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

                let message = "Ciao,\n\nEcco l'URL dell'itinerario per i tuoi appuntamenti del giorno " + "<?php echo $displayDate; ?>" + ":\n\n";
                if (formatGoogle) {
                    message += "**APRI IN GOOGLE MAPS**\n" + mapUrlGoogle + "\n\n";
                }
                if (formatApple) {
                    message += "**APRI IN MAPPE APPLE**\n" + mapUrlApple + "\n\n";
                }
                message += "Cordiali saluti,\nIl Team degli Appuntamenti";

                sendEmail(email, "Itinerario per gli appuntamenti del giorno " + "<?php echo $displayDate; ?>", message);
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
               // Helper function to process HTML entities
function processHtmlEntities(element) {
    // Process all text nodes in the element
    const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT, null, false);
    let node;
    while (node = walker.nextNode()) {
        // Replace encoded entities with actual spaces
        const textContent = node.textContent;
        if (textContent.includes('&nbsp;') || textContent.includes('&amp;') || 
            textContent.includes('&lt;') || textContent.includes('&gt;')) {
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = textContent;
            node.textContent = tempDiv.textContent;
        }
    }
    
    // Apply proper spacing styles to all elements
    const allElements = element.querySelectorAll('*');
    allElements.forEach(el => {
        el.style.wordSpacing = 'normal';
        el.style.letterSpacing = 'normal';
        el.style.wordBreak = 'break-word';
    });
}
            
            // Funzione per generare il PDF
            function generatePDF() {
                // Preparazione contenuto per il PDF (clonare il contenuto degli appuntamenti)
                const element = document.getElementById('appointments-content');
                const elementClone = element.cloneNode(true);
                
                // Visualizza gli elementi nascosti per la stampa
                elementClone.querySelectorAll('.d-print-block').forEach(el => {
                    el.classList.remove('d-none');
                });
                
                // Nascondi gli elementi che non devono apparire nel PDF
                elementClone.querySelectorAll('.no-print').forEach(el => {
                    el.style.display = 'none';
                });
                
                // Process HTML entities to fix spacing issues
                processHtmlEntities(elementClone);
                
                // Configurazione html2pdf
                const opt = {
                    margin: 10,
                    filename: 'appuntamenti-<?php echo $displayDate; ?>.pdf',
                    image: { type: 'jpeg', quality: 0.98 },
                    html2canvas: { 
                        scale: 2,
                        letterRendering: true,
                        useCORS: true
                    },
                    jsPDF: { 
                        unit: 'mm', 
                        format: 'a4', 
                        orientation: 'portrait',
                        compress: false
                    }
                };
                
                // Genera il PDF
                return html2pdf().set(opt).from(elementClone).save();
            }
            
            // Evento per il pulsante di stampa PDF
            document.getElementById('printPdfButton').addEventListener('click', function() {
                generatePDF();
            });
            
            // Evento per l'invio del PDF via email
            document.getElementById('sendPdfEmail').addEventListener('click', function() {
                const email = document.getElementById('pdfEmailAddress').value;
                const subject = document.getElementById('pdfEmailSubject').value;
                const message = document.getElementById('pdfEmailMessage').value;
                
                if (!email) {
                    alert('Inserisci un indirizzo email valido.');
                    return;
                }
                
                // Chiudi il modal
                const modal = bootstrap.Modal.getInstance(document.getElementById('emailPdfModal'));
                modal.hide();
                
                // Mostra messaggio di caricamento
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
                
                // Genera il PDF e invialo per email
                generatePDFForEmail(email, subject, message);
            });
            
            // Genera il PDF per l'email
            function generatePDFForEmail(email, subject, message) {
                // Preparazione contenuto per il PDF
                const element = document.getElementById('appointments-content');
                const elementClone = element.cloneNode(true);
                
                // Visualizza gli elementi nascosti per la stampa
                elementClone.querySelectorAll('.d-print-block').forEach(el => {
                    el.classList.remove('d-none');
                });
                
                // Nascondi gli elementi che non devono apparire nel PDF
                elementClone.querySelectorAll('.no-print').forEach(el => {
                    el.style.display = 'none';
                });
                
                // Process HTML entities to fix spacing issues
                processHtmlEntities(elementClone);
                
                
                // Configurazione html2pdf
                const opt = {
                    margin: 10,
                    filename: 'appuntamenti-<?php echo $displayDate; ?>.pdf',
                    image: { type: 'jpeg', quality: 0.98 },
                    html2canvas: { 
                        scale: 2,
                        letterRendering: true,
                        useCORS: true
                    },
                    jsPDF: { 
                        unit: 'mm', 
                        format: 'a4', 
                        orientation: 'portrait',
                        compress: false
                    }
                };
                
                // Genera il PDF come blob
                html2pdf().set(opt).from(elementClone).outputPdf('blob').then(function(pdfBlob) {
                    // Crea un FormData e allega il PDF
                    const formData = new FormData();
                    formData.append('pdf', pdfBlob, 'appuntamenti-<?php echo $displayDate; ?>.pdf');
                    formData.append('email', email);
                    formData.append('subject', subject);
                    formData.append('message', message);
                    
                    // Invia il PDF via email usando una richiesta AJAX
                    fetch('send_pdf_email.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        // Rimuovi il messaggio di caricamento
                        document.getElementById('loadingMessage').remove();
                        
                        if (data.success) {
                            alert('PDF inviato con successo via email.');
                        } else {
                            alert('Errore nell\'invio del PDF: ' + data.error);
                        }
                    })
                    .catch(error => {
                        // Rimuovi il messaggio di caricamento
                        document.getElementById('loadingMessage').remove();
                        console.error('Error:', error);
                        alert('Errore nell\'invio del PDF via email.');
                    });
                });
            }
        });
    </script>
</body>
</html>
