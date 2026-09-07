---
name: datastar-wordpress
description: "Marier Datastar et WordPress : format de fil SSE v1, attributs qui existent vraiment, prise de contrôle de la sortie REST, SDK PHP vendorisé et ses deux défauts, modèle de charge PHP, autorisation d'une route publique derrière un cache. À lire avant de toucher à un attribut data-*, à la route SSE, ou au SDK."
---

# Datastar dans WordPress

Cette skill ne réécrit pas WordPress. `wp-plugin-development`,
`wp-plugin-directory-guidelines` et `wp-block-development` font autorité sur les blocs, la
Settings API, la désinstallation et les règles du dépôt officiel. Ici, uniquement ce qui naît de
la rencontre des deux — c'est-à-dire tout ce qu'aucune des deux documentations ne dit.

> Tout ce qui est marqué **mesuré** l'a été le 31/08/2026 sur le clone iso-production
> (WordPress 7.1, PHP 8.4, Apache), avec le bundle Datastar **v1.0.3** et le SDK PHP **1.0.1**.

## ⚠️ À lire avant d'écrire une ligne

**1. `data-on-load` n'existe pas.** C'était le nom de la v0. En v1 c'est **`data-init`**, qui se
déclenche au chargement, **et à chaque fois que l'élément est injecté dans le DOM**. C'est ce
second point qui rend le motif « le serveur livre le comportement » possible.

**2. Les événements prennent un DEUX-POINTS.** `data-on:click`, jamais `data-on-click`. Mais les
attributs qui ne sont pas des événements DOM prennent un tiret : `data-on-interval`,
`data-on-intersect`, `data-on-signal-patch`. Les deux formes coexistent et rien ne le signale.

**3. Un attribut ou un modificateur inconnu est ignoré EN SILENCE.** Pas d'erreur, pas
d'avertissement. Le code se rend, se lint, passe la revue — et ne fait rien. C'est le mode
d'échec dominant de Datastar, et il ne ressemble pas à une panne.

**4. Ne jamais appeler `ServerSentEventGenerator::readSignals()` dans WordPress.** Il lit
`$_GET['datastar']` et `$_SERVER['REQUEST_METHOD']` **sans garde** : sur une requête sans
signaux, PHP 8 émet un `Warning: Undefined array key`, et si `WP_DEBUG_DISPLAY` est actif ce
warning s'imprime **dans le flux** et le rend illisible. On lit `WP_REST_Request`, ce qui est de
toute façon la bonne pratique : on y gagne la validation déclarative.

**5. La version du bundle JS et celle du SDK PHP ne se suivent pas.** Bundle en `v1.0.3`, SDK en
`1.0.1`. Vérifier les deux séparément.

**6. Datastar exige `unsafe-eval` — SAUF en mode CSP, arrivé en 1.0.3.** Il évalue ses
expressions avec `Function()`. Sous une CSP stricte, rien ne fonctionne, et rien ne le dit dans
l'interface. Le mode CSP lève ça ; voir plus bas.

## Le seul modèle de charge acceptable dans WordPress

**PHP-FPM immobilise un worker par connexion ouverte.** Un flux SSE maintenu pendant une visite,
c'est un worker pris dans un pool qui en compte souvent moins de dix sur un mutualisé. Le cas
documenté en amont : vingt visiteurs sur vingt workers, et le site entier répond 504.

**Et un second défaut, indépendant du premier** : avec `retry: 'auto'` — le défaut — Datastar
**ne se reconnecte pas** sur une fermeture *propre* du serveur. Or c'est exactement ce que fait
PHP en fin de temps imparti. Le composant se fige, sans message.

Les deux intégrations PHP de référence font du **requête/réponse** : le plugin Craft et le
package Laravel de putyourlightson n'ont ni boucle ni `sleep` dans leurs exemples, et le package
Laravel impose `getEventStream()`. Écrire autre chose dans un plugin distribué, c'est décider à
la place de ses utilisateurs.

| À faire | À ne pas faire |
|---|---|
| Une salve qui se ferme aussitôt (mesuré : **44 ms, 3 585 octets**) | Garder le flux ouvert et `sleep()` entre deux patchs |
| Faire décider le serveur (quoi, dans quel ordre, à quel rythme) | Faire cadencer le serveur en temps réel |
| Un `@get` par affichage de composant | Un `@get` toutes les N secondes — WordPress démarre en entier à chaque tic |

