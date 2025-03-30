<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include_once 'db.php';
include_once 'ext_parts/fpdf/fpdf.php';

session_start();

// Gestisci la disconnessione
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: today.php");
    exit;
}

// Gestisci la sottomissione del modulo di login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username']) && isset($_POST['password'])) {
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

// Funzione per generare il PDF
function generatePDF($appointments, $displayDate) {
    $pdf = new FPDF();
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, "Appuntamenti del $displayDate", 0, 1, 'C');
    $pdf->Ln(10);

    foreach ($appointments as $appointment) {
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 10, date('H:i', strtotime($appointment['appointment_time'])) . " - " . $appointment['name'] . " " . $appointment['surname'], 0, 1);
        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(0, 10, "Telefono: " . $appointment['phone'], 0, 1);
        $pdf->Cell(0, 10, "Indirizzo: " . $appointment['address'], 0, 1);
        if (!empty($appointment['notes'])) {
            $pdf->Cell(0, 10, "Note: " . $appointment['notes'], 0, 1);
        }
        $pdf->Ln(5);
    }

    $output = $pdf->Output('S');
    if (!$output) {
        echo "Error generating PDF";
        return false;
    }
    return $output;
}

// Funzione per inviare email con il PDF allegato
function sendEmailWithPDF($appointments, $displayDate, $recipientEmail) {
    $pdfContent = generatePDF($appointments, $displayDate);

    if (!$pdfContent) {
        return false;
    }

    $subject = "Appuntamenti del $displayDate";
    $message = "In allegato il PDF con gli appuntamenti del $displayDate.";
    $boundary = md5(uniqid(time()));

    $headers = "From: gestioneappuntamenti@ac.it\r\n";
    $headers .= "Reply-To: gestioneappuntamenti@ac.it\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n\r\n";

    $body = "--$boundary\r\n";
    $body .= "Content-Type: text/plain; charset=\"UTF-8\"\r\n";
    $body .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $body .= "$message\r\n\r\n";
    $body .= "--$boundary\r\n";
    $body .= "Content-Type: application/pdf; name=\"appuntamenti.pdf\"\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n";
    $body .= "Content-Disposition: attachment; filename=\"appuntamenti.pdf\"\r\n\r\n";
    $body .= chunk_split(base64_encode($pdfContent)) . "\r\n";
    $body .= "--$boundary--";

    return mail($recipientEmail, $subject, $body, $headers);
}

if (isset($_POST['action']) && $_POST['action'] === 'send_pdf') {
    // Ensure no output before JSON response
    ob_start();
    header('Content-Type: application/json');

    $recipientEmail = $_POST['email'];
    $success = sendEmailWithPDF($appointments, $displayDate, $recipientEmail);

    // Capture any unexpected output
    $output = ob_get_clean();
    if (!empty($output)) {
        echo json_encode(['success' => false, 'error' => 'Unexpected output: ' . $output]);
    } else {
        echo json_encode(['success' => $success]);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'generate_pdf') {
    $pdfContent = generatePDF($appointments, $displayDate);
    if ($pdfContent) {
        header('Content-Type: application/pdf');
        echo $pdfContent;
    } else {
        echo "Error generating PDF";
    }
    exit;
}
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
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.8.1/font/bootstrap-icons.min.css">
</head>
<body>
    <div class="container">
        <div class="row mb-3">
            <div class="col text-start">
                <a href="dashboard.php" class="btn btn-light dashboard-button">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </a>
            </div>
            <div class="col text-end">
                <a href="today.php?action=generate_pdf&date=<?php echo $selectedDate; ?>" class="btn btn-primary mx-1 print-button">
                    <i class="bi bi-printer"></i> Stampa PDF
                </a>
                <button id="emailPdfButton" class="btn btn-primary mx-1 email-button">
                    <i class="bi bi-envelope"></i> Invia PDF via Email
                </button>
                <a href="today.php?logout=true" class="btn btn-light logout-button">
                    <i class="bi bi-x-circle"></i> Esci
                </a>
            </div>
        </div>
        <div class="navigation">
            <a href="today.php?date=<?php echo date('Y-m-d', strtotime($selectedDate . ' -1 day')); ?>" class="btn btn-secondary">&lt;</a>
            <h1 style="margin:0 10%;"><?php echo $isToday ? "Appuntamenti di Oggi" : "Appuntamenti del $displayDate"; ?></h1>
            <a href="today.php?date=<?php echo date('Y-m-d', strtotime($selectedDate . ' +1 day')); ?>" class="btn btn-secondary">&gt;</a>
        </div>
        <?php if (empty($appointments)): ?>
            <p class="text-center">Nessun appuntamento registrato</p>
        <?php else: ?>
            <?php foreach ($appointments as $appointment): ?>
                <div class="appointment-details">
                    <p class="appointment-time"><?php echo date('H:i', strtotime($appointment['appointment_time'])); ?></p>
                    <p><span class="name"><?php echo $appointment['name']; ?></span> <span class="surname"><?php echo $appointment['surname']; ?></span></p>
                    <p><span><?php echo $appointment['phone']; ?></span>
                        <a href="tel:<?php echo $appointment['phone']; ?>" class="btn call-button"><i class="bi bi-telephone-fill"></i>Chiama</a>
                    </p>
                    <p><?php echo $appointment['address']; ?>
                        <a href="#" class="btn map-button" data-address="<?php echo urlencode($appointment['address']); ?>"><i class="bi bi-geo-alt-fill"></i>Apri in Mappe</a>
                    </p>
                    <?php if (!empty($appointment['notes'])): ?>
                        <p><strong>Note:</strong> <?php echo $appointment['notes']; ?></p>
                    <?php endif; ?>
                </div>
                <hr>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Modal for sending email -->
    <div class="modal fade" id="emailModal" tabindex="-1" aria-labelledby="emailModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="emailModalLabel">Invia PDF via Email</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="emailForm">
                        <div class="mb-3">
                            <label for="recipientEmail" class="form-label">Indirizzo Email</label>
                            <input type="email" class="form-control" id="recipientEmail" required>
                        </div>
                        <button type="submit" class="btn btn-primary">Invia</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('emailPdfButton').addEventListener('click', function() {
            var emailModal = new bootstrap.Modal(document.getElementById('emailModal'));
            emailModal.show();
        });

        document.getElementById('emailForm').addEventListener('submit', function(event) {
            event.preventDefault();
            var recipientEmail = document.getElementById('recipientEmail').value;

            fetch('send_email.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    email: recipientEmail,
                    url: window.location.href,
                    selectedDate: "<?php echo $selectedDate; ?>"
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Email inviata con successo.');
                } else {
                    alert('Errore nell\'invio dell\'email.');
                }
                var emailModal = bootstrap.Modal.getInstance(document.getElementById('emailModal'));
                emailModal.hide();
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Errore nell\'invio dell\'email.');
                var emailModal = bootstrap.Modal.getInstance(document.getElementById('emailModal'));
                emailModal.hide();
            });
        });
    </script>
</body>
</html>
