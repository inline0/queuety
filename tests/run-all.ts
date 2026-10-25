import { resolve } from "node:path"
import { main, script } from "../scripts/lib/cli.ts"
import { runChecks } from "../scripts/lib/suites.ts"
const root = resolve(import.meta.dirname, "..")
export const command = script("run-all", {
  description:
    "Run the complete local validation suite, collecting all failures",
  flags: {},
  run: ({ json }) =>
    runChecks(
      [
        {
          label: "Coding Standards",
          command: "composer",
          args: ["cs"],
        },
        {
          label: "Static Analysis",
          command: "composer",
          args: ["analyse"],
        },
        {
          label: "Unit Tests",
          command: "composer",
          args: ["test:unit"],
        },
        {
          label: "Integration Tests",
          command: "composer",
          args: ["test:integration"],
        },
        {
          label: "E2E Tests",
          command: "node",
          args: ["tests/e2e/run-all.ts"],
        },
        {
          label: "Docs",
          command: "node",
          args: [
            "scripts/check-docs-content.ts",
            "--root",
            "docs",
            "--expected-docs",
            "55",
            "--expected-nav",
            "4",
          ],
        },
      ],
      root,
      json
    ),
})
if (import.meta.main) main(command)