**Ce qu'on ne perd pas en fermant tout de suite** : le serveur décide toujours de tout. Ce qu'on
perd, c'est de changer le contenu *pendant* la visite — ce dont un carrousel n'a pas besoin.

## Prendre la main sur la sortie de l'API REST

`WP_REST_Server` envoie `Content-Type: application/json` **avant** le dispatch. Le seul point où
nos en-têtes ont le dernier mot est le filtre `rest_pre_serve_request`, appliqué après tout le
reste. Renvoyer `true` dit au cœur que la réponse est partie ; il rend `null` et
`rest_api_loaded()` fait le `die()`, les hooks d'arrêt tournant normalement.

```php
add_filter( 'rest_pre_serve_request', static function ( $served ) use ( $request ) {
    self::emit( $request );
    return true;
}, PHP_INT_MAX );
```

**Avant d'émettre, dans cet ordre, et aucun de ces gestes n'est décoratif :**

```php
@ini_set( 'display_errors', '0' );
@ini_set( 'html_errors', '0' );
@ini_set( 'zlib.output_compression', '0' );
if ( function_exists( 'apache_setenv' ) ) { @apache_setenv( 'no-gzip', '1' ); }
if ( PHP_SESSION_ACTIVE === session_status() ) { session_write_close(); }
while ( ob_get_level() > 0 ) { ob_end_clean(); }   // DÉTRUIRE, pas vider
```

**`ob_end_clean()`, jamais `ob_end_flush()`** — et c'est ce qui neutralise le second défaut du
SDK, dont le `sendEvent()` appelle `ob_end_flush()` là où `ob_end_clean()` était voulu : sans
tampon, cette branche n'est jamais atteinte. **Aucune ligne de contournement n'est nécessaire.**

**Mesuré, avec contre-épreuve.** Un mu-plugin qui fait `ob_start(); echo 'GARBAGE'` sur
`plugins_loaded` : avec le nettoyage, le flux est **identique à l'octet** au flux de référence et
ne contient pas la chaîne ; sans lui, `GARBAGE-BEFORE-STREAM` se pose **avant** le premier
`event:` et le flux devient illisible (3 606 octets contre 3 585). La sonde était vérifiée
sensible d'abord — la page, elle, portait bien la saleté.

**Le cache, ensuite.** Le cœur n'envoie ses en-têtes no-cache que si
`apply_filters( 'rest_send_nocache_headers', is_user_logged_in() )` — donc **rien du tout pour un
visiteur anonyme**, qui est précisément le public d'une route publique. Le SDK n'envoie que
`Cache-Control: no-cache`. On appelle `nocache_headers()` **après** `sendHeaders()`.

Mesuré à `curl`, en sortie : `Content-Type: text/event-stream;charset=UTF-8` (le JSON du cœur est
bien écrasé), `Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private`,
`X-Accel-Buffering: no` (envoyé par le SDK).

## Autoriser une route publique derrière un cache de page

**Ne jamais poser un nonce REST dans du HTML susceptible d'être mis en cache.**
`wp_create_nonce()` dépend de l'utilisateur et de l'heure : figé dans une page servie par un
cache, il est invalide pour le visiteur suivant et change la page toutes les douze heures.

**Un HMAC ne dépend ni de l'un ni de l'autre.** Le rendu serveur signe les paramètres qu'il
autorise ; la route vérifie la signature.

```php
$token = substr( hash_hmac( 'sha256', "$ids|$size|$target", wp_salt( 'mon_sel' ) ), 0, 32 );
// dans permission_callback : hash_equals( $attendu, $recu )
```

**Pourquoi c'est nécessaire, et pas seulement propre.** Une route publique qui prend des ids de
pièces jointes et rend leur balisage est un **oracle d'énumération de la médiathèque** :
brouillons, fichiers non rattachés, tout ce qui a un id devinable. Un `permission_callback`
rendant `__return_true` ouvre grand cette porte.

**Et tout paramètre interpolé dans un sélecteur CSS de patch est une frontière de sécurité.**
`'selector' => '#' . $target . ' .track'` : sans `validate_callback` strict, un appelant choisit
où le site injecte du HTML — y compris dans une page d'administration. La regex n'est pas de la
coquetterie.

