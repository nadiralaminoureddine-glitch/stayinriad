<?php
// StayInRiad — configuration privée
// À placer dans /home/stayinri/  (À CÔTÉ de public_html, PAS dedans).
// Remplissez les 3 valeurs de la base de données créée dans cPanel.
// Ne partagez jamais ce fichier, et n'envoyez jamais le mot de passe par message.
return [
    'db' => [
        'driver' => 'mysql',
        'host'   => 'localhost',
        'name'   => 'stayinri_app',        // nom complet de la base (cPanel ajoute « stayinri_ » devant)
        'user'   => 'stayinri_app',        // nom complet de l'utilisateur de la base
        'pass'   => 'METTEZ_ICI_LE_MOT_DE_PASSE_DE_LA_BASE',
    ],
    'timezone' => 'Africa/Casablanca',
    'debug'    => false,
];
