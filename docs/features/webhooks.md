---
title: "Webhooks"
description: "HTTP notifications on job and workflow events."
path: "features/webhooks"
order: 380
section: "Features"
meta_title: "Webhooks"
meta_description: "HTTP notifications on job and workflow events."
---

# Webhooks

Queuety can send HTTP POST notifications to external URLs when specific events occur. Use webhooks to integrate with monitoring systems, Slack, or custom dashboards.

## Supported events

| Event | Fired when |
|---|---|
| `job.completed` | A plain handler job or `Contracts\Job` class finishes successfully (not fired for workflow steps or state-machine actions) |
| `job.failed` | An attempt throws and a retry is scheduled |
| `job.buried` | A job exhausts all retry attempts |
| `workflow.failed` | A workflow step fails permanently |

## Registering webhooks

Via PHP:

```php
$id = Queuety::webhook_notifier()->register( 'job.buried', 'https://hooks.slack.com/services/...' );
```

Via CLI:

```bash
wp queuety webhook add job.buried https://hooks.slack.com/services/...
wp queuety webhook add workflow.failed https://monitoring.example.com/queuety
```

## Listing webhooks

```php
$webhooks = Queuety::webhook_notifier()->list();
```

```bash
wp queuety webhook list
```

## Removing webhooks

```php
Queuety::webhook_notifier()->remove( $webhook_id );
```

```bash
wp queuety webhook remove 3
```

## Payload format

When an event fires, Queuety sends an HTTP POST request with a JSON body. The payload always has the shape:

```json
{
    "event": "job.buried",
    "data": {
        "job_id": 42,
        "handler": "send_email",
        "queue": "emails",
        "error_message": "Connection timed out"
    },
    "timestamp": "2025-03-15T14:30:00Z"
}
```

For workflow failures:

```json
{
    "event": "workflow.failed",
    "data": {
        "workflow_id": 7,
        "job_id": 42,
        "handler": "CallLlmStep",
        "error_message": "API rate limit exceeded"
    },
    "timestamp": "2025-03-15T14:30:00Z"
}
```

Webhook delivery is best-effort. The worker posts to each registered URL in turn with a one-second stream timeout and ignores delivery failures, so a slow endpoint costs the worker up to about a second per URL but never fails the job.

## Use cases

- **Slack alerts.** Get notified when jobs are buried or workflows fail.
- **PagerDuty integration.** Trigger incidents for critical job failures.
- **Custom dashboards.** Stream events to your own monitoring infrastructure.
- **Audit trail.** Forward events to an external logging service.
