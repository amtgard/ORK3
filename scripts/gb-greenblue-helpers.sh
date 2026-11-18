#!/usr/bin/env bash
# Sourced from {repo}/.greenblue (optional). Resolves sibling host-env-vault for local dev.
set -euo pipefail

_ork3_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [[ -z "${GB_HEV_VAULT_ROOT:-}" ]]; then
    for candidate in \
        "${_ork3_root}/../host-env-vault/Untitled" \
        "${_ork3_root}/../host-env-vault"; do
        if [[ -f "${candidate}/dev-shared/host-config.toml" ]]; then
            GB_HEV_VAULT_ROOT="$(cd "$candidate" && pwd)"
            break
        fi
    done
fi

if [[ -z "${GB_HEV_CONFIG:-}" && -n "${GB_HEV_VAULT_ROOT:-}" ]]; then
    GB_HEV_CONFIG="${GB_HEV_VAULT_ROOT}/dev-shared/host-config.toml"
fi

if [[ -z "${GB_HEV_REPO:-}" && -n "${GB_HEV_VAULT_ROOT:-}" && -f "${GB_HEV_VAULT_ROOT}/Cargo.toml" ]]; then
    GB_HEV_REPO="$GB_HEV_VAULT_ROOT"
fi
