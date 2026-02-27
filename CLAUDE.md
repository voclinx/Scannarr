# CLAUDE.md — Instructions pour le projet Scanarr

## Contexte

Tu travailles sur **Scanarr**, une application de gestion et surveillance de bibliothèque multimédia. La spécification technique est découpée en fichiers thématiques dans le dossier `docs/`. **Commence toujours par lire `docs/README.md`** pour savoir quel fichier consulter selon la tâche.

## Règle absolue

**Avant chaque phase de développement, lis les fichiers de spec correspondants dans `docs/`.** Ne code jamais de mémoire — retourne toujours vérifier les spécifications. Le `docs/README.md` contient une table de routage "je travaille sur X → lire le fichier Y".

## Documentation technique

```
docs/
├── README.md                      ← LIRE EN PREMIER — Index + routage
├── ARCHITECTURE.md                ← Stack, composants, Docker, systemd, auth, déploiement
├── DATABASE.md                    ← Schéma PostgreSQL, tables, index, relations
├── API.md                         ← Endpoints REST, controllers, format réponse, regex parsing
├── FRONTEND.md                    ← Routes Vue.js, types TypeScript, stores Pinia, composants
├── WATCHER.md                     ← Go : config, WebSocket, scanner, fsnotify, hardlinks
├── DELETION.md                    ← Chaîne de suppression, WebSocket delete, reconnexion, statuts
├── EXTERNAL_SERVICES.md           ← Radarr, TMDB, Plex, Jellyfin, qBittorrent, Discord
├── PATH_MAPPING.md                ← Chemins Docker, mounts, host_path, hardlinks entre services
├── QBIT_STATS_AND_SCORING.md      ← V1.5 : sync qBit, score suppression, presets, suggestions
├── CROSS_SEED.md                  ← V1.5 : cross-seed, groupement torrents, partial_hash
├── HARDLINK_MANAGEMENT.md         ← V1.5 : remplacement fichier lecteur, création hardlinks
├── TESTING.md                     ← Plan de tests unitaires, intégration, matrice
├── IMPLEMENTATION_ORDER.md        ← Phases d'implémentation, ordre des tâches
└── CHANGELOG.md                   ← Historique des versions
```

**Chaque fichier est autonome** sur son domaine. Les prérequis sont listés en tête de fichier. Ne charge que les fichiers nécessaires à ta tâche.

## Architecture

Le projet est un **monorepo** composé de 3 briques :

| Dossier | Stack | Rôle |
|---------|-------|------|
| `api/` | Symfony 7 / PHP 8.3 / Doctrine / JWT | Back-end API REST + serveur WebSocket |
| `front/` | Vue.js 3 / TypeScript / Vite / Pinia / PrimeVue / Tailwind | Front-end SPA |
| `watcher/` | Go 1.22+ / fsnotify / gorilla/websocket | Binaire natif (hors Docker) — surveillance filesystem |

Base de données : **PostgreSQL 16** (dans Docker).

Le watcher **ne tourne PAS dans Docker**. C'est un binaire Go natif installé sur le serveur hôte avec un service systemd. Voir `docs/ARCHITECTURE.md` section 12.5.

## Versions

| Version | Périmètre | Docs |
|---------|-----------|------|
| **V1.0** | MVP : auth, explorateur, films, suppression planifiée, watcher, Discord | ARCHITECTURE, DATABASE, API, FRONTEND, WATCHER, DELETION, EXTERNAL_SERVICES |
| **V1.2.1** | Chaîne suppression via watcher, qBit cleanup, Plex/Jellyfin refresh | DELETION |
| **V1.5** | Stats qBit, score, presets, suggestions, cross-seed, hardlinks, règles trackers | QBIT_STATS_AND_SCORING, CROSS_SEED, HARDLINK_MANAGEMENT, PATH_MAPPING |

## Conventions de code

