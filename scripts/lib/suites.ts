import { resolve } from "node:path"
import * as Console from "effect/Console"
import * as Effect from "effect/Effect"
import * as FileSystem from "effect/FileSystem"
import { fail } from "./cli.ts"
import * as Command from "./command.ts"

export interface Check {
  readonly label: string
  readonly command: string
  readonly args: ReadonlyArray<string>
}
export const runChecks = (
  checks: ReadonlyArray<Check>,
  cwd: string,
  json = false
) =>
  Effect.gen(function* () {
    const results: Array<{ label: string; exitCode: number }> = []
    for (const check of checks) {
      if (!json) yield* Console.log(`\n${check.label}`)
      const result = yield* Command.run({
        ...check,
        cwd,
        inherit: !json,
        check: false,
      }).pipe(
        Effect.catchTag("CommandError", (error) =>
          Effect.succeed({
            exitCode: error.exitCode,
            stdout: "",
            stderr: error.message,
          })
        )
      )
      results.push({ label: check.label, exitCode: result.exitCode })
      if (!json)
        yield* Console.log(
          `${result.exitCode === 0 ? "PASS" : "FAIL"}: ${check.label}${result.stderr ? `\n${result.stderr}` : ""}`
        )
    }
    if (json) yield* Console.log(JSON.stringify(results))
    if (results.some((result) => result.exitCode !== 0))
      return yield* fail("Some checks failed")
  })

export const e2eChecks = (root: string, coreOnly = false) =>
  Effect.gen(function* () {
    const fs = yield* FileSystem.FileSystem
    const directory = resolve(root, "tests/e2e")
    const files = yield* fs.readDirectory(directory)
    return files
      .filter(
        (file) =>
          /^test-.*\.(php|ts)$/.test(file) &&
          (!coreOnly || file !== "test-wp-env.ts")
      )
      .sort()
      .map((file): Check => ({
        label: file,
        command: file.endsWith(".php") ? "php" : process.execPath,
        args: file.endsWith(".php")
          ? [
              "-d",
              "zend.assertions=1",
              "-d",
              "assert.exception=1",
              resolve(directory, file),
            ]
          : [resolve(directory, file)],
      }))
  })
