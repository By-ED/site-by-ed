<?php
// Copie ce fichier sous le nom config.php, puis remplis les trois premières valeurs.
return [
    // Clé API Anthropic (console.anthropic.com > API Keys). Elle commence par "sk-ant-".
    'cle_api' => 'sk-ant-...',

    // Code que ta sœur tape une seule fois sur son téléphone (ou qu'elle reçoit dans le lien ?code=...).
    'code_acces' => 'change-moi',

    // Texte aléatoire et long, utilisé pour signer le cookie de connexion. Ne le communique à personne.
    'secret' => 'remplace-moi-par-une-longue-phrase-au-hasard',

    // Modèle Claude utilisé pour créer les questions.
    'modele' => 'claude-opus-5-5',

    // Niveau de réflexion de Claude pour les questions : low, medium, high.
    'effort' => 'high',

    // Nombre maximum d'appels à Claude par jour (protège contre une facture imprévue).
    'appels_max_par_jour' => 60,

    // Prix du modèle en dollars par million de jetons, pour afficher une estimation du coût.
    'prix' => ['entree' => 4.00, 'sortie' => 20.00, 'cache_ecriture' => 5.00, 'cache_lecture' => 0.20],
];
