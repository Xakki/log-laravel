SHELL = /bin/bash
### https://makefiletutorial.com/

include .env
export

HOST_NAME := $(shell hostname)
HOST_IP  := $(shell hostname -I 2>/dev/null | awk '{print $$1}' || echo unknown)
TTY ?= $(shell if [ -t 0 ]; then echo "-it"; else echo "-i"; fi)

DOCKER_CPUS ?= 2
DOCKER_MEMORY ?= 1g
UID := $(shell id -u)
GID := $(shell id -g)

# --rm: no corpse container per run. --user: container must not stamp vendor/ and
# composer.lock as root on the host mount. COMPOSER_HOME: a non-root uid has no
# writable HOME inside the image.
docker := docker run --rm ${TTY} --cpus=${DOCKER_CPUS} --memory=${DOCKER_MEMORY} \
	--user $(UID):$(GID) -e COMPOSER_HOME=/tmp/composer -e HOME=/tmp \
	-v $(PWD):/app ${DOCKER_USER}/${TAG}
composer := $(docker) composer

run: docker-build composer-u test

docker-login:
	docker login ${HOST} -u ${DOCKER_USER} -p ${DOCKER_PASS}

docker-push:
	docker push ${DOCKER_USER}/${TAG}

docker-build:
	docker pull ${PHP_IMAGE}
	docker build -t ${DOCKER_USER}/${TAG} --build-arg PHP_IMAGE=${PHP_IMAGE} .

fix-perms:
	docker run --rm -v $(PWD):/app -w /app ${PHP_IMAGE} chown -R $(UID):$(GID) .

bash:
	$(docker) bash

composer-i:
	$(composer) i

composer-u:
	$(composer) u $(name)

cs-fix:
	$(composer) cs-fix

cs-check:
	$(composer) cs-check

phpstan:
	$(composer) phpstan

phpunit:
	$(composer) phpunit

test:
	$(composer) cs-check
	$(composer) phpstan
	$(composer) phpunit
