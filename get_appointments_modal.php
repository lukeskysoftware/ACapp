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
    $formatted_date = date('Y-m-d', strtotime($appointment_date));
    
    // Query SQL per ottenere gli appuntamenti della data selezionata
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
        // Formatta la data per la visualizzazione
        $display_date = date('d-m-Y', strtotime($formatted_date));
        
        // Aggiungi l'intestazione
        $output .= '<div class="container">';
        $output .= '<h4 class="mb-3">Appuntamenti del ' . $display_date . '</h4>';
        
        // Cicla attraverso gli appuntamenti trovati
        while($row = $result->fetch_assoc()) {
            // Formatta l'ora per la visualizzazione (formato H:i)
            $time = date('H:i', strtotime($row['appointment_time']));
            
            // Costruisci l'HTML per ogni appuntamento nello stile di today.php
            $output .= '<div class="appointment-details">';
            $output .= '<p class="appointment-time">' . $time . '</p>';
            $output .= '<p><span class="name">' . $row['name'] . '</span> <span class="surname">' . $row['surname'] . '</span></p>';
            $output .= '<p>' . $row['phone'] . '</p>';
            
            // Mostra l'indirizzo se disponibile
            if (!empty($row['address'])) {
                $output .= '<p>' . $row['address'] . '</p>';
            }
            
            // Mostra le note se disponibili
            if (!empty($row['notes'])) {
                $output .= '<p><strong>Note:</strong> ' . $row['notes'] . '</p>';
            }
            
            // Mostra la zona se disponibile
            if (!empty($row['zone_name'])) {
                $output .= '<p><strong>Zona:</strong> ' . $row['zone_name'] . '</p>';
            }
            
            $output .= '</div>';
            $output .= '<hr>';
        }
        
        $output .= '</div>'; // Chiudi il container
    } else {
        // Non ci sono appuntamenti per questa data
        $output .= '<div class="no-appointments text-center p-4">Non ci sono appuntamenti registrati per questa data.</div>';
    }
} else {
    // Non è stata fornita una data
    $output .= '<div class="error-message text-danger text-center p-4">Errore: data non specificata.</div>';
}

// Chiudi la connessione al database
$conn->close();

// Aggiungi stili CSS inline per la formattazione
$output .= '
<style>
    .appointment-time {
        font-weight: bold;
        font-size: 1.5rem;
    }
    .appointment-details {
        margin-bottom: 20px;
    }
    hr {
        border-top: 1px solid #ddd;
    }
    .name, .surname {
        font-weight: bold;
        font-size: 120%;
    }
    @media (max-width: 576px) {
        .appointment-time {
            font-size: 1.2rem;
        }
        .name, .surname {
            font-size: 100%;
        }
    }
</style>';

// Restituisci l'output HTML
echo $output;
?>
