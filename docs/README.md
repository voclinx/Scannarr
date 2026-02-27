# Scanarr — Documentation Technique

> **Version courante** : V1.5
> **Dernière mise à jour** : 2026-02-27

## Navigation rapide

Ce dossier contient la spécification technique complète de Scanarr, découpée par domaine. Chaque fichier est autonome sur son périmètre.

### 🗺️ Quel fichier lire ?

| Je travaille sur... | Fichier à lire | Dépendances |
|---------------------|----------------|-------------|
| L'architecture globale, le stack, Docker, systemd | [ARCHITECTURE.md](ARCHITECTURE.md) | — |
| Le schéma BDD, les tables, les migrations | [DATABASE.md](DATABASE.md) | — |
| Les endpoints REST, les controllers Symfony | [API.md](API.md) | DATABASE.md |
| L'interface Vue.js, les composants, les stores | [FRONTEND.md](FRONTEND.md) | API.md |
| Le watcher Go, fsnotify, scanner, WebSocket | [WATCHER.md](WATCHER.md) | ARCHITECTURE.md |
| La chaîne de suppression (immédiate + planifiée) | [DELETION.md](DELETION.md) | DATABASE.md, API.md, WATCHER.md |
| Radarr, TMDB, Plex, Jellyfin, qBittorrent | [EXTERNAL_SERVICES.md](EXTERNAL_SERVICES.md) | DATABASE.md |
| Les chemins Docker, mounts, host_path, hardlinks | [PATH_MAPPING.md](PATH_MAPPING.md) | ARCHITECTURE.md |
| Stats qBit, score de suppression, presets, suggestions | [QBIT_STATS_AND_SCORING.md](QBIT_STATS_AND_SCORING.md) | DATABASE.md, EXTERNAL_SERVICES.md, PATH_MAPPING.md |
| Cross-seed, groupement torrents, matching | [CROSS_SEED.md](CROSS_SEED.md) | QBIT_STATS_AND_SCORING.md, PATH_MAPPING.md |
| Remplacement fichier lecteur, création hardlinks | [HARDLINK_MANAGEMENT.md](HARDLINK_MANAGEMENT.md) | DELETION.md, PATH_MAPPING.md |
| Les tests unitaires et d'intégration | [TESTING.md](TESTING.md) | Tous |
| L'ordre d'implémentation, les phases | [IMPLEMENTATION_ORDER.md](IMPLEMENTATION_ORDER.md) | Tous |
| L'historique des versions | [CHANGELOG.md](CHANGELOG.md) | — |

### 📦 Versions

| Version | Périmètre | Status |
|---------|-----------|--------|
| **V1.0** | MVP : auth, explorateur, films, suppression planifiée, watcher, Discord | ✅ Implémenté |
| **V1.2.1** | Chaîne suppression via watcher (filesystem délégué), qBit cleanup, Plex/Jellyfin refresh | ✅ Implémenté |
| **V1.5** | Stats qBit, score de suppression, presets, suggestions, cross-seed, hardlink management, règles trackers | 📋 Spécifié |
| **V2** | Séries (Sonarr), dashboard avancé, lien lecteur | 📝 Planifié |
| **V3** | Automatisation, multi-canaux notifications, API publique | 📝 Planifié |

### ⚠️ Conventions

- **UUID** partout, jamais d'auto-increment
- **Timestamps** : `created_at` + `updated_at` sur toutes les tables
- Les fichiers de cette doc font **autorité** sur le code. En cas de doute, la doc prime.
- Chaque fichier commence par un bloc `Prérequis` listant les fichiers à lire en complément si nécessaire
