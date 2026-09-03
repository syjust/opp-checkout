# OPP — Checkout Stripe

Application de paiement pour **L'Oreille Presque Parfaite** (OPP), association de musique loi 1901 basée à Marseille.

Accessible sur `pay.loreillepresqueparfaite.com`, cette app remplace le formulaire HelloAsso pour gérer les inscriptions aux cours de musique avec paiement en plusieurs fois.

## Fonctionnalités

- Inscription à un cours avec paiement mensuel (x9 ou x10) ou trimestriel (x3)
- Adhésion annuelle à prix libre (minimum 1 €), ajoutée automatiquement si non encore payée pour l'année scolaire en cours
- Don optionnel avec montants suggérés
- Le tout combiné en une seule session Stripe Checkout (abonnement + éléments ponctuels sur la première facture)
- Webhook Stripe pour enregistrer les adhésions et programmer la fin automatique des abonnements (`cancel_at`)

## Stack technique

- **Symfony 8.1** / PHP 8.3+
- **Twig** + Bootstrap 5 (CDN, pas de build front)
- **stripe/stripe-php** SDK
- **SQLite** via Doctrine ORM (suivi des adhésions uniquement)
- Hébergé sur **OVH mutualisé** (`public/` comme web root)

## Développement local

```bash
composer install
bin/console doctrine:migrations:migrate
symfony serve
```

Copier `.env` vers `.env.local` et renseigner les variables Stripe :

```
STRIPE_SECRET_KEY=sk_test_...
STRIPE_PUBLISHABLE_KEY=pk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
ADHESION_PRODUCT_ID=prod_...
DONATION_PRODUCT_ID=prod_...
```

### Créer les produits Stripe

Les produits et prix sont définis dans `data/products.csv` et poussés vers Stripe via :

```bash
bin/console opp:products:create --dry-run   # aperçu
bin/console opp:products:create             # création (idempotent)
```

Les IDs des produits Adhésion et Don sont à reporter dans `.env.local` après la première exécution.

### Tester les webhooks

```bash
stripe listen --forward-to https://127.0.0.1:8000/webhook/stripe
```

## Architecture

Voir [ADR-001](doc/adr-001-architecture-checkout.md) pour les décisions d'architecture détaillées.

### Catégories de produits Stripe

Chaque produit Stripe porte un metadata `opp_category` :

| Catégorie | Usage |
|---|---|
| `cours-annee` | Cours/ateliers à l'année (récurrent) |
| `cours-unite` | Cours à l'unité (ponctuel) |
| `stage` | Stages (ponctuel) |
| `adhesion` | Adhésion annuelle (prix libre) |
| `don` | Don (prix libre) |

### Convention des `lookup_key`

```
{slug}-mensuel-9x      → mensuel, 9 échéances
{slug}-mensuel-10x     → mensuel, 10 échéances
{slug}-trimestriel-3x  → trimestriel, 3 échéances
{slug}-unique           → paiement unique
{slug}-reduc-*          → tarif réduit (checkbox déclarative)
```

### Année scolaire

Calculée automatiquement : avant le 1er août → `(N-1)-(N)`, à partir du 1er août → `(N)-(N+1)`.
Sert à dédupliquer les adhésions par email.
