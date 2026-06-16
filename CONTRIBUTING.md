# Contribuer à Laraprint

Merci de votre intérêt ! Ce guide explique comment mettre en place l'environnement,
lancer les tests et proposer vos changements.

## Prérequis

- **PHP 8.3+**
- **Composer**
- Git

## Mise en place

```bash
git clone https://github.com/neocodesupport/laraprint.git
cd laraprint
composer install
```

Le dépôt est un **package** (pas une application Laravel) : il n'y a pas de binaire
`artisan`. Les tests s'exécutent directement via PHPUnit, sans application hôte.

## Lancer les tests

```bash
vendor/bin/phpunit
```

- `tests/Unit` — logique pure (DTO, enums, fabrique de connecteurs, télémétrie…).
- `tests/Feature` — registre & imprimante par défaut (base **SQLite en mémoire** via Eloquent Capsule, aucune base externe requise).

Lancer une suite ciblée :

```bash
vendor/bin/phpunit --testsuite=Unit
vendor/bin/phpunit --testsuite=Feature
vendor/bin/phpunit --filter=PrinterRegistryTest
```

## Style de code

Le projet suit le style **Laravel Pint** (preset Laravel). Avant de committer :

```bash
vendor/bin/pint          # corrige automatiquement
vendor/bin/pint --test   # vérifie sans rien modifier (utilisé en CI)
```

La CI GitHub Actions exécute PHPUnit (PHP 8.3/8.4 × Laravel min/max) **et** `pint --test` :
gardez les deux verts.

## Conventions

- **Branches** : `feat/...`, `fix/...`, `docs/...`, `chore/...`.
- **Commits** : style impératif court, idéalement préfixé par un type
  (`feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `chore:`).
- **Code** :
  - `declare(strict_types=1);` en tête de chaque fichier ;
  - typage strict des propriétés, paramètres et retours ;
  - les classes du domaine sont `final` quand l'extension n'est pas prévue ;
  - aucune dépendance dure à une application Laravel démarrée : les accès au conteneur
    (`events`, `log`, `session`, `config`…) doivent rester **optionnels** (voir
    `Support\Telemetry` et `Printers\CurrentWorkstation`).
- **Documentation** : mettez à jour `README.md` (EN) **et** `README.fr.md` (FR) si vous
  changez l'API publique, et ajoutez une entrée dans `CHANGELOG.md`.

## Proposer un changement (Pull Request)

1. Forkez le dépôt et créez une branche depuis `main`.
2. Ajoutez/mettez à jour les **tests** couvrant votre changement.
3. Vérifiez `vendor/bin/phpunit` et `vendor/bin/pint --test`.
4. Mettez à jour la **documentation** et le **CHANGELOG**.
5. Ouvrez la PR vers `main` en décrivant le *quoi* et le *pourquoi*.

## Signaler un bug

Ouvrez une *issue* en précisant : version de PHP/Laravel, type de connexion et
d'imprimante, configuration utilisée (sans secrets), étapes de reproduction, et le
message d'erreur complet.

## Sécurité

Merci de **ne pas** ouvrir d'issue publique pour une faille de sécurité. Contactez
l'équipe à **support@necode.ci**.
