<?php
session_start();

// Vérifier si la confirmation a été reçue
if (isset($_GET['confirm']) && $_GET['confirm'] === 'yes') {
    // Détruire toutes les variables de session
    $_SESSION = array();
    
    // Détruire le cookie de session si existant
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time()-3600, '/');
    }
    
    // Détruire la session
    session_destroy();
    
    // Empêcher la mise en cache pour éviter que l'utilisateur puisse revenir en arrière
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Rediriger vers la page de connexion
    header('Location: login.php');
    exit();
} else {
    // Si pas de confirmation, rediriger vers l'index
    header('Location: index.php');
    exit();
}
?>