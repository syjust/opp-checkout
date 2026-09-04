# Roadmap

## v1.0 — Lancement

### CI/CD — Déploiement automatisé sur tag

Workflow GitHub Actions déclenché sur push de tag (`v*`).

**Pipeline :**
1. `composer install --no-dev --optimize-autoloader`
2. Écrire le tag git dans un fichier `VERSION` à la racine
3. `rsync` vers le serveur OVH mutualisé via SSH (excluant `.git/`, `.env.local`, `var/`, `node_modules/`, `vendor/`)
4. SSH : `composer install --no-dev --optimize-autoloader` sur le serveur
5. SSH : `bin/console doctrine:migrations:migrate --no-interaction`
6. Mise à jour du lien symbolique `htdocs`

**Prérequis :** clé SSH dédiée pour GitHub Actions, secrets GitHub (`SSH_PRIVATE_KEY`, `OVH_HOST`, `OVH_USER`, `OVH_DEPLOY_PATH`).

**Structure de déploiement avec historique et symlink :**

```
inscriptions.loreillepresqueparfaite.com/
├── .env.local                    ← fichier partagé entre versions, symlinké dans chaque release
├── shared/
│   └── var/                      ← logs, cache, data.db — persisté entre releases
├── releases/
│   ├── v1.0.0/
│   ├── v1.0.1/
│   └── v1.1.0/                  ← chaque tag = un répertoire
└── htdocs -> releases/v1.1.0    ← symlink vers la release active
```

Chaque deploy :
1. Crée `releases/<tag>/` et y rsync le build
2. Symlinke `.env.local` → `../../.env.local` et `var/` → `../../shared/var/`
3. Bascule le symlink `htdocs` vers la nouvelle release (atomique)
4. Conserve les N dernières releases pour rollback rapide

**Workflow simplifié** (`.github/workflows/deploy.yaml`) :

```yaml
name: Deploy
on:
  push:
    tags: ['v[0-9]+.[0-9]+.[0-9]+']
concurrency:
  group: deploy
  cancel-in-progress: false
jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Write VERSION
        run: echo "${{ github.ref_name }}" > VERSION
      - name: Install dependencies
        run: composer install --no-dev --optimize-autoloader --no-scripts
      - name: Deploy via rsync + SSH
        env:
          SSH_KEY: ${{ secrets.SSH_PRIVATE_KEY }}
          HOST: ${{ secrets.OVH_HOST }}
          USER: ${{ secrets.OVH_USER }}
          DEPLOY_PATH: ${{ secrets.OVH_DEPLOY_PATH }}
          TAG: ${{ github.ref_name }}
        run: |
          mkdir -p ~/.ssh
          echo "$SSH_KEY" > ~/.ssh/deploy_key
          chmod 600 ~/.ssh/deploy_key
          SSH_OPTS="-o StrictHostKeyChecking=no -i ~/.ssh/deploy_key"

          # rsync le build dans releases/<tag>
          rsync -azP --delete \
            --exclude='.git/' \
            --exclude='.env.local' \
            --exclude='var/' \
            --exclude='node_modules/' \
            ./ "$USER@$HOST:$DEPLOY_PATH/releases/$TAG/"

          # Symlinks partagés + bascule htdocs + migrations
          ssh $SSH_OPTS "$USER@$HOST" bash -s <<REMOTE
            cd $DEPLOY_PATH
            ln -sfn ../../.env.local releases/$TAG/.env.local
            ln -sfn ../../shared/var releases/$TAG/var
            ssh $SSH_OPTS "$USER@$HOST" "cd $DEPLOY_PATH/releases/$TAG && composer install --no-dev --optimize-autoloader"
            cd releases/$TAG && php bin/console doctrine:migrations:migrate --no-interaction
            cd $DEPLOY_PATH
            ln -sfn releases/$TAG htdocs
            # Garder les 5 dernières releases
            ls -1dt releases/v* | tail -n +6 | xargs rm -rf
          REMOTE
```

> OVH mutualisé (offre Pro+) supporte SSH. L'action [`Burnett01/rsync-deployments`](https://github.com/Burnett01/rsync-deployments) peut remplacer le rsync manuel si besoin.

### Renommage du site

Migrer de `pay.loreillepresqueparfaite.com` vers `inscriptions.loreillepresqueparfaite.com`.

- Créer le vhost OVH
- Mettre à jour les URLs Stripe (webhook endpoint, success/cancel URLs)
- Redirection 301 depuis l'ancien domaine

### Version dans le footer

Afficher le numéro de version (tag git) dans le footer via le fichier `VERSION` généré par la CI.

- Lire `VERSION` au boot (paramètre Symfony ou variable Twig globale)
- Afficher dans `base.html.twig` : `v1.0.0`
- En dev sans fichier `VERSION` : afficher `dev-{branch}-{short_hash}` (via `git` à la volée)

---

## v1.1 — Gestion des saisons

### Standardisation des tarifs

Supprimer le rythme x9 mensuel. Tous les cours passent en **x10 mensuel** ou **x3 trimestriel** uniquement. Simplification pour les adhérents et pour le code (plus besoin de gérer la divisibilité par 9).

### Saison dans les métadonnées Stripe

