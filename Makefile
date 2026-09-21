SHELL := /bin/bash
.PHONY: help dev up build logs migrate seed migrate-seed down stop restart artisan frontend-watch links install

help:
	@echo "Make targets:"
	@echo "  dev            Start all services in foreground (hot reload)"
	@echo "  up             Start services in background (detached)"
	@echo "  build          Build service images"
	@echo "  logs           Follow service logs"
	@echo "  migrate        Run Laravel migrations inside backend container"
	@echo "  seed           Run Laravel db:seed inside backend container"
	@echo "  migrate-seed   Run migrations then seed"
	@echo "  install        Install backend vendor dependencies (composer install)"
	@echo "  frontend-watch Run frontend dev server (inside container)"
	@echo "  down|stop      Stop and remove containers"
	@echo "  restart        Restart services"
	@echo "  artisan CMD=... Run arbitrary artisan command in backend"
	@echo "  links          Print common service URLs and connection info"

dev: install
	@echo "Starting full stack (foreground) with hot reload..."
	@$(MAKE) links
	@echo "Bringing up frontend (Vite) and backend-dev (artisan serve) with hot reload"
	docker compose up --build frontend backend-dev
	@echo ""
	@echo "✅ Dev stack is running! Useful links:"
	@echo "  Frontend (Vite):      http://localhost:5173"
	@echo "  Backend (HTTP):       http://localhost:8000"
	@echo "  Reverb (WebSocket):   http://localhost:8080"
	@echo "  MySQL:                127.0.0.1:3306 (rbqbot / rbqbot / rbqbot)"
	@echo "  phpMyAdmin:           http://localhost:8081 (root / root)"
	@echo "  Redis:                127.0.0.1:6379"

up: install
	@echo "Starting stack in background..."
	docker compose up -d --build
	@$(MAKE) links

build:
	@echo "Building images..."
	docker compose build
	@$(MAKE) links

logs:
	@echo "Following logs (ctrl-c to exit)..."
	docker compose logs -f
	@$(MAKE) links

install:
	@echo "Installing backend dependencies (composer install)..."
	docker compose run --rm backend composer install --no-interaction --prefer-dist
	@$(MAKE) links

migrate: install
	@echo "Running migrations inside backend container..."
	docker compose run --rm backend php artisan migrate --force
	@$(MAKE) links

seed: install
	@echo "Running db:seed inside backend container..."
	docker compose run --rm backend php artisan db:seed --force
	@$(MAKE) links

migrate-seed: install
	@echo "Running migrate then seed inside backend container..."
	docker compose run --rm backend sh -c "php artisan migrate --force && php artisan db:seed --force"
	@$(MAKE) links

frontend-watch:
	@echo "Starting frontend dev server inside container (attached)..."
	@$(MAKE) links
	docker compose run --rm frontend sh -c "npm run dev -- --host 0.0.0.0 --port 5173"

down stop:
	@echo "Stopping and removing containers..."
	docker compose down

restart:
	@echo "Restarting services..."
	docker compose restart

artisan: install
	@if [ -z "$(cmd)" ]; then \
		echo "Usage: make artisan cmd=\"route:list\""; exit 1; \
	fi
	@docker compose run --rm backend php artisan $(cmd)
	@$(MAKE) links

links:
	@echo "Service URLs & connection info:"
	@echo "  Frontend (Vite dev):     http://localhost:5173"
	@echo "  Backend (HTTP serve):    http://localhost:8000"
	@echo "  Reverb (WebSocket):      http://localhost:8080"
	@echo "  phpMyAdmin:              http://localhost:8081 (root / root)"
	@echo "  MySQL: 127.0.0.1:3306  (DB: rbqbot / user: rbqbot / pass: rbqbot)"
	@echo "  Redis: 127.0.0.1:6379"
	@echo "  To run artisan HTTP server: make artisan cmd=\"serve --host=0.0.0.0 --port=8000\""
