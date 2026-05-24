.PHONY: run stop test test-concurrency fresh shell logs build

# Shorthand for the test compose stack (base + override)
TEST_COMPOSE = docker compose -f docker-compose.yml -f docker-compose.test.yml

build:
	docker compose build

run:
	docker compose up --build

stop:
	docker compose down

# Run unit + feature tests.
# Uses docker-compose.test.yml to swap DB_DATABASE → petroapp_test
# so tests never touch the production DB.
test:
	$(TEST_COMPOSE) run --rm app php artisan test

# Run the concurrency proof test (requires `make run` in another terminal).
test-concurrency:
	docker compose exec -e CONCURRENCY_BASE_URL=http://localhost:8000 app php artisan test --filter=ConcurrencyTest

# Drop and recreate the app DB schema.
fresh:
	docker compose run --rm app php artisan migrate:fresh

shell:
	docker compose run --rm app bash

logs:
	docker compose logs -f app
