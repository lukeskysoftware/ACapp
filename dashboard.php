<?php
include 'menu.php';
?>

<!DOCTYPE html>
<html>
<head>
    <title>Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/purecss@3.0.0/build/pure-min.css" integrity="sha384-X38yfunGUhNzHpBaEBsWLO+A0HDYOQi8ufWDkZ0k9e0eXz/tH3II7uKZ9msv++Ls" crossorigin="anonymous">
    <link rel="stylesheet" href="styles.css">
    <style>
        .modifica-btn {
            background-color: green;
            color: white;
        }
        .cancella-btn {
            background-color: red;
            color: white;
        }
        .confirm-btn {
            background-color: darkred;
            color: white;
        }
        .chiudi-btn {
            background-color: grey;
            color: white;
        }
        .hidden {
            display: none;
        }
        
        /* Nuovi stili per il contenitore centrale */
        .container {
            margin-top: 3em;
        }
        
        .card {
            padding: 2em;
            border: none; /* Rimuove il bordo */
            box-shadow: none; /* Rimuove l'ombra */
        }
        
        /* Classi per mantenere lo stile di layout senza Bootstrap */
        .row {
            display: flex;
            justify-content: center;
        }
        
        .col-md-8 {
            width: 66.66%;
        }
        
        .text-center {
            text-align: center;
        }
    </style>
</head>
<body>
  
    <div class="container">
        <div class="row">
            <div class="col-md-8">
                <div class="card">
                    <h2 class="text-center">Gestione Appuntamenti ACapp</h2>
                    <p class="text-center">Seleziona un'attività dal menu.</p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
