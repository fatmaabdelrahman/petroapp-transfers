.PHONY: run stop test test-concurrency fresh shell logs build

build:
	docker compose build

run:
	docker compose up --build

stop:
	docker compose down

# Run unit + feature tests against petroapp_test (never touches the app DB).
# -e DB_DATABASE overrides the docker-compose system env var at the OS level.
# RUN_MIGRATIONS=false skips the entrypoint migrate so RefreshDatabase owns it.
test:
	docker compose run --rm \
		-e DB_DATABASE=petroapp_test \
		-e RUN_MIGRATIONS=false \
		app php artisan test

# Run the concurrency proof test against the live HTTP app.
# Requires the stack to be running: `make run` in another terminal.
test-concurrency:
	docker compose exec -e CONCURRENCY_BASE_URL=http://localhost:8000 app php artisan test --filter=ConcurrencyTest

fresh:
	docker compose run --rm app php artisan migrate:fresh

shell:
	docker compose run --rm app bash

logs:
	docker compose logs -f app
