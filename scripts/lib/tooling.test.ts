import assert from "node:assert/strict"
import { spawnSync } from "node:child_process"
import { mkdtemp, readFile, rm } from "node:fs/promises"
import { tmpdir } from "node:os"
import { join, resolve } from "node:path"
import test from "node:test"
import * as NodeServices from "@effect/platform-node/NodeServices"
import * as Effect from "effect/Effect"
import { validateDocumentation } from "../check-docs-content.ts"
import * as Command from "./command.ts"
import { runtimeLayer } from "./runtime.ts"
import { runChecks } from "./suites.ts"
import { shellQuote, stripWpEnv, withWordPress } from "./wp-env.ts"

const root = resolve(import.meta.dirname, "../..")
const document = (route = "/guide", order = 0, body = "Text.") =>
  `---\ntitle: Guide\ndescription: Guide\npath: ${route}\norder: ${order}\nmeta_title: Guide\nmeta_description: Guide\n---\n${body}\n`
const snapshot = (files: Record<string, string>) =>
  new Map(
    Object.entries(files).map(([file, text]) => [
      join(root, "docs", file),
      text,
    ])
  )

test("documentation checks navigation, routes, frontmatter and portable body", () => {
  const good = snapshot({
    "meta.json": '{"pages":["guide"]}',
    "guide.md": document(),
  })
  assert.deepEqual(
    validateDocumentation(join(root, "docs"), good, 1, 1).problems,
    []
  )
  const bad = snapshot({
    "meta.json": '{"pages":["guide","other","missing"]}',
    "guide.md": document(
      "/same",
      0,
      "<Component />\n[Missing](missing-file.md)"
    ),
    "other.md": document("/same", 0),
    "orphan.mdx": "export default 1;",
  })
  const problems = validateDocumentation(join(root, "docs"), bad).problems.join(
    "\n"
  )
  for (const expected of [
    "canonical content",
    "duplicate path",
    "duplicate order",
    "executable JSX",
    "unresolved navigation",
    "broken relative link",
  ])
    assert.ok(problems.includes(expected), expected)
})

test("CLI preserves docs counts, usage failures and help without requiring the content root", () => {
  const run = (...args: Array<string>) =>
    spawnSync(
      process.execPath,
      [join(root, "scripts/check-docs-content.ts"), ...args],
      { cwd: root, encoding: "utf8" }
    )
  assert.equal(run("--help", "--root", "missing-directory").status, 0)
  const preview = spawnSync(
    process.execPath,
    [join(root, "tests/e2e/test-wp-env.ts"), "--dry-run"],
    { encoding: "utf8", timeout: 3000 }
  )
  assert.equal(preview.status, 0, preview.stderr)
  assert.match(preview.stdout, /dry-run: provision WordPress/)
  const jsonPreview = spawnSync(
    process.execPath,
    [join(root, "tests/e2e/test-wp-env.ts"), "--dry-run", "--json"],
    { encoding: "utf8", timeout: 3000 }
  )
  assert.equal(jsonPreview.status, 0, jsonPreview.stderr)
  assert.equal(JSON.parse(jsonPreview.stdout).dryRun, true)
  assert.equal(run("--unknown").status, 2)
  assert.equal(run("--expected-docs", "invalid").status, 2)
  assert.equal(run("--root", "missing-directory").status, 1)
  const result = run(
    "--root",
    "docs",
    "--json",
    "--dry-run",
    "--log-level",
    "error"
  )
  assert.equal(result.status, 0, result.stderr)
  assert.equal(JSON.parse(result.stdout).problems, 0)
})

test("command gateway captures both streams and exposes checked and unchecked status", async () => {
  const result = await Effect.runPromise(
    Command.run({
      command: process.execPath,
      args: [
        "-e",
        'process.stdout.write("out");process.stderr.write("err");process.exitCode=7',
      ],
      check: false,
    }).pipe(Effect.provide(runtimeLayer()))
  )
  assert.deepEqual(result, { stdout: "out", stderr: "err", exitCode: 7 })
  await assert.rejects(
    Effect.runPromise(
      Command.run({
        command: process.execPath,
        args: ["-e", "process.exit(7)"],
      }).pipe(Effect.provide(runtimeLayer()))
    ),
    /exited with 7/
  )
})

test("interrupting a scoped command terminates its child", async () => {
  const temporary = await mkdtemp(join(tmpdir(), "tooling-command-"))
  try {
    const pidFile = join(temporary, "pid")
    const code =
      'require("node:fs").writeFileSync(process.argv[1],String(process.pid));setInterval(()=>{},1000)'
    await assert.rejects(
      Effect.runPromise(
        Command.run({
          command: process.execPath,
          args: ["-e", code, pidFile],
        }).pipe(Effect.timeout("500 millis"), Effect.provide(runtimeLayer()))
      ),
      /Timeout/
    )
    const pid = Number(await readFile(pidFile, "utf8"))
    assert.throws(() => process.kill(pid, 0), { code: "ESRCH" })
  } finally {
    await rm(temporary, { recursive: true, force: true })
  }
})

test("suite orchestration continues after failure and reports a failing aggregate", async () => {
  const calls: Array<string> = []
  const program = runChecks(
    [
      { label: "first", command: "first", args: [] },
      { label: "second", command: "second", args: [] },
    ],
    root,
    true
  ).pipe(
    Effect.provideService(Command.Command, {
      run: (call) => {
        calls.push(call.command)
        return Effect.succeed({
          exitCode: call.command === "first" ? 9 : 0,
          stdout: "",
          stderr: "",
        })
      },
    })
  )
  await assert.rejects(Effect.runPromise(program), /Some checks failed/)
  assert.deepEqual(calls, ["first", "second"])
})

test("WordPress scope cleans up after a scenario fails and dry-run provisions nothing", async () => {
  const calls: Array<Command.CommandCall> = []
  const fake = {
    run: (call: Command.CommandCall) => {
      calls.push(call)
      return Effect.succeed({ exitCode: 0, stdout: "", stderr: "" })
    },
  }
  const program = withWordPress(root, "stop", () =>
    Effect.fail(new Error("scenario failed"))
  ).pipe(
    Effect.provide(NodeServices.layer),
    Effect.provideService(Command.Command, fake)
  )
  await assert.rejects(Effect.runPromise(program), /scenario failed/)
  assert.equal(calls[0].command, "docker")
  assert.deepEqual(calls.at(-1)?.args, ["exec", "wp-env", "stop"])
  calls.length = 0
  const destroyProgram = withWordPress(root, "destroy", () =>
    Effect.fail(new Error("scenario failed"))
  ).pipe(
    Effect.provide(NodeServices.layer),
    Effect.provideService(Command.Command, fake)
  )
  await assert.rejects(Effect.runPromise(destroyProgram), /scenario failed/)
  assert.deepEqual(calls.at(-1)?.args, ["exec", "wp-env", "destroy"])
  assert.equal(calls.at(-1)?.stdin, "y\n")
  calls.length = 0
  await Effect.runPromise(
    program.pipe(Effect.provideService(Command.DryRun, true))
  )
  assert.deepEqual(calls, [])
})

test("WordPress output filtering and shell arguments preserve data", () => {
  assert.equal(
    stripWpEnv("ℹ Starting command\n\nvalue\n✔ Ran command\n"),
    "value"
  )
  const text = "space ' quote; $(printf danger)"
  const result = spawnSync("sh", ["-c", `printf '%s' ${shellQuote(text)}`], {
    encoding: "utf8",
  })
  assert.equal(result.stdout, text)
})
