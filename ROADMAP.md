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

## v1.1.0 — Refonte produits, panier et saisons

> v1.1 et v1.2 fusionnées — rien n'est en prod, on repart sur une base propre.

### Restructuration des produits Stripe

**Produits cibles (7 produits cours + adhésion + don) :**

| Produit Stripe | Catégorie | Réduction possible |
|---|---|---|
| Cours d'instruments hebdomadaire | `cours-annee` | Non (éligibilisant) |
| Atelier Café Belsunce | `cours-annee` | Non (éligibilisant) |
| Guinguette Orchestra Marseille (2 ateliers / mois) | `cours-annee` | Oui |
| Guinguette Orchestra Marseille (1 atelier / mois) | `cours-annee` | Oui |
| Guinguette Orchestra Aix-en-Provence (9 ateliers / an) | `cours-annee` | Oui |
| Atelier d'accordéon diatonique Aix-en-Provence | `cours-annee` | Non |
| Atelier d'accordéon diatonique Aubagne | `cours-annee` | Non |
| Cours particulier 1h (instrument) | `cours-unite` | Non |
| Adhésion | `adhesion` | — |
| Don à l'OPP | `don` | — |

Les noms de produits ne contiennent plus « / année ». Le lieu et la fréquence sont dans le nom du Product, pas dans les Prices.

**Description enrichie :** pour chaque cours où le nombre d'ateliers/an est connu, afficher le coût par atelier dans la description (ex: « 20 séances — 25 €/séance »).

### Rythmes de paiement (Prices par Product)

Chaque cours à l'année a **3 Prices** par saison :

| Rythme | Stripe mode | Installments |
|---|---|---|
| **1x** (paiement unique) | `payment` (one_time) | — |
| **3x** (trimestriel) | `subscription` (recurring, interval: month, interval_count: 3) | 3 |
| **10x** (mensuel) | `subscription` (recurring, interval: month, interval_count: 1) | 10 |

**Arrondis pour le 3x :** pour les montants annuels non divisibles par 3, on arrondit l'échéance au centime inférieur à 0,50 € près en dessous. Ex : 500 € annuel → 3 × 166,50 € = 499,50 €.

Les produits éligibles à la réduction ont **6 Prices** (3 normaux + 3 réduits).

Le sélecteur de rythme dans l'UI propose 1x / 3x / 10x pour chaque produit.

### DEVX — Suppression des env vars Product ID

Remplacer `ADHESION_PRODUCT_ID` et `DONATION_PRODUCT_ID` (env vars) par une résolution via metadata `opp_category` (déjà existante sur chaque Product). L'app cherche le produit avec `opp_category=adhesion` et `opp_category=don` au lieu de stocker des IDs.

### Métadonnées Stripe : inventaire cible

**Product metadata :**

| Clé | Exemple | Usage |
|---|---|---|
| `opp_category` | `cours-annee` | Filtrage par catégorie dans l'UI |
| `opp_grants_reduction` | `true` | Ce produit donne droit à la réduction (cours instruments, Café Belsunce) |
| `opp_reducible` | `true` | Ce produit peut recevoir une réduction (Guinguette) |

**Price metadata :**

| Clé | Exemple | Usage |
|---|---|---|
| `opp_season` | `2026-2027` | Filtrage par saison — absent sur adhésion et don |
| `opp_installments` | `10` | Nombre d'échéances (absent pour paiement unique) |
| `opp_reduced` | `true` | Tarif réduit |

**Price `nickname` (visible dans le Stripe Dashboard) :**

Format : `{nom cours} — {saison} {rythme} [{réduit}]`
Exemples : `Guinguette Marseille 2x — 2026-2027 10x`, `Guinguette Marseille 2x — 2026-2027 3x [réduit]`

**Checkout Session metadata :**

| Clé | Valeur |
|---|---|
| `school_year` | `2026-2027` |
| `adhesion_amount_cents` | `1000` |

### Gestion des abonnements — `subscription_schedule` au lieu de `cancel_at`

Remplacer le mécanisme `cancel_at` (date calculée, fragile si le premier paiement est décalé) par l'API `Subscription Schedules` de Stripe :
- Créer un schedule avec une phase de N `iterations` (10 pour mensuel, 3 pour trimestriel)
- Stripe arrête automatiquement après le dernier paiement
- Plus besoin de stocker `cancel_at` en metadata ni de l'appliquer via webhook
- Le schedule est visible et modifiable dans le Dashboard

### Vérification des achats existants — modèle hybride

**Table locale SQLite (déjà existante : `membership`)** pour :
- Adhésion déjà payée pour la saison en cours → ne pas reproposer

**Nouvelle table `purchase`** pour :
- Enregistrer les achats de cours par email + saison + product_id
- Permet de savoir si un produit éligibilisant (cours instruments, Café Belsunce) a déjà été souscrit
- → applique automatiquement la réduction sur Guinguette même si le cours n'est pas dans le panier actuel

Remplie par le webhook `checkout.session.completed`, comme pour `membership`.

### UI / UX — Panier et checkout

**Séparation des types de produits :**
- Les `cours-unite` (cours particulier) et les futurs `stage` sont vendus **seuls** (pas combinables avec les cours à l'année)
- Les `cours-annee` sont combinables entre eux dans un panier

**Page de sélection (one-page) :**
- Tous les cours à l'année de la saison en cours affichés
- Bouton « Ajouter au panier » par produit
- Sélecteur de rythme (1x / 3x / 10x) par produit dans le panier
- Affichage en temps réel du **prix total annuel** et de la **première échéance**
- Pour chaque cours avec un nombre d'ateliers connu : afficher le coût par atelier

**Réductions :**
- Indicateur visuel sur les produits Guinguette : « tarif réduit disponible si cours d'instrument ou Café Belsunce au panier »
- Lorsqu'un produit éligibilisant est dans le panier OU a déjà été acheté pour la saison (table `purchase`) :
  - Swap automatique vers le Price réduit
  - Affichage du prix normal **barré** à côté du prix réduit

**Checkout (après sélection) :**
- Récapitulatif du panier (cours + rythmes + prix + réductions)
- Saisie email → check adhésion + achats existants
- Adhésion conditionnelle (prix libre, min 1 €)
- Don optionnel
- Bouton unique → redirection vers Stripe Checkout Session

**Contrainte Stripe à résoudre :** en mode `subscription`, toutes les line items récurrentes doivent partager le même `interval` et `interval_count`. Solution retenue : **forcer le même rythme pour tout le panier** (le sélecteur 1x/3x/10x est global, pas par produit). Cf. [ADR-001](doc/adr-001-architecture-checkout.md).

### Commande `opp:products:create` — argument saison

```bash
bin/console opp:products:create 2026-2027
```

- Argument obligatoire : la saison
- Crée les Products (idempotent, détection par nom)
- Crée les Prices pour la saison avec toutes les metadata + nickname
- Génère les 3 rythmes (1x, 3x, 10x) × normal/réduit selon le produit
- Produits permanents (adhésion, don) : pas de `opp_season`
- Archivage des anciens prix (flag `--archive-old`)