Mesuré, huit sondes : jeton valide → 200 `text/event-stream` ; id ajouté, id retiré, cible
changée, jeton d'un caractère différent → **403** ; jeton absent → 400 ; `target=body` → 400
`rest_invalid_param` ; taille hors énumération → 400.

## Ce que le serveur peut livrer, et ce qu'il ne peut pas

**`data-on-interval__duration.5s` ne peut pas lire un signal.** La durée est analysée depuis le
**nom** de l'attribut, à l'initialisation. Envoyer l'intervalle en `datastar-patch-signals` ne
cadence rien.

**La parade, et elle vaut mieux que le problème** : faire livrer par la salve **l'élément qui
porte l'attribut**. Datastar retraite les attributs des éléments injectés, donc la cadence prend
effet — et elle arrive fraîche même sur une page qu'un cache a figée il y a des jours. Le rendu
pose un porteur vide, la salve le remplace :

```php
// rendu : <div id="xxx-cadence" hidden></div>
$sse->patchElements( '<div id="xxx-cadence" hidden data-on-interval__duration.5s="…"></div>' );
```

**Mesuré au navigateur** : la diapo passe de « 1 sur 5 » à « 2 sur 5 » après 6,5 s, sur un
élément arrivé par la salve. C'était l'inconnue principale de la conception ; elle est levée.

**Ordonner les événements.** Les patchs s'appliquent dans l'ordre reçu. Livrer le comportement
**en dernier**, après les signaux dont il dépend : une cadence qui démarre avant que `count` soit
juste fait défiler des vues qui n'existent pas.

## Le mode CSP, et pourquoi il n'est pas ce qu'on croit

**Datastar 1.0.3 ajoute un mode CSP, dans le bundle ordinaire — pas dans un bundle séparé.**
J'ai d'abord écrit ici qu'une CSP stricte condamnait Datastar « et que ça ne se contournait pas ».
C'était faux au moment même où je l'écrivais : la version qu'on embarquait déjà portait le
correctif, sorti le 29/08/2026. C'est la note la plus utile de la page, parce qu'elle dit où j'ai
cessé de vérifier.

**Ce n'est pas un interpréteur restreint, c'est un nonce.** Les expressions compilent exactement
pareil — `matchMedia()`, `%`, `&&`, les `;` multiples, tout continue de fonctionner. La page pose
`<html data-nonce="…">`, Datastar le lit, **retire l'attribut**, et l'applique quand il compile.
La disparition de l'attribut est d'ailleurs le meilleur signe qu'il a démarré.

**Ce qu'un plugin doit faire, et surtout ne pas faire.** Ne jamais inventer le nonce : il ne vaut
quelque chose que si la même valeur figure dans le `script-src` de la réponse, et seul ce qui
émet cet en-tête peut le garantir. Un nonce inventé produit un attribut qui a l'air juste et ne
protège rien. On expose donc un filtre, et on greffe l'attribut sur `language_attributes` :

```php
add_filter( 'language_attributes', function ( $out ) {
    if ( is_admin() || str_contains( $out, 'data-nonce' ) ) { return $out; }
    $nonce = (string) apply_filters( 'mon_csp_nonce', '' );
    return '' === $nonce ? $out : $out . ' data-nonce="' . esc_attr( $nonce ) . '"';
} );
```

Le garde `str_contains` n'est pas de la coquetterie : une seconde extension portant Datastar
poserait un deuxième `data-nonce` sur la même balise, et le navigateur lirait celui qu'il a
analysé en premier.

**Mesuré des deux côtés, sous `script-src 'self' 'nonce-…'` sans `unsafe-eval`.** Avec le pont :
l'attribut a disparu après le démarrage, la salve arrive, la rotation passe de « 1 sur 5 » à
« 2 sur 5 ». Sans le pont, même CSP : la salve n'arrive pas, le composant reste figé, et la
console dit `EvalError: … 'unsafe-eval' is not an allowed source`, suivi de
`Error: GenerateExpression`.

**Ce que le mode CSP ne fait pas** : il ne rend pas les expressions sûres vis-à-vis de contenu non
fiable — la doc amont le dit en toutes lettres. Datastar n'examine ni ne nettoie ce qu'on écrit
dans un attribut. On fait passer les valeurs d'utilisateur **par des signaux**, jamais par
interpolation dans l'expression.

## `@get` dans un plugin distribué : deux options, et l'événement qui dit pourquoi ça a échoué

