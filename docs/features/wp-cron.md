---
title: "WP-Cron Replacement"
description: "Replace WordPress built-in cron with Queuety's reliable scheduler."
path: "features/wp-cron"
order: 450
section: "Features"
meta_title: "WP-Cron Replacement"
meta_description: "Replace WordPress built-in cron with Queuety's reliable scheduler."
---

# WP-Cron Replacement

Queuety can replace the built-in WordPress cron system. When enabled, calls to `wp_schedule_event()` are intercepted and routed through Queuety's scheduler instead of the default WP-Cron mechanism. Events are meant to be processed by the Queuety worker, which provides execution independent of site traffic.

> **Current limitation:** the bridge creates the Queuety schedules, but the worker has no handler for the `__queuety_cron_{hook}` jobs they produce, so the original WordPress callbacks never run. Each job fails, is retried with backoff, and is eventually buried ([#9](https://github.com/inline0/queuety/issues/9)). Do not enable the bridge on a site whose cron events must run until that is fixed.

## Enabling the replacement

Call `Queuety::replace_wp_cron()` early in your plugin or theme initialization:

```php
use Queuety\Queuety;

Queuety::replace_wp_cron();
```

This is typically called in your plugin's main file or in a `plugins_loaded` hook. It must be called while WordPress is loaded (the plugin must be active).

## What it intercepts

The cron bridge hooks into three WordPress filters:

| WordPress function | Filter hooked | Behavior |
|---|---|---|
| `wp_schedule_event()` | `pre_schedule_event` | Creates a Queuety schedule with the same interval |
| `wp_unschedule_event()` | `pre_unschedule_event` | Removes the corresponding Queuety schedule |
| `wp_get_scheduled_event()` | `pre_get_scheduled_event` | Returns schedule data from Queuety |

Only **recurring** events (those with a `schedule` property like `hourly`, `twicedaily`, `daily`) are intercepted. Single-fire events scheduled with `wp_schedule_single_event()` are not intercepted.

## How events are mapped

When a plugin calls `wp_schedule_event()`, Queuety resolves the schedule name through `wp_get_schedules()`, creates a Queuety schedule with the handler name `__queuety_cron_{hook}`, and stores the original WordPress hook name and arguments in the schedule payload.

The intended behaviour is that the Queuety worker processing that scheduled job calls `do_action()` with the original hook name and arguments, so the existing WordPress callbacks run with Queuety driving the timing. `CronBridge::process_cron_event()` implements that call, but nothing invokes it yet (see the limitation above). Schedules are also only evaluated by a long-running `wp queuety work` process, about once a minute (see [Scheduling](/docs/features/scheduling)).

### Interval resolution

The bridge resolves schedule names through `wp_get_schedules()` first, then falls back to built-in defaults:

| Schedule name | Interval |
|---|---|
| `hourly` | 3,600 seconds |
| `twicedaily` | 43,200 seconds |
| `daily` | 86,400 seconds |
| `weekly` | 604,800 seconds |

Custom schedule intervals registered via the `cron_schedules` filter are supported as long as `wp_get_schedules()` returns them.

## Restoring WP-Cron

To revert to the default WordPress cron system:

```php
Queuety::restore_wp_cron();
```

This removes the filter hooks and allows WordPress to handle cron events through its built-in mechanism again. Note that `DISABLE_WP_CRON` is defined as `true` when the bridge is installed and cannot be un-defined at runtime, so you may need to remove that constant from `wp-config.php` or restart the process.

## When to use it

The replacement is most useful when your site has low traffic, when you need more reliable timing than WP-Cron can provide, or when you are already running Queuety workers and want cron execution to live in the same operational system. It also helps when centralized observability matters, because cron events then show up in the same logs and metrics as your other queue work.

## Limitations

There are a few important constraints. An active Queuety worker is required, otherwise the scheduled events will never run. Only recurring schedules are intercepted; single-fire events created with `wp_schedule_single_event()` still stay outside the bridge. `replace_wp_cron()` also defines `DISABLE_WP_CRON` for the rest of the current PHP process, so `restore_wp_cron()` removes the hooks but cannot un-define the constant. Finally, the bridge only works when WordPress is loaded, because it depends on WordPress filters and `do_action()`.

## Example: full setup

```php
use Queuety\Queuety;

// In your plugin's main file.
add_action( 'plugins_loaded', function () {
    Queuety::replace_wp_cron();
} );
```

There is no need to call `Queuety::init()` here: the active Queuety plugin builds its connection from the WordPress database credentials and initializes itself on `plugins_loaded`. `replace_wp_cron()` only installs the filters, so it does not depend on that having happened first.

After this, recurring events that plugins register with `wp_schedule_event()` are stored as Queuety schedules. Until [#9](https://github.com/inline0/queuety/issues/9) is fixed, their callbacks do not run.
