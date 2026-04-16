#!/usr/bin/env bash

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
AGENT_DIR="$REPO_ROOT/.agent"
CODEX_HOME="${CODEX_HOME:-$HOME/.codex}"
MEMORIES_DIR="$CODEX_HOME/memories"
SKILLS_DIR="$CODEX_HOME/skills"
SKILL_DIR="$SKILLS_DIR/pecado-project"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

if [[ ! -d "$AGENT_DIR/rules" || ! -d "$AGENT_DIR/workflows" ]]; then
  echo "Expected .agent/rules and .agent/workflows under $REPO_ROOT" >&2
  exit 1
fi

mkdir -p "$MEMORIES_DIR" "$SKILL_DIR/references/rules" "$SKILL_DIR/references/workflows"

MEMORY_FILE="$TMP_DIR/pecado-project-rules.md"
cat > "$MEMORY_FILE" <<'EOF'
# Pecado Project Memory

Project: `/home/savosik/projects/pecado`

Always apply these defaults when working in this repository:

- The project runs in Docker. Prefer running project commands through the appropriate `docker exec pecado-* ...` containers rather than directly on the host.
- The user-facing interface must be in Russian only, including labels, validation errors, pagination, and other UI text.
- On the dev server there is already data. When changing entities or schema, create a new migration and do not edit old migrations.
- Any task related to 1C/ERP integration over RabbitMQ and reflected in AsyncAPI must include integration test coverage.

ERP spec-first rule:

- Start ERP exchange changes from specs and docs, then only after that update handlers, jobs, listeners, and other code.
- Update JSON Schema in `app/Services/Erp/Schemas/*.json`.
- Update AsyncAPI spec in `docs/asyncapi/pecado-erp-integration.yaml`.
- Update ERP business docs in `docs-erp/content/rules/*.md` and `docs-erp/content/tests/*.md`.
- Add a changelog entry in `docs-erp/content/changelog.md`.
- Build docs with `docker exec pecado-node npm run asyncapi:build` and `mkdocs build`.

Frontend Chakra rule:

- For Chakra UI v3, import composed components from `@/components/ui/*`, not directly from `@chakra-ui/react`, except layout primitives.
- If a Chakra component adapter is missing, add it with the Chakra CLI snippet instead of hand-rolling it.

Workflow routing:

- For deploy/release work, follow `.agent/workflows/deploy.md`.
- For ERP integration tasks, follow `.agent/workflows/erp-task.md`.
- For preparing integration testing, follow `.agent/workflows/prepare-integration-test.md`.
- For sprint execution, follow `.agent/workflows/sprint.md`.
- For admin panel implementation, follow `.agent/workflows/admin-panel-todo.md`.

Source of truth:

- Primary repo-local instructions live under `.agent/rules/` and `.agent/workflows/`.
- If there is any conflict, prefer explicit user instructions first, then system/developer instructions from the active session, then these project rules.
EOF

SKILL_FILE="$TMP_DIR/SKILL.md"
cat > "$SKILL_FILE" <<'EOF'
---
name: pecado-project
description: Use for any task in the Pecado repository at /home/savosik/projects/pecado. Applies project-specific always-on rules, Russian-only UI requirements, Docker-first command execution, migration policy, Chakra UI v3 adapter usage, and ERP spec-first workflows with linked repo-specific references.
---

# Pecado Project

Use this skill for work in `/home/savosik/projects/pecado`.

## Always-on rules

- Run project commands through the appropriate Docker containers. Read `references/rules/doker.md`.
- Keep the user-facing interface fully in Russian. Read `references/rules/ru.md`.
- Never edit old migrations when schema changes are needed; create a new migration. Read `references/rules/migration-rule.md`.
- For RabbitMQ/AsyncAPI based 1C integration work, require integration tests. Read `references/rules/integration-tests.md`.

## ERP tasks

For any change in the 1C <-> site exchange protocol, follow spec-first development:

1. Update JSON Schema.
2. Update AsyncAPI.
3. Update ERP docs and tests.
4. Update changelog.
5. Build generated docs.
6. Only then change runtime code.

Read `references/rules/erp-exchange-protocol.md` first.

If the task is an ERP integration implementation, also read `references/workflows/erp-task.md`.

If the task is about preparing manual integration testing on dev, read `references/workflows/prepare-integration-test.md`.

## Frontend tasks

For Chakra UI v3, prefer local adapters from `@/components/ui/*` instead of direct imports from `@chakra-ui/react`, except for layout primitives. If a snippet component is missing, add it through the Chakra CLI snippet flow. Read `references/rules/chakra-ui-components.md`.

## Other workflows

- For deploy or release work, read `references/workflows/deploy.md`.
- For admin panel implementation, read `references/workflows/admin-panel-todo.md`.
- For sprint execution, read `references/workflows/sprint.md`.

## Source of truth

The copied files in `references/` come from the repository-local `.agent/` directory. When the repo files change, rerun `scripts/sync_agent_to_codex.sh` to keep the skill in sync.
EOF

install -m 0644 "$MEMORY_FILE" "$MEMORIES_DIR/pecado-project-rules.md"
install -m 0644 "$SKILL_FILE" "$SKILL_DIR/SKILL.md"

find "$SKILL_DIR/references" -type f -name '*.md' -delete
install -m 0755 -d "$SKILL_DIR/references/rules" "$SKILL_DIR/references/workflows"
find "$AGENT_DIR/rules" -maxdepth 1 -type f -name '*.md' -exec install -m 0644 {} "$SKILL_DIR/references/rules/" \;
find "$AGENT_DIR/workflows" -maxdepth 1 -type f -name '*.md' -exec install -m 0644 {} "$SKILL_DIR/references/workflows/" \;

echo "Updated Codex memory: $MEMORIES_DIR/pecado-project-rules.md"
echo "Updated Codex skill:  $SKILL_DIR"