**Mesuré le 07/09/2026, bundle 1.0.3, lu dans le code minifié.**

**1. `payload: {}`.** Par défaut `@get` sérialise **tous les signaux de la page** dans la query
string (`datastar=…`). Seul, c'est cinquante octets. Mais dès qu'un site partage un runtime
Datastar entre deux extensions (ce que le readme conseille pour éviter le gel à deux runtimes),
ce qu'une autre extension lie par `data-bind` — champ de recherche, mot de passe — part dans
**nos** journaux d'accès. La route ne lit aucun signal. Dans le bundle : `y!==void 0?y:G(...)`,
donc un objet vide passé en `payload` donne `datastar={}` et rien d'autre.

**2. `openWhenHidden: true`.** Un GET est **annulé** quand l'onglet passe en arrière-plan et
**relancé** quand il revient — mais seulement s'il est en vol à ce moment-là (l'écouteur
`visibilitychange` n'existe que pendant la requête). Ouvrir une page dans un onglet d'arrière-plan
et y passer pendant la salve la fait donc partir deux fois, et `mode: append` double toutes les
diapositives ; la garde `loaded` ne peut rien, c'est la même requête qui repart. Une salve qui se
ferme en 44 ms n'a rien à gagner à attendre l'onglet.

**Le piège de mesure** : sur localhost la salve est finie avant qu'un test puisse changer la
visibilité. Le test qui prouve la sensibilité **retarde la réponse** (`page.route` + 1 s) et
bascule `document.hidden` pendant ce délai. Sans l'option : deux requêtes ; avec : une.

