<?php

declare(strict_types=1);

namespace App\Security;

use Psr\Http\Message\ServerRequestInterface;
use Waffle\Commons\Contracts\Security\SubjectResolverInterface;

/**
 * Résolveur de sujet EXEMPLE du template (SEC-05) — NON câblé par défaut.
 *
 * Le squelette n'injecte aucun résolveur dans le SecureContainer : vos voters
 * reçoivent la requête PSR-7 (le repli documenté) tant que vous n'avez pas
 * branché votre propre implémentation (voir AppKernelFactory, étape 4). Cette
 * classe illustre le contrat : exposer les paramètres de route bruts comme
 * sujet est un point de départ pédagogique, PAS une pratique de production —
 * un voter qui attend une entité recevrait ici un tableau contrôlé par le
 * client. Une fois câblé, le SecureContainer l'invoque paresseusement,
 * post-routage / pré-dispatch, et seulement si l'action dispatchée porte au
 * moins un #[Voter] — les routes #[PublicAccess] sans voter ne sollicitent
 * jamais le résolveur. Le sujet retourné est transmis aux voters comme cible
 * de décision — c'est le point d'extension qui rend possibles les règles au
 * niveau objet (anti-IDOR).
 *
 * Point d'extension : une vraie application transformerait ici un paramètre
 * `{id}` en entité via son dépôt (RFC-022) et LÈVERAIT une exception si la
 * résolution échoue — sur une route votée, le SecureContainer refuse alors la
 * requête (fail-closed, 403 journalisé par la SecurityMiddleware) ; ne
 * retournez jamais null pour masquer un échec. Le template se contente
 * d'exposer les paramètres de route comme sujet, et null pour les routes qui
 * ne portent aucune ressource.
 *
 * Sans état (mode worker FrankenPHP) : `final readonly`, tout est lu depuis la
 * requête courante.
 */
final readonly class RouteParamSubjectResolver implements SubjectResolverInterface
{
    #[\Override]
    public function resolve(ServerRequestInterface $request): mixed
    {
        // Clé littérale : reflète le nom d'attribut posé par la
        // CoreRoutingMiddleware (contracts n'expose pas de constante
        // ATTR_PARAMS à ce jour, et le producteur utilise aussi le littéral).
        $params = $request->getAttribute('_params');
        if (!is_array($params) || $params === []) {
            // Route sans ressource : null ⇒ les voters votent sur la requête.
            return null;
        }

        return $params;
    }
}
