<?php
require('fpdf/fpdf.php');
include_once 'db.php';

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
$displayDate = $selectedDate === date('Y-m-d') ? "Oggi" : date('d-m-Y', strtotime($selectedDate));

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

if (empty($appointments)) {
    echo "No appointments found for the selected date.";
    exit;
}

$pdf->Output('D', 'appuntamenti.pdf');
?>
