import { resolve } from "node:path"
import * as Effect from "effect/Effect"
import { main, script } from "../../scripts/lib/cli.ts"
import { e2eChecks, runChecks } from "../../scripts/lib/suites.ts"
const root = resolve(import.meta.dirname, "../..")
export const command = script("run-all", {
  description: "Run Queuety E2E suites and report every failure",
  flags: {},
  run: ({ json }) =>
    Effect.gen(function* () {
      const checks = yield* e2eChecks(root, false)
      yield* runChecks(checks, root, json)
    }),
})
if (import.meta.main) main(command)
