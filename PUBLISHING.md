# Publier Laraprint sur Packagist

Ce guide explique comment rendre le package installable via
`composer require neocode/laraprint`, puis comment automatiser les mises à jour.

> Le **nom du package** (`neocode/laraprint`, défini dans `composer.json`) peut différer
> du **dépôt GitHub** (`neocodesupport/laraprint`) : c'est parfaitement valide. Veillez
> simplement à publier sous le vendor `neocode`, qui doit vous appartenir sur Packagist.

## Pré-requis

- Le dépôt est poussé sur GitHub : ✅ `https://github.com/neocodesupport/laraprint`
- Un tag de version existe : ✅ `v1.0.0`
- `composer.json` contient au minimum `name`, `description`, `license`, `require` : ✅
- Un compte sur [packagist.org](https://packagist.org) (connexion via GitHub recommandée).

## 1. Soumettre le package (une seule fois)

1. Connectez-vous sur https://packagist.org.
2. Cliquez sur **Submit** (https://packagist.org/packages/submit).
3. Collez l'URL du dépôt :
   ```
   https://github.com/neocodesupport/laraprint
   ```
4. Cliquez sur **Check**, puis **Submit**.

Packagist lit `composer.json`, détecte le nom `neocode/laraprint` et importe
automatiquement les versions à partir des **tags Git** (ici `v1.0.0`).

## 2. Activer la mise à jour automatique (webhook)

Sans webhook, Packagist ne voit pas les nouveaux tags tant que vous ne cliquez pas
manuellement sur **Update**. Pour automatiser :

### Option A — Intégration GitHub (recommandée)

1. Sur Packagist : **Profile → Settings**, copiez votre **API Token**.
2. Sur GitHub, dépôt → **Settings → Webhooks → Add webhook** :
   - **Payload URL** : `https://packagist.org/api/github?username=VOTRE_USER_PACKAGIST`
   - **Content type** : `application/json`
   - **Secret** : votre API Token Packagist
   - **Events** : *Just the push event* (suffit pour les tags et les commits)
3. **Add webhook**. Chaque push/tag déclenchera désormais une mise à jour.

### Option B — Crochet global

Sur Packagist, **Profile → Settings → Show API Token**, puis suivez la procédure
« GitHub Hook » proposée pour lier tout votre compte d'un coup.

## 3. Publier une nouvelle version

Le versionnage est piloté par les **tags Git** (SemVer) :

```bash
# 1) Mettez à jour le CHANGELOG.md (nouvelle section de version)
# 2) Committez
git commit -am "chore: release vX.Y.Z"

# 3) Taguez et poussez
git tag -a vX.Y.Z -m "Laraprint vX.Y.Z"
git push origin main
git push origin vX.Y.Z
```

- **patch** (`v1.0.1`) : corrections rétro-compatibles.
- **minor** (`v1.1.0`) : ajouts rétro-compatibles.
- **major** (`v2.0.0`) : changements cassants.

Avec le webhook actif, la version apparaît sur Packagist en quelques secondes.

## 4. Créer la release GitHub (optionnel mais conseillé)

Dépôt → **Releases → Draft a new release** → choisissez le tag (`vX.Y.Z`) →
collez les notes (réutilisez la section correspondante du `CHANGELOG.md`) →
cochez **Set as the latest release** → **Publish**.

## Vérifier l'installation

```bash
composer require neocode/laraprint
```

Si Packagist signale *« This package is not auto-updated »*, vérifiez le webhook
(étape 2) ou cliquez sur **Update** sur la page du package.
