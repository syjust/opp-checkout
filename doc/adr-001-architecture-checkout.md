# ADR-001 : Architecture du checkout OPP

**Date :** 2026-09-03
**Statut :** Accepté

## Contexte

L'OPP propose des cours de musique payables en plusieurs fois. Le formulaire HelloAsso utilisé jusqu'ici ne permet pas de gérer des abonnements avec date de fin, ni de combiner abonnement + adhésion à prix libre dans une même session de paiement.

## Décisions prises

### Checkout Stripe en redirect mode
On utilise Stripe Checkout Sessions (redirect vers Stripe) plutôt qu'un formulaire de paiement embarqué. C'est plus simple, PCI-compliant nativement, et Stripe gère la partie paiement (3D Secure, etc.).

### Un seul abonnement par session
Pas de panier multi-produits. L'utilisateur choisit un cours, ajoute optionnellement adhésion + don, et passe au paiement. Une session Stripe = un abonnement + des éléments ponctuels sur la première facture.

### Fin d'abonnement via `cancel_at`
Les abonnements s'auto-annulent après le dernier paiement. La date est calculée à partir du nombre d'échéances encodé dans le `lookup_key` du prix (`-9x`, `-10x`, `-3x`).

### Mensuel x9 ou x10 selon divisibilité
Les montants annuels divisibles par 9 (585€, 225€, 135€, 315€) utilisent x9 mensuel. Les autres (500€, 250€, 300€, 150€) utilisent x10 mensuel pour éviter les arrondis.

### Réduction par checkbox déclarative
Les produits ayant un tarif réduit (Guinguette Orchestra) ont des prix avec `-reduc-` dans le `lookup_key`. Un toggle dans l'UI permet à l'utilisateur de déclarer son éligibilité. Pas de vérification automatique côté serveur.

### Adhésion dédupliquée par année scolaire
La base SQLite locale enregistre les adhésions par email + année scolaire. Si l'email a déjà une adhésion pour l'année en cours, elle n'est pas reproposée lors d'un nouvel achat.

### Produits gérés dans Stripe, pas dans l'app
Les produits et prix sont créés dans Stripe (via la commande `opp:products:create`). L'app les récupère par API et les filtre par metadata `opp_category`.

### Pas d'admin dans le v1
Le suivi des abonnements se fait via le Stripe Dashboard. La base SQLite ne sert qu'au check d'adhésion.

## Points en suspend

### Panier multi-cours
Les élèves inscrits à plusieurs cours (ex: instrument hebdomadaire + Guinguette Orchestra) pourraient vouloir tout payer en une seule session plutôt qu'en deux inscriptions séparées. Cela impliquerait :

- un vrai panier avec ajout/retrait de produits
- la gestion de plusieurs abonnements dans une même Checkout Session (Stripe le supporte avec plusieurs `line_items` récurrents, mais tous doivent partager le même intervalle de facturation)
- la réduction automatique basée sur le contenu du panier (remplacerait la checkbox déclarative)
- potentiellement, demander l'email en amont pour vérifier les abonnements existants et pré-remplir la réduction

**Contrainte Stripe :** en mode `subscription`, toutes les line items récurrentes doivent avoir le même `interval` et `interval_count`. On ne peut pas mixer mensuel et trimestriel dans une seule session. Il faudrait soit forcer le même rythme pour tous les produits du panier, soit créer des sessions séparées.

Ce chantier est reporté — la checkbox couvre le besoin pour le lancement.
