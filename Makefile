.PHONY: build install update test test-filter phpstan cs cs-fix shell down clean matrix

PHP_VERSION ?= 8.3
DC = PHP_VERSION=$(PHP_VERSION) docker compose
RUN = $(DC) run --rm app

build:
	$(DC) build

install:
	$(RUN) composer install --prefer-dist

update:
	$(RUN) composer update --prefer-dist --with-all-dependencies

test:
	$(RUN) vendor/bin/phpunit --colors=never

test-filter:
	$(RUN) vendor/bin/phpunit --colors=never --filter "$(F)"

phpstan:
	$(RUN) vendor/bin/phpstan analyse --no-progress

cs:
	$(RUN) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix:
	$(RUN) vendor/bin/php-cs-fixer fix

shell:
	$(RUN) sh

down:
	$(DC) down -v

clean:
	$(DC) down -v --rmi local
	rm -rf vendor .phpunit.cache

matrix:
	@for v in 8.2 8.3 8.4; do \
		echo "===== PHP $$v ====="; \
		$(MAKE) PHP_VERSION=$$v build install test || exit 1; \
	done
