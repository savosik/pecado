#!/bin/bash
# Portable Context7 MCP wrapper — reads API key from project .env
SCRIPT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
CONTEXT7_API_KEY=$(grep '^CONTEXT7_API_KEY=' "$SCRIPT_DIR/.env" 2>/dev/null | cut -d= -f2-)
exec docker exec -i pecado-node context7-mcp ${CONTEXT7_API_KEY:+--api-key "$CONTEXT7_API_KEY"}
