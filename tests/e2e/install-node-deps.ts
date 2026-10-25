import { resolve } from "node:path"
import * as Argument from "effect/cli/Argument"
import * as Console from "effect/Console"
import * as Effect from "effect/Effect"
import { fail, main, script } from "../../scripts/lib/cli.ts"
import * as Command from "../../scripts/lib/command.ts"
export const command = script("install-node-deps", {
  description:
    "Retry a frozen pnpm dependency installation with bounded backoff",
  flags: { attempts: Argument.Int("attempts").pipe(Argument.withDefault(3)) },
  run: ({ attempts }) =>
    Effect.gen(function* () {
      if (attempts < 1)
        return yield* fail("attempts must be a positive integer", 2)
      for (let attempt = 1; attempt <= attempts; attempt++) {
        const result = yield* Command.run({
          command: "pnpm",
          args: ["install", "--frozen-lockfile", "--prefer-offline"],
          cwd: resolve(import.meta.dirname, "../.."),
          inherit: true,
          check: false,
        })
        if (result.exitCode === 0) return
        if (attempt < attempts) {
          yield* Console.error(
            `Node dependency install failed on attempt ${attempt}/${attempts}. Retrying in ${attempt * 5}s...`
          )
          yield* Effect.sleep(`${attempt * 5} seconds`)
        }
      }
      return yield* fail(
        `Node dependency install failed after ${attempts} attempts.`
      )
    }),
})
if (import.meta.main) main(command)
