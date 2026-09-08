<?php

declare(strict_types=1);

namespace AppTests\Fixture\Listener;

// Fichier PHP portant le marqueur AsEventListener mais ne déclarant AUCUNE
// classe : l'extraction de FQCN rend une chaîne vide et la découverte passe son
// chemin. La constante n'existe que pour que le fichier déclare quelque chose.
const ORPHAN_MARKER = 'aucune classe ici';
