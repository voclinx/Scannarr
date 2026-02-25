# ============================================================================
# Scanarr — Makefile
# ============================================================================
# Usage: make <commande>
# Lancer `make help` pour voir toutes les commandes disponibles.
# ============================================================================

.DEFAULT_GOAL := help

# ---------- Couleurs ----------
GREEN  := \033[0;32m
YELLOW := \033[0;33m
CYAN   := \033[0;36m
RESET  := \033[0m

# ---------- Variables ----------
DC      := docker compose
DC_DEV  := docker compose -f docker-compose.yml -f docker-compose.dev.yml
API     := docker exec scanarr-api
FRONT   := docker exec scanarr-front
DB      := docker exec scanarr-db

# ============================================================================
# 🐳 Docker
# ============================================================================

.PHONY: up
up: ## Démarrer tous les containers (dev)
	$(DC_DEV) up -d

.PHONY: up-prod
up-prod: ## Démarrer tous les containers (prod)
	$(DC) up -d

.PHONY: down
down: ## Arrêter tous les containers
	$(DC_DEV) down

.PHONY: restart
restart: ## Redémarrer tous les containers (dev)
	$(DC_DEV) restart

.PHONY: build
build: ## Rebuild les images Docker (dev)
	$(DC_DEV) build --no-cache

.PHONY: build-prod
build-prod: ## Rebuild les images Docker (prod)
	$(DC) build --no-cache

.PHONY: logs
logs: ## Voir les logs de tous les containers
	$(DC_DEV) logs -f

.PHONY: logs-api
logs-api: ## Voir les logs de l'API
	$(DC_DEV) logs -f api

.PHONY: logs-front
logs-front: ## Voir les logs du Front
	$(DC_DEV) logs -f front

.PHONY: logs-db
logs-db: ## Voir les logs de PostgreSQL
	$(DC_DEV) logs -f db

.PHONY: ps
ps: ## Voir l'état des containers
	$(DC_DEV) ps

# ============================================================================
# 🔧 API (Symfony / PHP)
# ============================================================================

.PHONY: api-shell
api-shell: ## Ouvrir un shell dans le container API
	$(API) bash

.PHONY: api-cc
api-cc: ## Vider le cache Symfony
	$(API) php bin/console cache:clear

.PHONY: composer-install
composer-install: ## Installer les dépendances Composer
	$(API) composer install

.PHONY: composer-update
composer-update: ## Mettre à jour les dépendances Composer
	$(API) composer update

.PHONY: composer-require
composer-require: ## Ajouter un package (usage: make composer-require PKG=vendor/package)
	$(API) composer require $(PKG)

# ---------- Base de données ----------

.PHONY: db-migrate
db-migrate: ## Exécuter les migrations Doctrine
	$(API) php bin/console doctrine:migrations:migrate --no-interaction

.PHONY: db-diff
db-diff: ## Générer une nouvelle migration à partir des changements d'entités
	$(API) php bin/console doctrine:migrations:diff

.PHONY: db-status
db-status: ## Voir le statut des migrations
	$(API) php bin/console doctrine:migrations:status

.PHONY: db-rollback
db-rollback: ## Annuler la dernière migration
	$(API) php bin/console doctrine:migrations:migrate prev --no-interaction

.PHONY: db-shell
db-shell: ## Ouvrir un shell psql dans PostgreSQL
	$(DB) psql -U scanarr -d scanarr

.PHONY: db-create-test
db-create-test: ## Créer la base de données de test
	$(DB) psql -U scanarr -c "CREATE DATABASE scanarr_test;" 2>/dev/null || true
	$(API) php bin/console doctrine:migrations:migrate --no-interaction --env=test

# ---------- JWT ----------

.PHONY: jwt-generate
jwt-generate: ## Générer les clés JWT
	$(API) php bin/console lexik:jwt:generate-keypair --overwrite

# ---------- Commandes Scanarr ----------

.PHONY: sync-radarr
sync-radarr: ## Synchroniser les films depuis Radarr
	$(API) php bin/console scanarr:sync-radarr

.PHONY: process-deletions
process-deletions: ## Exécuter les suppressions planifiées
	$(API) php bin/console scanarr:process-deletions

.PHONY: send-reminders
send-reminders: ## Envoyer les rappels Discord
	$(API) php bin/console scanarr:send-reminders

.PHONY: websocket
websocket: ## Démarrer le serveur WebSocket manuellement
	$(API) php bin/console app:websocket:run

# ============================================================================
# 🎨 Front (Vue.js / Vite)
# ============================================================================

.PHONY: front-shell
front-shell: ## Ouvrir un shell dans le container Front
	$(FRONT) sh

.PHONY: npm-install
npm-install: ## Installer les dépendances npm
	$(FRONT) npm install

.PHONY: npm-build
npm-build: ## Build de production du front
	$(FRONT) npm run build

.PHONY: npm-lint
npm-lint: ## Linter le code front
	$(FRONT) npm run lint

# ============================================================================
# 🧪 Tests
# ============================================================================

.PHONY: test
test: test-api test-front test-go ## Lancer TOUS les tests

.PHONY: test-api
test-api: ## Lancer les tests PHPUnit (API)
	$(API) php vendor/bin/phpunit

.PHONY: test-api-unit
test-api-unit: ## Lancer uniquement les tests unitaires (API)
	$(API) php vendor/bin/phpunit --testsuite Unit

.PHONY: test-api-functional
test-api-functional: ## Lancer uniquement les tests fonctionnels (API)
	$(API) php vendor/bin/phpunit --testsuite Functional

.PHONY: test-api-coverage
test-api-coverage: ## Lancer les tests PHPUnit avec couverture de code
	$(API) php vendor/bin/phpunit --coverage-text

.PHONY: test-api-filter
test-api-filter: ## Lancer un test spécifique (usage: make test-api-filter FILTER=testLogin)
	$(API) php vendor/bin/phpunit --filter $(FILTER)

.PHONY: test-front
test-front: ## Lancer les tests Vitest (Front)
	$(FRONT) npx vitest run

.PHONY: test-front-watch
test-front-watch: ## Lancer Vitest en mode watch
	$(FRONT) npx vitest

.PHONY: test-front-coverage
test-front-coverage: ## Lancer Vitest avec couverture de code
	$(FRONT) npx vitest run --coverage

.PHONY: test-go
test-go: ## Lancer les tests Go (Watcher)
	cd watcher && go test ./... -v

.PHONY: test-go-coverage
test-go-coverage: ## Lancer les tests Go avec couverture
	cd watcher && go test ./... -v -cover -coverprofile=coverage.out

# ============================================================================
# 🐿️ Watcher (Go)
# ============================================================================

.PHONY: watcher-build
watcher-build: ## Compiler le binaire du watcher
	cd watcher && go build -o bin/scanarr-watcher .

.PHONY: watcher-run
watcher-run: ## Lancer le watcher localement
	cd watcher && go run .

.PHONY: watcher-install
watcher-install: ## Installer le watcher via le script d'installation
	cd watcher && sudo bash install.sh

# ============================================================================
# 🧹 Nettoyage
# ============================================================================

.PHONY: clean
clean: ## Nettoyer les caches et fichiers temporaires
	$(API) php bin/console cache:clear
	$(API) rm -rf var/log/*.log
	@echo "$(GREEN)✓ Caches nettoyés$(RESET)"

.PHONY: clean-docker
clean-docker: ## Supprimer les containers, volumes et images
	$(DC_DEV) down -v --rmi local
	@echo "$(GREEN)✓ Docker nettoyé$(RESET)"

.PHONY: clean-vendors
clean-vendors: ## Supprimer vendor/ et node_modules/
	$(API) rm -rf vendor
	$(FRONT) rm -rf node_modules
	@echo "$(GREEN)✓ Dépendances supprimées$(RESET)"

# ============================================================================
# 📦 Setup initial
# ============================================================================

.PHONY: install
install: ## Installation complète du projet (1ère fois)
	@echo "$(CYAN)📦 Installation de Scanarr...$(RESET)"
	cp -n .env.example .env 2>/dev/null || true
	$(DC_DEV) build
	$(DC_DEV) up -d
	@echo "$(YELLOW)⏳ Attente de PostgreSQL...$(RESET)"
	@sleep 5
	$(API) composer install
	$(API) php bin/console lexik:jwt:generate-keypair --skip-if-exists
	$(API) php bin/console doctrine:migrations:migrate --no-interaction
	@echo "$(GREEN)✅ Installation terminée !$(RESET)"
	@echo "$(CYAN)Front: http://localhost:3000$(RESET)"
	@echo "$(CYAN)API:   http://localhost:8080$(RESET)"

.PHONY: reset
reset: ## Remettre à zéro la BDD et relancer les migrations
	$(API) php bin/console doctrine:database:drop --force --if-exists
	$(API) php bin/console doctrine:database:create
	$(API) php bin/console doctrine:migrations:migrate --no-interaction
	@echo "$(GREEN)✓ Base de données réinitialisée$(RESET)"

# ============================================================================
# ℹ️ Aide
# ============================================================================

.PHONY: help
help: ## Afficher cette aide
	@echo ""
	@echo "$(CYAN)╔══════════════════════════════════════════════════════╗$(RESET)"
	@echo "$(CYAN)║           📂  Scanarr — Commandes Make              ║$(RESET)"
	@echo "$(CYAN)╚══════════════════════════════════════════════════════╝$(RESET)"
	@echo ""
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(GREEN)%-22s$(RESET) %s\n", $$1, $$2}'
	@echo ""
