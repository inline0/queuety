import { resolve } from "node:path"
import * as Effect from "effect/Effect"
import { main, script } from "../../scripts/lib/cli.ts"
import { e2eChecks, runChecks } from "../../scripts/lib/suites.ts"
const root = resolve(import.meta.dirname, "../..")
export const command = script("run-core", {
  description: "Run Queuety core E2E suites and report every failure",
  flags: {},
  run: ({ json }) =>
    Effect.gen(function* () {
      const checks = yield* e2eChecks(root, true)
      yield* runChecks(checks, root, json)
    }),
})
if (import.meta.main) main(command)
