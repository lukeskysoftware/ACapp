<?php
// Connessione al database
require_once 'db.php';

// Inizializza l'output
$output = '';

// Verifica se è stata passata una data
if(isset($_POST['appointment_date']) && !empty($_POST['appointment_date'])) {
    // Prendi la data dalla richiesta POST
    $appointment_date = $_POST['appointment_date'];
    
    // Formatta correttamente la data per MySQL (Y-m-d)
    // Questo è fondamentale perché MySQL confronta le date in questo formato
    $formatted_date = date('Y-m-d', strtotime($appointment_date));
    
    // Query SQL per ottenere gli appuntamenti della data selezionata
    // JOIN con cp_patients per ottenere i dati del paziente
    // JOIN opzionale con cp_zones per ottenere la zona (se necessario)
    $sql = "SELECT a.id, a.appointment_date, a.appointment_time, a.notes, a.address, 
                  p.name, p.surname, p.phone, z.name AS zone_name
           FROM cp_appointments a
           JOIN cp_patients p ON a.patient_id = p.id
           LEFT JOIN cp_zones z ON a.zone_id = z.id
           WHERE a.appointment_date = '$formatted_date' 
           ORDER BY a.appointment_time ASC";
    
    // Esegui la query
    $result = $conn->query($sql);
    
    // Controlla se ci sono appuntamenti
    if($result && $result->num_rows > 0) {
        // Inizia il container degli appuntamenti
        $output .= '<div class="appointments-container">';
        
        // Cicla attraverso gli appuntamenti trovati
        while($row = $result->fetch_assoc()) {
            // Formatta l'ora per la visualizzazione (formato H:i)
            $time = date('H:i', strtotime($row['appointment_time']));
            
            // Costruisci l'HTML per ogni appuntamento
            $output .= '<div class="appointment-item">';
            $output .= '<div class="appointment-time">' . $time . '</div>';
            $output .= '<div class="appointment-details">';
            $output .= '<div class="appointment-name">' . $row['name'] . ' ' . $row['surname'] . '</div>';
            $output .= '<div class="appointment-phone">' . $row['phone'] . '</div>';
            
            // Mostra l'indirizzo se disponibile
            if (!empty($row['address'])) {
                $output .= '<div class="appointment-address">' . $row['address'] . '</div>';
            }
            
            // Mostra le note se disponibili
            if (!empty($row['notes'])) {
                $output .= '<div class="appointment-notes">' . $row['notes'] . '</div>';
            }
            
            // Mostra la zona se disponibile
            if (!empty($row['zone_name'])) {
                $output .= '<div class="appointment-zone">Zona: ' . $row['zone_name'] . '</div>';
            }
            
            $output .= '</div>'; // Chiudi appointment-details
            $output .= '</div>'; // Chiudi appointment-item
        }
        
        // Chiudi il container degli appuntamenti
        $output .= '</div>';
    } else {
        // Non ci sono appuntamenti per questa data
        $output .= '<div class="no-appointments">Non ci sono appuntamenti registrati per questa data.</div>';
    }
} else {
    // Non è stata fornita una data
    $output .= '<div class="error-message">Errore: data non specificata.</div>';
}

// Chiudi la connessione al database
$conn->close();

// Restituisci l'output HTML
echo $output;
?>
