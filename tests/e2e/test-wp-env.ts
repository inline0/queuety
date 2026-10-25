import { resolve } from "node:path"
import * as Console from "effect/Console"
import * as Effect from "effect/Effect"
import * as FileSystem from "effect/FileSystem"
import { main, script } from "../../scripts/lib/cli.ts"
import {
  Assertions,
  waitForWordPress,
  withWordPress,
} from "../../scripts/lib/wp-env.ts"
const root = resolve(import.meta.dirname, "../..")
export const command = script("test-wp-env", {
  description: "Exercise the complete WordPress queue and workflow lifecycle",
  flags: {},
  run: ({ json }) =>
    withWordPress(
      root,
      "stop",
      (site) =>
        Effect.gen(function* () {
          const announce = (text: string) =>
            json ? Console.error(text) : Console.log(text)
          const fs = yield* FileSystem.FileSystem
          const source = yield* fs.readFileString(resolve(root, "queuety.php"))
          const EXPECTED_VERSION =
            source.match(/define\( 'QUEUETY_VERSION', '([^']+)' \)/)?.[1] ?? ""
          const config = JSON.parse(
            yield* fs.readFileString(resolve(root, ".wp-env.json"))
          ) as { port?: number }
          const port = config.port ?? 8888
          const started = yield* site.raw(["start"], false)
          yield* announce(started.stdout + started.stderr)
          yield* waitForWordPress(port)
          if ((yield* site.attempt("core", "is-installed")).exitCode !== 0) {
            yield* site.wp(
              "core",
              "install",
              `--url=http://localhost:${port}`,
              "--title=QueuetyTest",
              "--admin_user=admin",
              "--admin_password=password",
              "--admin_email=test@example.test",
              "--skip-email"
            )
          }
          yield* site.wp("plugin", "activate", "queuety")
          const assertions = new Assertions(json)
          const evaluate = (code: string) =>
            site
              .attempt(
                "eval",
                `require_once WP_PLUGIN_DIR . '/queuety/tests/e2e/fixtures/wp-env-workflows.php'; ${code}`
              )
              .pipe(Effect.map((result) => result.stdout))
          const processOne = () =>
            evaluate(
              "$queue = new Queuety\\Queue(Queuety\\Queuety::connection()); $job = $queue->claim(); if (null === $job) { echo '0'; } else { Queuety\\Queuety::worker()->process_job($job); echo '1'; }"
            )
          const reset = () =>
            Effect.forEach(
              [
                "signals",
                "artifacts",
                "workflow_events",
                "jobs",
                "workflows",
                "logs",
              ],
              (table) =>
                site.attempt("db", "query", `DELETE FROM wp_queuety_${table};`),
              { discard: true }
            )
          const ACTIVE_PLUGINS = (yield* site.attempt(
            "plugin",
            "list",
            "--status=active",
            "--field=name"
          )).stdout
          yield* assertions.contains(
            ACTIVE_PLUGINS,
            "queuety",
            "Plugin is active"
          )
          const VERSION = (yield* site.attempt("eval", "echo QUEUETY_VERSION;"))
            .stdout
          yield* assertions.equals(
            EXPECTED_VERSION,
            VERSION,
            "QUEUETY_VERSION matches plugin constant"
          )
          const MYSQLI_AVAILABLE = (yield* site.attempt(
            "eval",
            "echo extension_loaded('mysqli') ? 'yes' : 'no';"
          )).stdout
          yield* assertions.equals(
            "yes",
            MYSQLI_AVAILABLE,
            "wp-env CLI has mysqli"
          )
          const CONNECTION_DRIVER = (yield* site.attempt(
            "eval",
            "echo Queuety\\Queuety::connection()->driver();"
          )).stdout
          yield* assertions.equals(
            "mysqli",
            CONNECTION_DRIVER,
            "Queuety uses mysqli in wp-env"
          )
          yield* announce("")
          yield* announce("--- Table Creation ---")
          const TABLES = (yield* site.attempt(
            "db",
            "query",
            "SHOW TABLES LIKE '%queuety%';",
            "--skip-column-names"
          )).stdout
          yield* assertions.contains(
            TABLES,
            "queuety_jobs",
            "queuety_jobs table exists"
          )
          yield* assertions.contains(
            TABLES,
            "queuety_workflows",
            "queuety_workflows table exists"
          )
          yield* assertions.contains(
            TABLES,
            "queuety_logs",
            "queuety_logs table exists"
          )
          yield* assertions.contains(
            TABLES,
            "queuety_artifacts",
            "queuety_artifacts table exists"
          )
          yield* announce("--- WP-CLI: Status ---")
          const STATUS = (yield* site.attempt("queuety", "status")).stdout
          yield* assertions.contains(
            STATUS,
            "Pending",
            "status command shows Pending column"
          )
          yield* assertions.contains(
            STATUS,
            "Buried",
            "status command shows Buried column"
          )
          yield* announce("")
          yield* announce("--- WP-CLI: Webhooks ---")
          const WEBHOOK_ADD_OUT = (yield* site.attempt(
            "queuety",
            "webhook",
            "add",
            "job.completed",
            "https://example.com/hook"
          )).stdout
          yield* assertions.contains(
            WEBHOOK_ADD_OUT,
            "registered",
            "webhook add command succeeds"
          )
          const WEBHOOK_LIST_OUT = (yield* site.attempt(
            "queuety",
            "webhook",
            "list"
          )).stdout
          yield* assertions.contains(
            WEBHOOK_LIST_OUT,
            "job.completed",
            "webhook list shows registered event"
          )
          yield* assertions.contains(
            WEBHOOK_LIST_OUT,
            "https://example.com/hook",
            "webhook list shows registered URL"
          )
          const WEBHOOK_ID = (yield* site.attempt(
            "db",
            "query",
            "SELECT id FROM wp_queuety_webhooks ORDER BY id DESC LIMIT 1;",
            "--skip-column-names"
          )).stdout.replace(/\s/g, "")
          const WEBHOOK_REMOVE_OUT = (yield* site.attempt(
            "queuety",
            "webhook",
            "remove",
            WEBHOOK_ID
          )).stdout
          yield* assertions.contains(
            WEBHOOK_REMOVE_OUT,
            "removed",
            "webhook remove command succeeds"
          )
          yield* announce("")
          yield* announce("--- WP-CLI: Dispatch and List ---")
          const DISPATCH_OUT = (yield* site.attempt(
            "queuety",
            "dispatch",
            "test_handler",
            '--payload={"key":"value"}'
          )).stdout
          yield* assertions.contains(
            DISPATCH_OUT,
            "Dispatched job",
            "dispatch command succeeds"
          )
          const LIST_OUT = (yield* site.attempt("queuety", "list")).stdout
          yield* assertions.contains(
            LIST_OUT,
            "test_handler",
            "list shows dispatched job"
          )
          yield* assertions.contains(
            LIST_OUT,
            "pending",
            "list shows pending status"
          )
          ;(yield* site.attempt(
            "queuety",
            "dispatch",
            "test_handler",
            '--payload={"n":2}'
          )).stdout
          ;(yield* site.attempt(
            "queuety",
            "dispatch",
            "test_handler",
            '--payload={"n":3}'
          )).stdout
          const STATUS2 = (yield* site.attempt("queuety", "status")).stdout
          yield* assertions.contains(
            STATUS2,
            "3",
            "status shows 3 pending jobs"
          )
          yield* announce("")
          yield* announce("--- WP-CLI: Bury and Retry ---")
          const JOB_ID = (yield* site.attempt(
            "db",
            "query",
            "SELECT id FROM wp_queuety_jobs ORDER BY id ASC LIMIT 1;",
            "--skip-column-names"
          )).stdout.replace(/\s/g, "")
          const BURY_OUT = (yield* site.attempt("queuety", "bury", JOB_ID))
            .stdout
          yield* assertions.contains(
            BURY_OUT,
            "buried",
            "bury command succeeds"
          )
          const BURIED_STATUS = (yield* site.attempt(
            "db",
            "query",
            "SELECT status FROM wp_queuety_jobs WHERE id=" + JOB_ID + ";",
            "--skip-column-names"
          )).stdout.replace(/\s/g, "")
          yield* assertions.equals(
            "buried",
            BURIED_STATUS,
            "job is in buried status"
          )
          const RETRY_OUT = (yield* site.attempt("queuety", "retry-buried"))
            .stdout
          yield* assertions.contains(
            RETRY_OUT,
            "Retried",
            "retry-buried command succeeds"
          )
          const RETRIED_STATUS = (yield* site.attempt(
            "db",
            "query",
            "SELECT status FROM wp_queuety_jobs WHERE id=" + JOB_ID + ";",
            "--skip-column-names"
          )).stdout.replace(/\s/g, "")
          yield* assertions.equals(
            "pending",
            RETRIED_STATUS,
            "job is pending after retry"
          )
          yield* announce("")
          yield* announce("--- WP-CLI: Delete and Purge ---")
          const DELETE_OUT = (yield* site.attempt("queuety", "delete", JOB_ID))
            .stdout
          yield* assertions.contains(
            DELETE_OUT,
            "deleted",
            "delete command succeeds"
          )
          const DELETED_COUNT = (yield* site.attempt(
            "db",
            "query",
            "SELECT COUNT(*) FROM wp_queuety_jobs WHERE id=" + JOB_ID + ";",
            "--skip-column-names"
          )).stdout.replace(/\s/g, "")
          yield* assertions.equals(
            "0",
            DELETED_COUNT,
            "job is deleted from database"
          )
          yield* announce("")
          yield* announce("--- WP-CLI: Workflow Commands ---")
          const WF_ID = (yield* site.attempt(
            "eval",
            "\n    $wf_id = Queuety\\Queuety::workflow('test_wf')\n        ->then('Queuety\\Tests\\Integration\\Fixtures\\AccumulatingStep')\n        ->then('Queuety\\Tests\\Integration\\Fixtures\\AccumulatingStep')\n        ->dispatch(['counter' => 0]);\n    echo $wf_id;\n"
          )).stdout
          if (WF_ID !== "" && WF_ID !== "0") {
            yield* assertions.check(
              true,
              "workflow dispatch via PHP API (ID: " + WF_ID + ")"
            )
            const WF_STATUS = (yield* site.attempt(
              "queuety",
              "workflow",
              "status",
              WF_ID
            )).stdout
            yield* assertions.contains(
              WF_STATUS,
              "test_wf",
              "workflow status shows name"
            )
            yield* assertions.contains(
              WF_STATUS,
              "running",
              "workflow status shows running"
            )
            yield* assertions.contains(
              WF_STATUS,
              "0/2",
              "workflow status shows step 0/2"
            )
            yield* evaluate(
              "Queuety\\Queuety::put_artifact(" +
                WF_ID +
                ", 'brief', ['status' => 'ready']);"
            )
            const WF_ARTIFACTS = (yield* site.attempt(
              "queuety",
              "workflow",
              "artifacts",
              WF_ID
            )).stdout
            yield* assertions.contains(
              WF_ARTIFACTS,
              "brief",
              "workflow artifacts lists stored artifact"
            )
            const WF_ARTIFACT = (yield* site.attempt(
              "queuety",
              "workflow",
              "artifact",
              WF_ID,
              "brief"
            )).stdout
            yield* assertions.contains(
              WF_ARTIFACT,
              '"key": "brief"',
              "workflow artifact shows stored artifact"
            )
            const WF_LIST = (yield* site.attempt("queuety", "workflow", "list"))
              .stdout
            yield* assertions.contains(
              WF_LIST,
              "test_wf",
              "workflow list shows workflow"
            )
            yield* assertions.contains(
              WF_LIST,
              "running",
              "workflow list shows status"
            )
            const PAUSE_OUT = (yield* site.attempt(
              "queuety",
              "workflow",
              "pause",
              WF_ID
            )).stdout
            yield* assertions.contains(
              PAUSE_OUT,
              "paused",
              "workflow pause succeeds"
            )
            const WF_PAUSED = (yield* site.attempt(
              "queuety",
              "workflow",
              "status",
              WF_ID
            )).stdout
            yield* assertions.contains(
              WF_PAUSED,
              "paused",
              "workflow is paused"
            )
            ;(yield* site.attempt(
              "eval",
              "Queuety\\Queuety::worker()->flush();"
            )).stdout
            const WF_AFTER_PAUSED_FLUSH = (yield* site.attempt(
              "queuety",
              "workflow",
              "status",
              WF_ID
            )).stdout
            yield* assertions.contains(
              WF_AFTER_PAUSED_FLUSH,
              "paused",
              "workflow stays paused while work is excluded"
            )
            yield* assertions.contains(
              WF_AFTER_PAUSED_FLUSH,
              "0/2",
              "workflow did not advance while paused"
            )
            const RESUME_OUT = (yield* site.attempt(
              "queuety",
              "workflow",
              "resume",
              WF_ID
            )).stdout
            yield* assertions.contains(
              RESUME_OUT,
              "resumed",
              "workflow resume succeeds"
            )
            const WF_RESUMED = (yield* site.attempt(
              "queuety",
              "workflow",
              "status",
              WF_ID
            )).stdout
            yield* assertions.contains(
              WF_RESUMED,
              "running",
              "workflow is running after resume"
            )
          } else {
            yield* assertions.check(
              false,
              "workflow dispatch via PHP API",
              "Could not create workflow"
            )
          }
          yield* announce("")
          yield* announce("--- WP-CLI: Review Workflow ---")
          yield* reset()
          const REVIEW_WF_ID = yield* evaluate(
            "\n    $wf_id = Queuety\\Queuety::workflow('review_gate')\n        ->wait_for_approval(result_key: 'approval')\n        ->wait_for_input(result_key: 'revision_notes')\n        ->then('WpEnvReviewFinalizeStep')\n        ->dispatch(['draft_id' => 9]);\n    echo $wf_id;\n"
          )
          if (REVIEW_WF_ID !== "" && REVIEW_WF_ID !== "0") {
            yield* assertions.check(
              true,
              "review workflow dispatch via PHP API (ID: " + REVIEW_WF_ID + ")"
            )
            yield* processOne()
            const REVIEW_WAIT = (yield* site.attempt(
              "queuety",
              "workflow",
              "status",
              REVIEW_WF_ID
            )).stdout
            yield* assertions.contains(
              REVIEW_WAIT,
              "waiting_for_signal",
              "review workflow waits for approval"
            )
            yield* assertions.contains(
              REVIEW_WAIT,
              "approval",
              "review workflow status shows approval wait"
            )
            const REVIEW_APPROVE = (yield* site.attempt(
              "queuety",
              "workflow",
              "approve",
              REVIEW_WF_ID,
              '--data={"approved":true,"by":"editor"}'
            )).stdout
            yield* assertions.contains(
              REVIEW_APPROVE,
              "Approval sent",
              "workflow approve command succeeds"
            )
            yield* processOne()
            const REVIEW_INPUT_WAIT = (yield* site.attempt(
              "queuety",
              "workflow",
              "status",
              REVIEW_WF_ID
            )).stdout
            yield* assertions.contains(
              REVIEW_INPUT_WAIT,
              "waiting_for_signal",
              "review workflow waits for input after approval"
            )
            yield* assertions.contains(
              REVIEW_INPUT_WAIT,
              "editor",
              "approval payload is visible in workflow state"
            )
            const REVIEW_INPUT = (yield* site.attempt(
              "queuety",
              "workflow",
              "input",
              REVIEW_WF_ID,
              '--data={"note":"ship it"}'
            )).stdout
            yield* assertions.contains(
              REVIEW_INPUT,
              "Input sent",
              "workflow input command succeeds"
            )
            yield* processOne()
            const REVIEW_DONE = (yield* site.attempt(
              "queuety",
              "workflow",
              "status",
              REVIEW_WF_ID
            )).stdout
            yield* assertions.contains(
              REVIEW_DONE,
              "completed",
              "review workflow completes after input"
            )
            yield* assertions.contains(
              REVIEW_DONE,
              '"review_outcome": "approved"',
              "review workflow stores approved outcome"
            )
            yield* assertions.contains(
              REVIEW_DONE,
              '"revision_note": "ship it"',
              "review workflow stores revision note"
            )
          } else {
            yield* assertions.check(
              false,
              "review workflow dispatch via PHP API",
              "Could not create review workflow"
            )
          }
          yield* announce("")
          yield* announce("--- WP-CLI: Agent Workflow Group ---")
          yield* reset()
          const AGENT_WF_ID = yield* evaluate(
            "\n    $child = Queuety\\Queuety::workflow('wp_env_agent')\n        ->then('WpEnvAgentTaskStep')\n        ->max_attempts(1);\n\n    $wf_id = Queuety\\Queuety::workflow('wp_env_agent_parent')\n        ->start_agents('agent_tasks', $child, group_key: 'researchers')\n        ->wait_for_agent_group('researchers', \\Queuety\\Enums\\WaitMode::Quorum, 2, 'agent_results')\n        ->then('WpEnvAgentSummaryStep')\n        ->dispatch([\n            'agent_tasks' => [\n                ['topic' => 'pricing'],\n                ['topic' => 'reviews'],\n                ['topic' => 'faq', 'should_fail' => true]\n            ]\n        ]);\n\n    echo $wf_id;\n"
          )
          if (AGENT_WF_ID !== "" && AGENT_WF_ID !== "0") {
            yield* assertions.check(
              true,
              "agent workflow group dispatch via PHP API (ID: " +
                AGENT_WF_ID +
                ")"
            )
            yield* processOne()
            const AGENT_RUNNING = (yield* site.attempt(
              "queuety",
              "workflow",
              "status",
              AGENT_WF_ID
            )).stdout
            yield* assertions.contains(
              AGENT_RUNNING,
              "running",
              "agent workflow continues after starting children"
            )
            yield* processOne()
            yield* processOne()
            yield* processOne()
            yield* processOne()
            const AGENT_DONE = (yield* site.attempt(
              "queuety",
              "workflow",
              "status",
              AGENT_WF_ID
            )).stdout
            yield* assertions.contains(
              AGENT_DONE,
              "completed",
              "agent workflow completes after quorum"
            )
            yield* assertions.contains(
              AGENT_DONE,
              "pricing",
              "agent workflow summary includes successful topics"
            )
            yield* assertions.contains(
              AGENT_DONE,
              "reviews",
              "agent workflow summary includes multiple successful topics"
            )
          } else {
            yield* assertions.check(
              false,
              "agent workflow group dispatch via PHP API",
              "Could not create agent workflow"
            )
          }
          yield* announce("")
          yield* announce("--- WP-CLI: Worker ---")
          yield* reset()
          const WORK_RESULT = yield* evaluate(
            "\n    Queuety\\Queuety::register('e2e_test', 'WpEnvQueueHandler');\n    Queuety\\Queuety::dispatch('e2e_test', ['msg' => 'hello from e2e'])->id();\n\n    $queue = new Queuety\\Queue(Queuety\\Queuety::connection());\n    $job = $queue->claim();\n\n    if (null !== $job) {\n        Queuety\\Queuety::worker()->process_job($job);\n    }\n\n    echo get_option('queuety_e2e_result', 'NOT_SET');\n"
          )
          yield* assertions.contains(
            WORK_RESULT,
            "hello from e2e",
            "worker processes job with correct payload"
          )
          yield* announce("")
          yield* announce("--- WP-CLI: Log ---")
          const LOG_OUT = (yield* site.attempt("queuety", "log")).stdout
          if (new RegExp("started|completed").test(LOG_OUT)) {
            yield* assertions.check(true, "log command shows entries")
          } else {
            yield* assertions.excludes(
              LOG_OUT,
              "Error",
              "log command does not error"
            )
          }
          yield* announce("")
          yield* announce("--- WP-CLI: Log Purge and Workflow Limit ---")
          const LOG_PURGE_OUT = (yield* site.attempt(
            "queuety",
            "log",
            "purge",
            "--older-than=30"
          )).stdout
          yield* assertions.contains(
            LOG_PURGE_OUT,
            "Purged",
            "log purge accepts its action and retention option"
          )
          const WORKFLOW_LIMIT_OUT = (yield* site.attempt(
            "queuety",
            "workflow",
            "list",
            "--limit=1"
          )).stdout
          yield* assertions.excludes(
            WORKFLOW_LIMIT_OUT,
            "Error",
            "workflow list accepts --limit"
          )
          yield* announce("")
          yield* announce("--- Bridged WordPress Cron ---")
          yield* reset()
          const CRON_RESULT = yield* evaluate(
            "\n    add_action('queuety_e2e_cron', function ($first, $second) {\n        update_option('queuety_e2e_cron_result', $first . ':' . $second);\n    }, 10, 2);\n    Queuety\\CronBridge::install();\n    wp_schedule_event(time(), 'hourly', 'queuety_e2e_cron', ['hello', 'cron']);\n    global $wpdb;\n    $wpdb->query(\"UPDATE wp_queuety_schedules SET next_run = '2000-01-01 00:00:00' WHERE handler = '__queuety_cron_queuety_e2e_cron'\");\n    Queuety\\Queuety::run_worker('default', true);\n    echo get_option('queuety_e2e_cron_result', 'NOT_SET');\n    Queuety\\Queuety::scheduler()->remove('__queuety_cron_queuety_e2e_cron');\n    Queuety\\CronBridge::uninstall();\n"
          )
          yield* assertions.contains(
            CRON_RESULT,
            "hello:cron",
            "a bridged schedule invokes the original WordPress callbacks with their arguments"
          )
          yield* announce("")
          yield* announce("--- WP-CLI: Recover ---")
          const RECOVER_OUT = (yield* site.attempt("queuety", "recover")).stdout
          yield* assertions.contains(
            RECOVER_OUT,
            "Recovered",
            "recover command succeeds"
          )
          yield* announce("")
          yield* announce("--- WP-CLI: Purge ---")
          const PURGE_OUT = (yield* site.attempt(
            "queuety",
            "purge",
            "--older-than=0"
          )).stdout
          yield* assertions.contains(
            PURGE_OUT,
            "Purged",
            "purge command succeeds"
          )
          yield* announce("")
          yield* announce("--- WP-CLI: Full Workflow E2E ---")
          const FULL_WF_ID = yield* evaluate(
            "\n    $wf_id = Queuety\\Queuety::workflow('full_e2e')\n        ->then('WpEnvFetchStep')\n        ->then('WpEnvProcessStep')\n        ->dispatch(['user_id' => 42]);\n    echo $wf_id;\n"
          )
          yield* processOne()
          yield* processOne()
          const FULL_WF = (yield* site.attempt(
            "queuety",
            "workflow",
            "status",
            FULL_WF_ID
          )).stdout
          if (new RegExp("completed").test(FULL_WF)) {
            yield* assertions.check(true, "full workflow completes via worker")
            yield* assertions.contains(
              FULL_WF,
              "result from: data for user 42",
              "workflow state accumulates correctly"
            )
          } else {
            yield* assertions.check(
              false,
              "full workflow completes via worker",
              "Got: " + FULL_WF
            )
          }
          yield* announce("")
          yield* announce("--- WP-CLI: Runtime Artifacts ---")
          const ARTIFACT_WF_ID = yield* evaluate(
            "\n    $wf_id = Queuety\\Queuety::workflow('artifact_runtime')\n        ->then('WpEnvArtifactStep')\n        ->dispatch(['topic' => 'launch']);\n    echo $wf_id;\n"
          )
          if (ARTIFACT_WF_ID !== "" && ARTIFACT_WF_ID !== "0") {
            yield* assertions.check(
              true,
              "runtime artifact workflow dispatch via PHP API (ID: " +
                ARTIFACT_WF_ID +
                ")"
            )
            yield* evaluate("Queuety\\Queuety::worker()->flush();")
            const ARTIFACT_STATUS = (yield* site.attempt(
              "queuety",
              "workflow",
              "status",
              ARTIFACT_WF_ID
            )).stdout
            yield* assertions.contains(
              ARTIFACT_STATUS,
              "Artifacts: 1",
              "workflow status shows runtime artifact summary"
            )
            const RUNTIME_ARTIFACT = (yield* site.attempt(
              "queuety",
              "workflow",
              "artifact",
              ARTIFACT_WF_ID,
              "draft"
            )).stdout
            yield* assertions.contains(
              RUNTIME_ARTIFACT,
              '"topic": "launch"',
              "runtime artifact stores the workflow topic"
            )
            yield* assertions.contains(
              RUNTIME_ARTIFACT,
              '"source": "wp-env"',
              "runtime artifact stores metadata"
            )
          } else {
            yield* assertions.check(
              false,
              "runtime artifact workflow dispatch via PHP API",
              "Could not create artifact workflow"
            )
          }
          yield* assertions.finish(json)
        }),
      json
    ),
})
if (import.meta.main) main(command)
