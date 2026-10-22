<?php
// Copie ce fichier sous le nom config.php, puis remplis les deux valeurs.
return [
    // Code que la secrétaire tape une seule fois (ou qu'elle reçoit dans le lien ?code=...).
    'code_acces' => 'change-moi',

    // Texte aléatoire et long, utilisé pour signer le cookie de connexion. Ne le communique à personne.
    'secret' => 'remplace-moi-par-une-longue-phrase-au-hasard',

    // Jeton du robot qui crée les expéditions chez les transporteurs (le même dans le .env du robot).
    // Au moins 24 caractères au hasard ; vide = robot désactivé.
    'jeton_robot' => '',
];