Ajouter un metadata `opp_season` (ex: `2026-2027`) sur chaque Price Stripe. L'app n'affiche que les prix correspondant à la saison en cours.

### Adhésion sans notion de saison

Renommer le produit Stripe en **« Adhésion »** (tout court, sans l'année). Le produit reste unique et permanent — c'est la table `membership` en SQLite qui déduplique par email + année scolaire, pas Stripe. Les Prices de l'adhésion ne portent pas de metadata `opp_season`.

### Métadonnées Stripe : inventaire cible

**Product metadata :**

| Clé | Exemple | Usage |
|---|---|---|
| `opp_category` | `cours-annee` | Filtrage des produits par catégorie dans l'UI |

**Price metadata :**

| Clé | Exemple | Usage |
|---|---|---|
| `opp_season` | `2026-2027` | Filtrage par saison — absent sur adhésion et don (produits permanents) |
| `opp_installments` | `10` | Nombre d'échéances — remplace le parsing du suffix `-Nx$` du lookup_key |
| `opp_interval` | `month` | `month` ou `quarter` — redondant avec `recurring.interval` mais explicite pour le calcul de cancel_at |
| `opp_reduced` | `true` | Tarif réduit — remplace la détection de `-reduc-` dans le lookup_key |

**Price `nickname` (champ Stripe natif, visible dans le Dashboard) :**

Format : `{nom cours} — {saison} [{réduit}]`
Exemples : `Guinguette Marseille 2x — 2026-2027`, `Guinguette Marseille 2x — 2026-2027 [réduit]`

**Checkout Session metadata** (inchangé) :

| Clé | Valeur |
|---|---|
| `school_year` | `2026-2027` |
| `adhesion_amount_cents` | `1000` |

**Subscription metadata** (inchangé) :

| Clé | Valeur |
|---|---|
| `cancel_at` | `1751328000` (timestamp Unix) |

### Suppression des `lookup_key`

Aujourd'hui, les `lookup_key` encodent 4 informations dans une convention de nommage (`{slug}-{reduc?}-{interval}-{installments}x`). Elles sont parsées par regex à 6 endroits du code :

| Fichier | Usage du lookup_key |
|---|---|
| `StripeCheckoutService::isRecurringPrice()` | Détecter si récurrent (regex `-(\d+)x$`) |
| `StripeCheckoutService::computeCancelAt()` | Extraire installments + interval (regex + `str_contains`) |
| `AppExtension::priceLabel()` | Afficher "x9" / "x10" (regex) |
| `AppExtension::priceAnnualTotal()` | Calculer le total annuel (regex) |
| `AppExtension::isReducedPrice()` | Détecter tarif réduit (`str_contains('-reduc-')`) |
| `CheckoutController` / templates | Passé en hidden field jusqu'au `createCheckoutSession()` |

**Migration :** remplacer chaque regex par une lecture de `price.metadata['opp_installments']`, `opp_interval`, `opp_reduced`. Les `lookup_key` Stripe peuvent être conservées pour la rétrocompatibilité API mais ne sont plus lues par le code.

### Commande `opp:products:create` — argument saison

```bash
bin/console opp:products:create 2027-2028
```

- Argument obligatoire : la saison (ex: `2027-2028`)
- Crée de nouveaux Prices pour la saison demandée sur les Products existants
- Écrit les metadata `opp_season`, `opp_installments`, `opp_interval`, `opp_reduced` sur chaque Price
- Met le `nickname` du Price au format `{nom} — {saison} [{réduit}]`
- Produits permanents (adhésion, don) : pas de `opp_season`, pas de nouveau Price si déjà existant
- Idempotent : détection par `nickname` ou par combinaison metadata pour éviter les doublons

---

## v1.2.0 — Panier multi-cours

Évolution de l'UX : passer d'une sélection mono-produit à un **panier avec checkout en une page**.

### Sélection des cours

- Page unique avec tous les cours disponibles pour la saison en cours
- Bouton « Ajouter » sur chaque cours
- Choix du rythme de paiement (mensuel / trimestriel) par produit
- Affichage en temps réel du **prix total annuel** et de la **première mensualité** directement dans la page de sélection

### Réduction automatique par le panier

Remplace la checkbox déclarative actuelle. Si le panier contient un cours d'instrument hebdomadaire ou un atelier mensuel, le tarif réduit Guinguette s'applique automatiquement.

**Logique :** la présence d'un produit éligible (cours hebdo, atelier Café Belsunce) dans le panier déclenche le swap vers le Price `opp_reduced=true` pour les produits Guinguette Orchestra.

### One-page checkout

Après sélection des cours :
- Récapitulatif du panier (cours + rythmes + prix)
- Saisie email
- Adhésion conditionnelle (si pas encore payée pour la saison)
- Don optionnel
- Bouton unique → redirection vers Stripe Checkout Session

### Contrainte Stripe à résoudre

En mode `subscription`, toutes les line items récurrentes doivent partager le même `interval` et `interval_count`. Solutions possibles :
- Forcer le même rythme pour tout le panier
- Créer plusieurs subscriptions (plusieurs Checkout Sessions ou API directe)
- Voir [ADR-001](doc/adr-001-architecture-checkout.md) pour le détail de la contrainte