**3. `datastar-fetch` est le seul récit d'un échec.** Datastar n'écrit **rien en console** quand
la réponse n'est pas un 200 : il émet `document.dispatchEvent(new CustomEvent('datastar-fetch',
{detail: {type, el, argsRaw}}))` et s'arrête. `type: 'error'` avec `argsRaw.status` (chaîne) pour
tout statut ≥ 400 ; `retrying` puis `retries-failed` (`argsRaw: {}`) pour une erreur réseau ;
`started` / `finished` autour. `el` est l'élément qui porte le `data-init`. Un diagnostic qui
veut nommer la cause (REST restreinte → 401, route absente → 404, PHP → 5xx) écoute cet
événement ; rien d'autre ne le lui dira.

**Et un « pas de diapositives » n'est pas un « pas de réponse ».** Un carrousel dont les autres
images ont été supprimées après la mise en cache de sa page reçoit une salve légitimement vide.
Compter les diapositives pour décider qu'une salve a échoué crie au loup dans ce cas. La salve
porte donc **toujours** son dernier élément, marqué `data-ulcar-burst`, même quand il n'y a rien à
faire tourner, et c'est ce marqueur que le diagnostic lit.

## Plugin Check et le SDK vendorisé

**Le formulaire de dépôt de wordpress.org refuse un ZIP sur toute erreur de Plugin Check, que le
code soit à nous ou non.** Le SDK PHP de Datastar en porte trois d'inhérentes (`echo` de la trame
SSE, deux messages d'exception construits depuis un enum). Patcher le code amont est le début
d'un fork ; **un commentaire `phpcs:ignore` avec la raison** sur chaque ligne, posé par le script
de vendoring pour survivre au prochain rafraîchissement, laisse la ligne intacte et fait passer
le formulaire. Mesuré : `wp plugin check … --include-experimental` sur l'arbre publié, SDK
compris, **zéro signalement**.

Et la CI doit vérifier **exactement ce que le formulaire verra** : pas d'`exclude-directories`.
Une exclusion « pour garder le job sur nos régressions » est précisément ce qui laisse passer
le refus au moment du dépôt.

## Signaux : ils sont globaux

Deux composants sur une page se piloteraient l'un l'autre. On préfixe par instance, et
l'identifiant doit être un **identifiant JavaScript valide** :

- id DOM dérivé d'un hachage **déterministe** — **pas `wp_unique_id()`**, dont le compteur dépend
  de ce qui a été rendu avant, donc varie sur une page assemblée de fragments mis en cache ;
- un hachage hexadécimal peut commencer par un chiffre : `$app.0a1b2c.view` est une erreur de
  syntaxe. On préfixe d'une lettre. Ici : id DOM `xxx-<hash>`, chemin de signal `app.k<hash>`.

Mesuré : deux carrousels sur une page, deux appels 200 distincts, mettre l'un en pause laisse
l'autre tourner.

## Charger le bundle dans WordPress

Datastar est un module ES. `wp_register_script_module()` (WP 6.5+), jamais
`wp_enqueue_script( … 'strategy' => 'defer' )` : `defer` ne fait pas un module, et forcer
`type="module"` par `script_loader_tag` expose à ce qu'un plugin d'optimisation réécrive la
balise. On perdrait aussi la carte d'imports, donc la déduplication.

```php
add_action( 'init', fn() => wp_register_script_module( 'mon-datastar', $url, array(), '1.0.3' ) );
```

Dans `block.json`, un identifiant **sans préfixe `file:`** est repris tel quel :

```json
"viewScriptModule": "mon-datastar"
```

Et **il n'y a rien de plus à faire** : le cœur met `viewScriptModule` en file **au rendu du
bloc**. `has_block()` est inutile ici, et pire que cela — il ne voit ni les parties de gabarit,
ni les compositions synchronisées. `wp_script_is()` **ne fonctionne pas** sur les modules, et
`wp_script_module_is()` n'existe pas.

Mesuré : `<script id="…-js-module" src="…/datastar-1.0.3.js?ver=1.0.3" type="module"></script>`,
présent sur la page qui porte le bloc.

## Le SDK vendorisé

**PHP n'isole pas les namespaces.** Deux plugins embarquant `starfederation\datastar` en versions
différentes : le premier autoloader gagne, et l'autre s'exécute contre du code qui n'est pas le
sien. Un `class_exists()` ne corrige rien — il change un fatal en bug silencieux. Le seul remède
est le préfixage.

Pour 13 fichiers sans aucune dépendance, un `vendor/` Composer apporte plus de code
d'infrastructure que de code utile. On recopie, on réécrit le namespace, on charge par
`require_once`. Deux choses seulement changent dans le code amont — le namespace, et la garde
`defined( 'ABSPATH' ) || exit;` — et **elle se pose après la déclaration de namespace**, jamais
après `<?php` : `namespace` doit être la première instruction du fichier, sinon les treize
fichiers ne compilent plus. (Erreur commise, corrigée, mesurée.)

Un `UPSTREAM.md` porte le tag, la licence et la somme de contrôle. C'est ce qui répond en une
phrase à « ce fichier tiers, c'est quoi ? » à la revue.

## Aperçu dans l'éditeur

`viewScriptModule` est du front : **Datastar n'est pas chargé dans l'éditeur**. Un rendu dynamique
qui ne pose qu'un élément et compte sur le SSE pour le reste montrerait donc une seule image à
l'auteur, sans moyen de savoir si sa sélection est enregistrée.

```php
$is_preview = defined( 'REST_REQUEST' ) && REST_REQUEST;
```

Dans cette branche, rendre **tout**, statiquement, sans un seul `data-*`. Avec
`wp.serverSideRender`, l'aperçu est alors littéralement le rendu serveur : l'éditeur et le front
ne peuvent pas diverger.

**Piège de mesure**, payé aujourd'hui : `ServerSideRender` emboîte le rendu dans le conteneur de
l'éditeur, qui porte **aussi** `wp-block-<nom>`. Interroger ce conteneur fait croire qu'une classe
posée par le rendu serveur manque. Elle est sur l'élément **interne**. Remonter la chaîne des
parents avant de conclure.

## `__viewtransition` : le modificateur qui déclenche même quand rien ne change

Datastar offre un modificateur `__viewtransition` qui enveloppe l'expression dans
`document.startViewTransition`. Il est tentant : les éléments viennent du serveur, donc « on parle
de transition de vue ». **Deux faits mesurés le 31/08/2026 disent que ce n'est presque jamais le
bon outil pour un composant.**

**1. Le modificateur appelle `startViewTransition` que l'expression change quelque chose ou non.**
Une garde qui saute l'affectation — `!matchMedia('(prefers-reduced-motion: reduce)').matches && (…)`,
exactement le motif recommandé plus bas — laisse donc le navigateur faire une transition sur du
contenu **identique**. Mesuré en production : deux transitions déclenchées, image restée sur « 1 sur
5 ».

**2. `startViewTransition` capture la page entière.** La racine du document porte
`view-transition-name: root` par défaut ; poser un nom sur un élément ne restreint rien, cela
l'**extrait** d'un instantané qui couvre tout le reste. Mesuré, un seul échange, 1280 × 900 :
**597 604 pixels changés en dehors du composant**, sur toute la largeur et toute la hauteur. Des
décors sans rapport, à l'autre bout de la page, clignotaient au rythme du composant. Et l'instantané
est peint dans la couche du dessus, donc il **sort de tout masque** (`mask-image`, `clip-path`) posé
par un ancêtre.

La neutralisation habituelle — `::view-transition-old(root) { animation: none }` — est **globale au
document**. Dans une extension distribuée, poser une règle qui casserait les transitions de
navigation d'un thème est disqualifiant.

**Correction du 31/08/2026, sur signalement de Loïc, et elle compte : il EXISTE une transition de
vue scopée, mais pas là où on la cherchait.** La phrase « une transition de vue capture toute la
page » est vraie du **modificateur client** et fausse du **patch serveur**. Les deux chemins
n'appellent pas la même API, ce que seul le bundle dit :

| Chemin | Ce qu'appelle Datastar | Portée |
|---|---|---|
| `__viewtransition` sur `data-on*` | `document.startViewTransition(…)` | la page entière |
| `useViewTransition` sur `datastar-patch-elements` | `document` | la page entière |
| **+ `viewTransitionSelector`** | **`element.startViewTransition(…)`** | **le sous-arbre visé** |
| `data-view-transition` (pose un `view-transition-name`) | — | **Pro**, hors de portée |

Lu dans `datastar-1.0.3.js` : `let l = p; if (o) { const f = p.querySelector(o); if (f) l = f; }`
puis `yt(l) ? l.startViewTransition(...) : ...`, avec `yt = e => "startViewTransition" in e`. C'est
donc bien l'API **scopée** quand le sélecteur désigne un élément, avec repli silencieux si le
navigateur ne la connaît pas. Côté serveur, les en-têtes `datastar-use-view-transition` et
`datastar-selector` donnent la même chose pour une réponse HTML.

**Ce que ça change, et ce que ça ne change pas.** Si le changement vient d'un **patch serveur**, on
peut demander une transition de vue propre et scopée : c'est la bonne solution, et elle ne casse
rien ailleurs. Si le changement est **piloté par le navigateur** — un signal, un intervalle, un
clic — il n'y a pas de patch, donc pas de sélecteur, donc pas de portée : le modificateur reste
document-wide, et le fondu CSS reste la seule réponse gratuite. Cette extension est dans le second
cas **par conception** : la rotation tourne côté client précisément pour ne pas rappeler le serveur
toutes les cinq secondes.

**À faire, pour un composant piloté par le navigateur** : garder l'état dans un signal, empiler les
éléments et faire le fondu en CSS. Cela ne peut atteindre aucun pixel hors du conteneur, ne sort
d'aucun masque, ne demande aucune règle globale — et l'attribut `hidden` reste utilisable, donc
l'accessibilité ne paie rien. **À ne pas faire** : `__viewtransition` sur un tel composant.

**À faire, pour un composant piloté par le serveur** : `useViewTransition` **avec**
`viewTransitionSelector` visant le conteneur. Sans le sélecteur, le second est aussi large que le
premier.

**Et le piège de mesure qui a coûté deux versions de plus : un style calculé n'est pas ce qu'on
voit.** Le fondu CSS ci-dessus a été livré deux fois cassé, verdict des tests au vert à chaque
coup, parce que toutes les assertions portaient sur `getComputedStyle` :

1. **0.2.0** — les deux couches fondaient ensemble. Deux couches à moitié transparentes ne font pas
   une couche opaque : mesuré à mi-échange, 0,49 sur 0,51 ne couvraient que **0,75** de la boîte, et
   le quart de fond qui traversait se voyait comme un **éclair**. Parade : la sortante porte
   `hidden`, on la passe **au-dessus** et elle s'efface sur une entrante déjà opaque.
2. **0.3.0** — la sortante était bien à `z-index: 1`… et restait invisible. Une diapositive n'était
   pas un **contexte d'empilement**, donc un thème qui écrit `img { position: relative; z-index: 1 }`
   à l'intérieur en extrayait l'image entrante et la peignait par-dessus. Mesuré, sortante à 0,78
   d'opacité : l'image à mi-fondu différait de l'état d'arrivée de **4 %** des pixels — une coupe
   sèche. Parade : `isolation: isolate` sur chaque diapositive. Même instant, **95 %** et **99 %**.

3. **0.3.1** — le fondu marchait sur Chromium et **n'existait pas du tout sur Firefox**. La
   sortante était retenue à l'écran par `transition: display … allow-discrete` ; Firefox 153
   annonce le support (`CSS.supports('transition-behavior','allow-discrete')` rend `true`, et le
   style calculé dit bien `normal, allow-discrete`) **et pose `display: none` dès la première
   image**. Parade portable : une diapositive au repos est
   `display: block !important; visibility: hidden`. `visibility` n'a besoin d'aucun support de
   transition discrète, s'anime partout, reste `visible` jusqu'à la fin — et retire l'élément de
   l'arbre d'accessibilité et de l'ordre de tabulation exactement comme `display: none`, ce qui
   était toute la raison d'utiliser l'attribut `hidden`. Le `!important` répond à la feuille du
   navigateur, qui écrit `[hidden] { display: none !important }`.

   **Et il faut alors que la transition soit portée par l'état SORTANT** — `.ulcar-slide[hidden]`,
   pas `.ulcar-slide`. Une transition est choisie par l'état vers lequel on va : déclarée sur
   l'état visible, elle ferait aussi **fondre l'entrante**, et le flash du point 1 reviendrait
   (mesuré : 0,56 + 0,44, couverture 0,75).

**WebKit : installé, incapable de démarrer, et couvert quand même.** La parade par `visibility` a
d'abord été confirmée **à la main par Loïc, sur Safari, le 01/09/2026**. La suite la rejoue
désormais toute seule — 26 tests WebKit qui passent.

La confusion à éviter, parce qu'elle est naturelle : **le navigateur s'installe très bien.**
`webkit-2336` occupe 293 Mo à côté de `chromium` et `firefox` dans `~/.cache/ms-playwright`. Ce
n'est pas le téléchargement qui échoue, c'est le **lancement** : WebKit dépend de trois
bibliothèques **système** dont les deux autres se passent — `libicu74`, `libxml2`, `libflite1` — et
les poser demande root. Aucun gestionnaire de versions d'outils n'y changera rien : ils gèrent des
chaînes d'outils, pas des `.so`.

**La parade, sans root et sans toucher à la machine** : l'image officielle
`mcr.microsoft.com/playwright:v<version>-noble`, qui porte ces bibliothèques. D'où deux commandes
plutôt qu'une :

| Commande | Ce qu'elle lance |
|---|---|
| `npm test` | les deux moteurs qui démarrent en natif |
| `npm run test:all` | les trois, dans le conteneur |

**À faire** : garder l'étiquette de l'image en phase avec la version de Playwright, sinon les
navigateurs du conteneur ne sont pas ceux que la configuration attend. **À ne pas faire** : déclarer
un projet qui se saute lui-même quand il ne peut pas démarrer — un saut silencieux se lit
exactement comme un succès.

**Corollaire, et c'est le plus cher des trois : un moteur n'est pas « evergreen ».** La suite de
bout en bout de cette extension a tourné sur **Chromium seul** pendant trois versions, pendant que
le readme annonçait des navigateurs evergreen. Le défaut ci-dessus était invisible par construction.
Elle tourne désormais sur deux moteurs, et la contre-épreuve le prouve : l'ancienne mécanique
**passe sur Chromium et échoue sur Firefox**. **À faire** : quand une extension repose sur une
primitive CSS récente, la mesurer dans au moins deux moteurs — et se méfier de `CSS.supports`, qui
a répondu `true` ici sur une fonctionnalité qui ne faisait rien.

**La leçon commune, et elle vaut pour tout CSS d'extension** : `opacity`, `z-index` et `display`
lus au calcul décrivent une **intention**, pas une peinture. Ce qui décide, c'est l'ordre
d'empilement — et il dépend de ce que le **thème** met dans l'élément, ce qu'une page de fixture nue
ne montre jamais. **À faire** : mesurer en **pixels**, sur une page où l'on a injecté ce qu'un vrai
thème écrit. Le navigateur sous test décode lui-même les captures (`Image` + canvas), donc cela ne
coûte aucune dépendance. **À ne pas faire** : conclure d'un `getComputedStyle`, ni d'un test
d'atteinte (`elementFromPoint`) — essayé, il ne distingue pas les deux états.

**Le piège de test qui va avec, et qui a laissé le premier bug passer trois semaines.** Trois tests de bout
en bout affirmaient qu'une image **ne bougeait pas** (mouvement réduit, pause, pause qui ne repart
pas). C'est le symptôme exact du défaut : ils étaient verts pendant que le carrousel était figé et
que la page clignotait. Une assertion d'immobilité n'est pas seulement faible, elle est **satisfaite
par la mort de ce qu'elle teste**. Quand l'immobilité est bien ce qu'on veut vérifier, on la mesure
**à deux bras dans la même exécution** : un contexte où ça doit bouger, un où ça ne doit pas.

## Accessibilité : ce que Datastar ne fait pas pour vous

Un composant qui bouge tout seul relève de **WCAG 2.2.2 Pause/Stop/Hide, niveau A**. Non
négociable.

**Ce que cette extension a fini par livrer, et ce que ça coûte.** Les contrôles ont été retirés le
31/08/2026, sur décision explicite du mainteneur : « on a jamais demandé d'avoir des contrôles […]
c'est de l'over-engineering ». La conséquence se dit sans la maquiller — **sans moyen d'arrêt,
l'extension échoue à WCAG 2.2.2 (niveau A)**, et le readme le déclare en toutes lettres plutôt que
de laisser un site le découvrir. Ce qui suit reste la bonne pratique pour tout composant qui, lui,
doit passer le critère.

- **Le bouton d'arrêt vient avant le mouvement dans le DOM.** Faire traverser le mouvement pour
  atteindre ce qui l'arrête, ce n'est pas l'avoir fourni. Mesuré : Pause → Précédent → Suivant.
- **Une fois arrêté, ça ne repart jamais tout seul.**
- **`prefers-reduced-motion` ne se traite pas en CSS** — aucune feuille de style n'arrête un
  minuteur. On le met **dans l'expression**, où il est réévalué à chaque tic, donc un réglage
  système changé en cours de visite est obéi :
  ```
  data-on-interval__duration.5s="!$s.paused && !matchMedia('(prefers-reduced-motion: reduce)').matches && (…)"
  ```
  Mesuré avec `reducedMotion: 'reduce'` : immobile.
- **Deux libellés basculés par `hidden`, plutôt qu'une chaîne dans l'expression.** Le nom
  accessible est celui qui reste visible, les chaînes restent dans gettext, et aucun problème de
  guillemets ne se pose. Mesuré : `button "Pause slideshow"`, un seul libellé.
- **`hidden`, jamais `opacity: 0`.** Un élément transparent reste focalisable et reste lu.
  Mesuré : zéro élément focalisable dans les vues masquées.
- **`aria-live="off"` pendant l'animation automatique**, `polite` seulement quand le visiteur a
  pris la main — sinon on annonce une image toutes les cinq secondes à un lecteur d'écran.

**Un vert d'axe ne vaut que si axe est sensible ici.** Mesuré des deux côtés : 0 violation sur le
composant ; en retirant les noms accessibles, **1 violation critique sur 3 nœuds**.

## Le piège du lint, payé aujourd'hui

Le premier script de lint de ce dépôt terminait par `| grep -v … || true`. Il a annoncé **code de
retour 0** pendant que treize erreurs fatales défilaient. La forme correcte n'a **ni tuyau ni
`|| true`**, et sa boucle lit depuis un **fichier** — un `while` en bout de tuyau tourne dans un
sous-shell et son code de retour est perdu :

```sh
find . -name '*.php' > /tmp/files.txt
rc=0
while IFS= read -r f; do php -l "$f" > /tmp/out.txt 2>&1 || { rc=1; cat /tmp/out.txt; }; done < /tmp/files.txt
exit $rc
```

Et on l'éprouve sur un fichier volontairement cassé **avant** de lui faire confiance.

## Sources

- <https://data-star.dev/reference/attributes>, <https://data-star.dev/reference/sse_events>,
  <https://data-star.dev/reference/actions>
- <https://github.com/starfederation/datastar-php>
- <https://putyourlightson.com/plugins/datastar>, <https://github.com/putyourlightson/laravel-datastar>
- `wp-includes/rest-api/class-wp-rest-server.php` du cœur, lu directement
