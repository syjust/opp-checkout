# Roadmap

> Les versions livrées sont documentées dans les [GitHub Releases](https://github.com/syjust/opp-checkout/releases).

## v1.2.0 — CI, webhooks et abonnements

### Amélioration CI/CD

**Supprimer le double `composer install --no-dev` :**
- Actuellement lancé une fois dans le job GitHub Actions, puis une seconde fois via SSH sur OVH
- Rsync le `vendor/` buildé par la CI → supprimer le `composer install` côté OVH
- Inclure `vendor/` dans le rsync (retirer l'exclusion implicite)

**Exclure le dossier `tests/` du déploiement :**
- Ajouter `--exclude='tests/'` au rsync
- Ajouter `--exclude='phpunit.xml.dist'` et `--exclude='phpunit.dist.xml'`

**Vérifier les migrations au déploiement :**
- Ajouter une étape de vérification post-migration : `bin/console doctrine:migrations:status` et vérifier que toutes les migrations sont appliquées
- Faire échouer le deploy si une migration échoue (exit code non-zéro)

### Refactor WebhookController

**Documenter la configuration webhook :**
- Lister les événements Stripe écoutés dans le `CLAUDE.md` ou un `doc/webhooks.md`
- Documenter la configuration requise dans le dashboard Stripe

**Nettoyer les événements écoutés :**
- Supprimer le traitement des événements `invoice.*` non utilisés
- Ne conserver que `checkout.session.completed` (et éventuellement `customer.subscription.deleted`)

**Améliorer les réponses HTTP :**
- Retourner `204 No Content` pour les événements ignorés (type non traité)
- Retourner `200` avec un body JSON décrivant le traitement effectué pour les événements traités

**Idempotence du webhook :**
- Stripe peut renvoyer le même événement plusieurs fois (retries, network issues)
- Les `Purchase` sont dupliqués à chaque replay car `recordPurchases` ne vérifie pas si le `checkoutSessionId` existe déjà
- Ajouter un check de doublon par `checkoutSessionId` dans `PurchaseRepository` avant insertion
- Alternative : tracker les event IDs déjà traités dans une table dédiée

**Gestion d'erreur dans le webhook handler :**
- `createSubscriptionScheduleIfNeeded` fait plusieurs appels API Stripe sans try/catch
- Si un appel échoue → 500 → Stripe retry → les opérations déjà exécutées (purchases, memberships) sont rejouées
- Encapsuler chaque étape dans un try/catch avec logging, et retourner 200 même en cas d'erreur partielle (ou rendre chaque étape idempotente)

**Écouter `customer.subscription.deleted` :**
- L'app crée des subscription schedules avec `end_behavior: cancel` mais n'écoute pas la fin de vie
- Ajouter un handler pour logger la fin d'abonnement (monitoring, audit)

### Pinning de la version API Stripe

Le `StripeClient` est instancié sans version API explicite (`config/services.yaml`). L'app hérite de la version par défaut du SDK, qui peut changer silencieusement lors d'un `composer update`.

```yaml
Stripe\StripeClient:
    arguments:
        - api_key: '%env(STRIPE_SECRET_KEY)%'
          stripe_version: '2025-04-30.basil'
```

### Migrer vers un Restricted API Key

L'app utilise probablement un `sk_` (secret key full-access). Créer un Restricted API Key (`rk_`) avec les permissions minimales :
- Checkout Sessions : write
- Products, Prices : read
- Subscriptions : read
- Subscription Schedules : read + write

Réduit le blast radius en cas de compromission de la clé.

### `integration_identifier` sur les Checkout Sessions

Passer `integration_identifier` à `checkout.sessions.create` pour tracker les sessions dans le Dashboard Stripe :

```php
$params['integration_identifier'] = 'opp_checkout_' . bin2hex(random_bytes(4));
```

### Cache des produits et prix Stripe

La homepage fait N+1 appels API Stripe à chaque chargement : `products->all()` × 2 + `prices->all()` par produit (~14 appels pour 6 produits).

- Mutualiser `loadProducts()` et `fetchProductsByCategory()` en un seul fetch
- Ajouter un cache Symfony (`CacheInterface`) avec TTL de 5 minutes
- Invalider le cache manuellement après `opp:products:create`

### Facture automatique pour les paiements one-off

Pour les checkout sessions en mode `payment` (1x), activer la création automatique de facture Stripe afin que l'élève reçoive un reçu/facture par email :

```php
$params['invoice_creation'] = ['enabled' => true];
```

- Uniquement en mode `payment` (les subscriptions génèrent déjà des invoices)
- À valider avec Valérie : un reçu de paiement pourrait suffire sans facture formelle

### Choix de la date de renouvellement

Permettre à l'élève de choisir sa date de prélèvement mensuel (ex : le 15 de chaque mois) au lieu d'utiliser la date du jour comme date de début d'abonnement.

- Ajouter un sélecteur de jour dans le checkout (1–28)
- Passer le `billing_cycle_anchor` à Stripe lors de la création du subscription_schedule
- Adapter le calcul des phases du schedule en conséquence
- Premier paiement au prorata ou à la prochaine échéance selon le choix

### Supprimer la dépendance Stimulus + Turbo

L'app embarque `symfony/stimulus-bundle` et `symfony/ux-turbo` via AssetMapper, mais ne les utilise pas réellement :
- `hello_controller.js` est le scaffold par défaut, non référencé dans les templates
- `csrf_protection_controller.js` est un fichier généré qui intercepte les événements Turbo pour le CSRF, mais l'app n'utilise ni Turbo Drive, ni `<turbo-frame>`, ni `<turbo-stream>`
- Les templates n'ont aucun attribut `data-controller`, `data-action` ou `data-turbo`
- Tout le JS du checkout est en vanilla JS inline dans `index.html.twig`

**À supprimer :**
- `composer remove symfony/stimulus-bundle symfony/ux-turbo`
- Supprimer `assets/stimulus_bootstrap.js`, `assets/controllers/hello_controller.js`, `assets/controllers/csrf_protection_controller.js`, `assets/controllers.json`
- Retirer l'import `./stimulus_bootstrap.js` de `assets/app.js`
- Nettoyer les entrées `@hotwired/stimulus`, `@symfony/stimulus-bundle`, `@hotwired/turbo` de `importmap.php`

**Risque :** aucun — la fonctionnalité de l'app n'est pas impactée, le formulaire checkout utilise un `form.submit()` standard.

### Crédits JustDevOps dans le footer

Ajouter un lien discret dans le footer vers [JustDevOps](https://jdo.li/in) (LinkedIn de Sylvain) :

```html
<small>Développé par <a href="https://jdo.li/in" target="_blank" rel="noopener">JustDevOps</a></small>
```

Modifier `templates/base.html.twig`, ligne footer existante.

---

## v1.3.0 — Adhésion & Don standalone

### Onglet "Adhésion & Don"

Ajouter un 3e onglet pour les personnes qui veulent soutenir l'association sans s'inscrire à un cours :

- Nouvel onglet "Soutenir l'OPP" (après "Cours à l'unité")
- Formulaire simplifié : email → adhésion (prix libre, obligatoire) + don (optionnel) → paiement
- Checkout en mode `payment` (one-off), pas de subscription
- Réutiliser la logique de membership check existante (pas d'adhésion en doublon)
- Facturer via Stripe Checkout (ou inline, cf. v2.0)

---

## v2.0.0 — Paiement inline (Stripe Elements)

Remplacer la redirection vers Stripe Checkout par un formulaire de paiement intégré dans la page.

### Pourquoi

- UX : l'utilisateur reste sur le site, pas de redirection
- Contrôle : gestion fine du flow (validation, preview, confirmation)
- Flexibilité : prépare le terrain pour les fonctionnalités avancées (choix date de prélèvement, etc.)

### Architecture cible

**Stripe Payment Intent + Elements (card form / stripe.js) :**
- Créer un `PaymentIntent` côté serveur avec les line items calculés
- Monter un `cardElement` (ou `paymentElement`) via Stripe.js dans la page
- Confirmer le paiement côté client avec `stripe.confirmCardPayment(clientSecret)`
- Le webhook `payment_intent.succeeded` (ou `invoice.paid`) remplace `checkout.session.completed`

### Gestion des clients Stripe

Actuellement l'app passe juste l'email du client dans le payload Checkout. En v2.0 :

- **Chercher un client Stripe existant** par email avant de créer un PaymentIntent
- **Créer le client Stripe** uniquement s'il n'existe pas encore
- **Associer le PaymentIntent au customer ID** — plus de guest checkout
- **Stocker la correspondance email ↔ Stripe customer ID** en SQLite pour éviter les appels API répétés

### Facturation et emails

Le passage au PaymentIntent nécessite de gérer les factures explicitement (Checkout les créait automatiquement) :

- Créer une `Invoice` Stripe à chaque paiement / création d'abonnement
- Configurer l'envoi automatique d'email par Stripe (`auto_advance: true`)
- **Événement webhook à évaluer** : `invoice.paid` semble plus adapté que `payment_intent.succeeded` car il couvre aussi les subscriptions
- Alternative : utiliser `invoice.payment_succeeded` pour un hook unifié one-off + recurring

### Webhook : `async_payment_succeeded` / `async_payment_failed`

Actuellement le webhook ne gère que `checkout.session.completed`. Si des méthodes de paiement asynchrones sont activées (virements, prélèvements SEPA…), le fulfillment peut être déclenché alors que le paiement n'est pas encore confirmé.

- Écouter `checkout.session.async_payment_succeeded` et `checkout.session.async_payment_failed`
- Conditionner le fulfillment à `payment_status !== 'unpaid'` dans `handleCheckoutCompleted`
- Non critique tant que seule la CB est activée, mais requis dès l'ajout d'autres méthodes

### Étapes de migration

1. Ajouter Stripe.js (`<script src="https://js.stripe.com/v3/"></script>`) dans `base.html.twig`
2. Créer un endpoint API `POST /api/payment-intent` qui retourne le `clientSecret`
3. Remplacer le bouton "Passer au paiement" par le card element + bouton "Payer"
4. Créer/mettre à jour le webhook pour écouter `invoice.paid` au lieu de `checkout.session.completed`
5. Ajouter l'entité `Customer` (email, stripe_customer_id) + migration
6. Adapter `StripeCheckoutService` → `StripePaymentService` (ou renommer)
7. Supprimer le flow Checkout Session (routes, templates cancel/success, service)
