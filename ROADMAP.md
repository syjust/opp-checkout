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
