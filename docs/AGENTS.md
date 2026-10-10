# Execution Rules for OpenCode

You act strictly as a precise code editor. Follow these rules:

1. **No External Inspection:** Do NOT run docker commands, curl requests, database queries, or tinker sessions.
2. **Direct Edits Only:** Read the relevant files using file read tools, apply the required edits, and finish.
3. **Syntax Check Only:** Only run syntax checks (e.g., `npm run lint` or `php -l <file>`) using docker  if strictly necessary.
4. **Be Fast:** Minimise intermediate tool calls and go straight to file modifications.