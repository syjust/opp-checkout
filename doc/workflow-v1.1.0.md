# Workflow v1.1.0 — Refonte produits, panier et saisons

Travail découpé en 3 phases séquentielles sur la branche `feat/v1.1.0`.

## Phase 1 — Analyse UX

Objectif : valider la structure des Products/Prices Stripe et l'expérience utilisateur avant d'écrire du code.

1. Mockup HTML statique (Bootstrap 5) des 2 parcours utilisateur :
   - **Cours à l'année** : liste des cours, panier multi-cours, sélecteur de rythme global (1x/3x/10x), réductions automatiques, adhésion, don
   - **Cours à l'unité** : sélection simple, adhésion, don
2. Validation de la structure Products/Prices/metadata Stripe
3. Livrable : `doc/mockup-v1.1.0.html`

## Phase 2 — Backend

Implémentation du backend, dans l'ordre :

1. **`data/products.csv`** — nouveau format avec colonnes slug, catégorie, `opp_reduced_by`, prix par rythme (1x/3x/10x), prix réduits
2. **`ProductsCreateCommand`** — refonte : argument saison, création des 3 rythmes × normal/réduit, metadata (`opp_category`, `opp_reduced_by`, `opp_season`, `opp_installments`, `opp_reduced`), nickname, lookup_key
3. **Migration Doctrine** — table `purchase` (email, season, product_id, price_id, lookup_key, checkout_session_id, created_at)
4. **`StripeCheckoutService`** — résolution adhésion/don par `opp_category` metadata (suppression des env vars `ADHESION_PRODUCT_ID` / `DONATION_PRODUCT_ID`), `subscription_schedule` avec `iterations`, gestion des réductions via `opp_reduced_by`
5. **`WebhookController`** — enregistrement dans la table `purchase` au `checkout.session.completed`

## Phase 3 — Frontend

Templates Twig et logique JS côté client :

6. **Templates Twig** — one-page checkout avec panier latéral, sélecteur de rythme, prix barrés, coût par atelier
7. **JavaScript** — logique panier (ajout/suppression), swap réductions, calcul totaux en temps réel, soumission vers Stripe Checkout
