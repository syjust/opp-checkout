#!/usr/bin/env bash
set -euo pipefail

if [ $# -ne 1 ]; then
    echo "Usage: $0 <tag>"
    echo "Example: $0 v1.0.0"
    exit 1
fi

TAG="$1"
DEPLOY_PATH="$(cd "$(dirname "$0")/../.." && pwd)"
RELEASE_DIR="${DEPLOY_PATH}/releases/${TAG}"
CURRENT_TARGET="$(readlink "${DEPLOY_PATH}/htdocs" 2>/dev/null || true)"

if [ ! -d "$RELEASE_DIR" ]; then
    echo "Error: release ${TAG} not found at ${RELEASE_DIR}"
    echo "Available releases:"
    ls -1 "${DEPLOY_PATH}/releases/"
    exit 1
fi

if [ "$CURRENT_TARGET" = "releases/${TAG}" ]; then
    echo "Already on ${TAG}, nothing to do."
    exit 0
fi

CURRENT_DIR="${DEPLOY_PATH}/${CURRENT_TARGET}"

echo "Rolling back to ${TAG}..."
echo "Current: ${CURRENT_TARGET:-none}"

# Find migrations present in current release but absent from target release
EXTRA_MIGRATIONS=()
if [ -d "${CURRENT_DIR}/migrations" ]; then
    for f in $(ls -1 "${CURRENT_DIR}/migrations/"*.php 2>/dev/null | xargs -r -n1 basename | sort -r); do
        if [ ! -f "${RELEASE_DIR}/migrations/${f}" ]; then
            EXTRA_MIGRATIONS+=("$f")
        fi
    done
fi

if [ ${#EXTRA_MIGRATIONS[@]} -gt 0 ]; then
    echo ""
    echo "⚠  ${#EXTRA_MIGRATIONS[@]} migration(s) to roll back (reverse order):"
    for m in "${EXTRA_MIGRATIONS[@]}"; do
        echo "   - ${m}"
    done
    echo ""
    read -p "Roll back these migrations and switch? [y/N] " -r
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Aborted."
        exit 1
    fi

    cd "${CURRENT_DIR}"
    for m in "${EXTRA_MIGRATIONS[@]}"; do
        VERSION=$(echo "$m" | sed 's/^Version//' | sed 's/\.php$//')
        echo "Rolling back migration ${VERSION}..."
        php bin/console doctrine:migrations:execute "DoctrineMigrations\\${m%.php}" --down --no-interaction
    done
fi

cd "$DEPLOY_PATH"
ln -sfn "releases/${TAG}" htdocs

echo "Switched to ${TAG}"
echo "Active: $(readlink htdocs)"
