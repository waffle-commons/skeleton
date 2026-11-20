# Politique de sécurité

Cette politique couvre chaque paquet Waffle : les dépôts `waffle-commons` publiés ensemble sur Packagist, celui-ci compris.

## Signaler une vulnérabilité

**Merci de ne pas signaler une vulnérabilité dans une issue publique, une pull request ou une discussion.** Signalez-la en privé, par l'un de ces deux canaux :

- **Le signalement privé de vulnérabilités de GitHub :** ouvrez l'onglet **Security** de ce dépôt, puis **Report a vulnerability**.
- **Par courriel :** `security@eightyfour.ovh`, la liste de diffusion privée du projet pour la sécurité.

Indiquez ce que vous pouvez parmi les éléments suivants :

- le paquet et la version concernés ;
- les étapes pour reproduire le problème ;
- une preuve de concept, un exemple d'exploitation ou un correctif ;
- l'impact que vous constatez (par exemple une exécution de code à distance ou une fuite d'informations).

## Et ensuite

- Nous accusons réception de votre signalement sous 48 heures.
- Nous le trions, le reproduisons, le corrigeons et préparons sa publication, en vous informant à chaque étape.
- Une fois le correctif publié, nous convenons avec vous de la divulgation publique, et nous vous créditons si vous le souhaitez.

## Versions maintenues

| Version | Correctifs de sécurité |
|---|---|
| `0.1.0-beta*` | La dernière bêta seulement. Un correctif arrive dans la publication suivante ; les bêtas précédentes ne sont pas corrigées. |
| `1.x` | À partir de `1.0.0` : la dernière version mineure `1.x`. |

Quand une nouvelle version majeure paraît, la majeure précédente reçoit encore pendant six mois les correctifs des vulnérabilités critiques.

Ce fichier n'est pas copié dans les projets créés depuis ce squelette (`export-ignore`, voir `.gitattributes`).
