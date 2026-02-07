#!/bin/sh
# Install global MCP packages if not already installed
if ! command -v context7-mcp >/dev/null 2>&1; then
  npm install -g @upstash/context7-mcp @chakra-ui/react-mcp >/dev/null 2>&1
fi

# Run the main command
exec "$@"
