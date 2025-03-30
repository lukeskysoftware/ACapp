<?php
include_once 'db.php';
require('ext_parts/fpdf/fpdf.php');

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

if (!isset($_SESSION['user_id'])) {
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

    $pdf->Output('D', 'appuntamenti.pdf');
}

if (isset($_GET['action']) && $_GET['action'] === 'generate_pdf') {
    generatePDF($appointments, $displayDate);
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
</head>
<body>
    <div class="container">
        <!-- Barra superiore con pulsanti -->
        <div class="row mb-3 no-print">
            <div class="col-12 d-flex justify-content-between align-items-center flex-wrap">
                <a href="dashboard.php" class="btn btn-light dashboard-button">
                    <i class="bi bi-speedometer2 btn-icon"></i> Dashboard
                </a>
                
                <!-- Pulsanti di stampa e invio email -->
                <div class="d-flex action-buttons">
                    <a href="today.php?action=generate_pdf&date=<?php echo $selectedDate; ?>" class="btn btn-primary mx-1 print-button">
                        <i class="bi bi-printer btn-icon"></i> Stampa PDF
                    </a>
                </div>
                
                <a href="today.php?logout=true" class="btn btn-light logout-button">
                    <i class="bi bi-x-circle btn-icon"></i> Esci
                </a>
            </div>
        </div>
 
        <!-- Navigazione data -->
        <div class="navigation no-print">
            <a href="today.php?date=<?php echo date('Y-m-d', strtotime($selectedDate . ' -1 day')); ?>" class="btn btn-secondary">&lt;</a>
            <h1><?php echo $isToday ? "Appuntamenti di Oggi" : "Appuntamenti del $displayDate"; ?></h1>
            <a href="today.php?date=<?php echo date('Y-m-d', strtotime($selectedDate . ' +1 day')); ?>" class="btn btn-secondary">&gt;</a>
        </div>
        
        <!-- Contenuto degli appuntamenti -->
        <div id="appointments-content">
            <!-- Titolo visibile nelle stampe -->
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
                            <a href="tel:<?php echo $appointment['phone']; ?>" class="btn call-button no-print"><i class="bi bi-telephone-fill btn-icon"></i>Chiama</a>
                        </p>
                        <p>
                            <?php echo $appointment['address']; ?>
                            <a href="#" class="btn map-button no-print" data-address="<?php echo urlencode($appointment['address']); ?>"><i class="bi bi-geo-alt-fill btn-icon"></i>Apri in Mappe</a>
                        </p>
                        <?php if (!empty($appointment['notes'])): ?>
                            <p><strong>Note:</strong> <?php echo $appointment['notes']; ?></p>
                        <?php endif; ?>
                    </div>
                    <hr>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
