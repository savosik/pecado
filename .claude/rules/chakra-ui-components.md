---
description: Enforce using local Chakra UI v3 component adapters, usage of CLI and MCP
---

# Chakra UI Component Usage Rule

When using Chakra UI v3 components in this project, **ALWAYS** import them from the local adapters directory `@/components/ui/*` instead of directly from `@chakra-ui/react`, unless it is a primitive not available in the adapters.

## 1. Installation & Usage 🛠️

If a component (e.g., `Accordion`) is missing in `@/components/ui/`, **DO NOT** create it manually or import it from the library.
Install it using the CLI command:

```bash
npx @chakra-ui/cli snippet add accordion
```

This will create the necessary file in `resources/js/components/ui/accordion.jsx` (or `.tsx`).

## 2. Imports 📦

### Bad Practice ❌
```javascript
import { Button, Checkbox, Dialog, Select } from "@chakra-ui/react";
```
Reason: In v3, these exports are often namespaces or primitives that require complex assembly. Direct usage breaks the "snippet" architecture and causes "Element type is invalid" errors.

### Good Practice ✅
```javascript
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import {
  DialogRoot,
  DialogContent,
  // ...
} from "@/components/ui/dialog"; // or import { Dialog } from ... depending on export style
import { Select } from "@/components/ui/select";
```

### Exception
Layout primitives like `Box`, `Flex`, `HStack`, `VStack`, `Stack`, `Grid`, `Text`, `Heading` can still be imported from `@chakra-ui/react`.

```javascript
import { Box, HStack, Text } from "@chakra-ui/react";
```

## 3. Use Chakra UI MCP 🤖

The project provides specific MCP tools for Chakra UI navigation and assistance. As an AI Agent, you **MUST** use these tools to avoid hallucinating V2 APIs or incorrect import paths.

**Available Tools:**
*   **`mcp_chakra-ui_installation`**: USE THIS to get the correct installation commands for snippets.
*   **`mcp_chakra-ui_get_component_examples`**: USE THIS to see how to properly compose complex components (like Dialog or Menu) using the v3 primitives.
*   **`mcp_chakra-ui_list_components`**: USE THIS to check if a component exists in the library.

**Guideline:**
Before implementing a UI component you are unfamiliar with in v3, call `mcp_chakra-ui_get_component_examples` to see the correct pattern.