### PHP / Symfony
- PSR-12 pour le style de code
- Nommage : PascalCase pour les classes, camelCase pour les méthodes et variables
- Chaque entité Doctrine dans `src/Entity/`, chaque repository dans `src/Repository/`
- Services dans `src/Service/`, commandes CLI dans `src/Command/`
- Enums PHP 8.1+ dans `src/Enum/`
- Attributs PHP 8 pour Doctrine (`#[ORM\Entity]`, `#[ORM\Column]`, etc.)
- Attributs Symfony pour le routing (`#[Route]`)
- Validation par attributs Symfony Validator (`#[Assert\NotBlank]`, etc.)
- Format de réponse API : toujours `{ "data": ... , "meta": ... }` ou `{ "error": ... }`
- UUID pour tous les identifiants (pas d'auto-increment)

### TypeScript / Vue.js
- Composition API avec `<script setup lang="ts">` systématiquement
- Types définis dans `src/types/index.ts`
- Stores Pinia dans `src/stores/` — un store par domaine
- Composables dans `src/composables/`
- Axios avec interceptor JWT refresh automatique sur 401
- PrimeVue DataTable pour tous les tableaux avec pagination serveur
- Tailwind pour le layout et l'espacement, PrimeVue pour les composants interactifs
- Pas d'utilisation de `any` — typer tout explicitement

### Go
- Structure standard Go avec `cmd/`, `internal/`
- Packages internes : `config`, `watcher`, `scanner`, `websocket`, `models`
- Structured logging avec `log/slog`
- Gestion d'erreurs explicite (pas de panic en prod)
- Tests dans des fichiers `_test.go` à côté du code

### Base de données
- UUID partout (pas de SERIAL/auto-increment)
- Timestamps : `created_at` et `updated_at` sur toutes les tables
- Index sur les colonnes fréquemment filtrées
- Relations avec ON DELETE CASCADE ou SET NULL selon la spec
- JSONB pour les données flexibles

## Points d'attention critiques

### Correspondance chemins Watcher ↔ API Docker
La table `volumes` a `path` (chemin Docker API) et `host_path` (chemin réel hôte). Le watcher envoie des chemins `host_path`. Voir `docs/PATH_MAPPING.md`.

### Suppression de fichiers — Chaîne complète
- L'API ne fait JAMAIS de `unlink()`. Tout passe par le watcher via WebSocket.
- Toute suppression passe par `ScheduledDeletion → DeletionService → Watcher`.
- Suppression hardlink-aware : supprimer TOUS les hardlinks (media/ + torrents/ + cross-seed/).
- Règles tracker : vérifier le garde-fou avant toute suppression.
- Voir `docs/DELETION.md` et `docs/PATH_MAPPING.md`.

### Cross-seed
- Un fichier peut avoir N torrents (1 par tracker).
- Le `partial_hash` (premiers 1MB + derniers 1MB) sert au groupement.
- Le score agrège la valeur cross-seed. Les règles tracker s'appliquent individuellement.
- Voir `docs/CROSS_SEED.md`.

### Presets de score
- Les presets stockent TOUTE la logique de décision (critères, poids, filtres).
- Ils doivent être rejouables programmatiquement (prévision nettoyage automatique futur).
- Le calcul du score se fait côté front (live preview).
- Voir `docs/QBIT_STATS_AND_SCORING.md`.

## Ce qu'il ne faut PAS faire

- Ne pas inventer de endpoints ou de tables qui ne sont pas dans la spec
- Ne pas utiliser d'auto-increment — UUID partout
- Ne pas mettre le watcher dans Docker
- Ne pas stocker les mots de passe en clair — bcrypt ou Argon2
- Ne pas hardcoder les URLs des services externes — tout passe par la config/settings
- Ne pas créer de fichiers de migration manuellement — utiliser `doctrine:migrations:diff`
- Ne pas cumuler le seed time entre trackers — c'est une donnée par tracker

## Comment travailler

1. **Lis `docs/README.md`** pour identifier les fichiers pertinents
2. **Lis les fichiers de spec** correspondant à ta tâche avant de coder
3. **Après chaque étape majeure**, vérifie que l'application compile/démarre correctement
4. **Écris les tests au fur et à mesure** — pas seulement en fin de phase
5. **Commite régulièrement** avec des messages clairs : `feat: add volume CRUD`, `fix: watcher reconnection logic`, etc.
6. Si tu as un doute sur un comportement, **la spec fait autorité**. Relis-la.
