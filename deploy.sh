#!/usr/bin/env bash
set -euo pipefail

main() {
    cd "$(dirname "$0")"

    git pull
    docker compose -f docker-compose.prod.yml build
    docker compose -f docker-compose.prod.yml up -d --wait mysql redis
    docker compose -f docker-compose.prod.yml run --rm app php artisan migrate --force
    docker compose -f docker-compose.prod.yml up -d
}

main "$@"
