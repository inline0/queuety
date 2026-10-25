import { join } from "node:path"
import * as Console from "effect/Console"
import * as Effect from "effect/Effect"
import * as FileSystem from "effect/FileSystem"
import { fail } from "./cli.ts"
import * as Command from "./command.ts"

export const stripWpEnv = (text: string) =>
  text
    .split("\n")
    .filter(
      (line) =>
        !line.startsWith("ℹ Starting ") &&
        !line.startsWith("✔ Ran ") &&
        line.trim() !== ""
    )
    .join("\n")
    .trim()
export const shellQuote = (value: string) =>
  `'${value.replaceAll("'", `'\\''`)}'`
export const wordpress = (root: string) => {
  const raw = (args: ReadonlyArray<string>, check = true) =>
    Command.run({
      command: "pnpm",
      args: ["exec", "wp-env", ...args],
      cwd: root,
      check,
    })
  const wp = (...args: ReadonlyArray<string>) =>
    raw(["run", "cli", "--", "wp", ...args]).pipe(
      Effect.map((result) => stripWpEnv(result.stdout))
    )
  const attempt = (...args: ReadonlyArray<string>) =>
    raw(["run", "cli", "--", "wp", ...args], false).pipe(
      Effect.map((result) => ({ ...result, stdout: stripWpEnv(result.stdout) }))
    )
  const shell = (code: string) =>
    raw(["run", "cli", "--", "bash", "-lc", code]).pipe(
      Effect.map((result) => stripWpEnv(result.stdout))
    )
  const selected = (id: string, ...args: ReadonlyArray<string>) =>
    shell(
      `RUDEL_ENVIRONMENT=${shellQuote(id)} wp ${args.map(shellQuote).join(" ")}`
    )
  return { raw, wp, attempt, shell, selected }
}
export type WordPress = ReturnType<typeof wordpress>

export const fetchPage = (url: string, options: RequestInit = {}) =>
  Effect.tryPromise({
    try: async (signal) => {
      const response = await fetch(url, { ...options, signal })
      return {
        status: response.status,
        body: await response.text(),
        headers: response.headers,
      }
    },
    catch: (cause) => new Error(`HTTP ${url}: ${String(cause)}`),
  }).pipe(Effect.timeout("10 seconds"))

export const withWordPress = <A, E, R>(
  root: string,
  mode: "stop" | "destroy",
  scenario: (wp: WordPress) => Effect.Effect<A, E, R>,
  json = false
) =>
  Effect.scoped(
    Effect.gen(function* () {
      const dryRun = yield* Command.DryRun
      if (dryRun) {
        yield* Console.log(
          json
            ? JSON.stringify({
                dryRun: true,
                operations: ["provision", "scenarios", mode],
              })
            : `dry-run: provision WordPress, run scenarios, ${mode} WordPress`
        )
        return
      }
      const docker = yield* Command.run({
        command: "docker",
        args: ["info"],
        check: false,
        mutates: false,
      }).pipe(
        Effect.catchTag("CommandError", () => Effect.succeed({ exitCode: 1 }))
      )
      if (docker.exitCode !== 0) {
        yield* Console.log(
          json
            ? JSON.stringify({ skipped: "Docker is not available" })
            : "SKIP: Docker is not available"
        )
        return
      }
      const fs = yield* FileSystem.FileSystem
      if (!(yield* fs.exists(join(root, "node_modules/@wordpress/env"))))
        return yield* fail(
          "Run pnpm install --frozen-lockfile before E2E tests"
        )
      const wp = wordpress(root)
      yield* Effect.acquireRelease(Effect.succeed(wp), (site) =>
        site
          .raw(mode === "destroy" ? ["destroy", "--force"] : ["stop"], false)
          .pipe(Effect.ignore)
      )
      return yield* scenario(wp)
    })
  )

export const waitForWordPress = (
  port: number,
  tries = 30
): Effect.Effect<void, Error> =>
  Effect.gen(function* () {
    for (let attempt = 0; attempt < tries; attempt++) {
      const ready = yield* fetchPage(`http://localhost:${port}/`, {
        redirect: "manual",
      }).pipe(
        Effect.map((response) => [200, 301, 302].includes(response.status)),
        Effect.orElseSucceed(() => false)
      )
      if (ready) return
      yield* Effect.sleep("1 second")
    }
    return yield* Effect.fail(
      new Error(`WordPress did not become ready on http://localhost:${port}/`)
    )
  })

export class Assertions {
  readonly json: boolean
  constructor(json = false) {
    this.json = json
  }
  passed = 0
  failed = 0
  check(condition: unknown, label: string, detail = "") {
    if (condition) this.passed++
    else this.failed++
    return this.json
      ? Console.error(
          JSON.stringify({
            passed: Boolean(condition),
            label,
            ...(!condition && detail ? { detail } : {}),
          })
        )
      : Console.log(
          `  ${condition ? "PASS" : "FAIL"}: ${label}${!condition && detail ? `\n${detail}` : ""}`
        )
  }
  contains(actual: string, expected: string, label: string) {
    return this.check(actual.includes(expected), label, actual)
  }
  excludes(actual: string, expected: string, label: string) {
    return this.check(!actual.includes(expected), label, actual)
  }
  equals(expected: string, actual: string, label: string) {
    return this.check(
      expected === actual,
      label,
      `Expected ${expected}, got ${actual}`
    )
  }
  finish(json = false) {
    return Console.log(
      json
        ? JSON.stringify({ passed: this.passed, failed: this.failed })
        : `Results: ${this.passed} passed, ${this.failed} failed`
    ).pipe(
      Effect.andThen(
        this.failed === 0
          ? Effect.void
          : fail(`${this.failed} E2E assertions failed`)
      )
    )
  }
}
