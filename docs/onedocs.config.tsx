import { defineConfig } from "onedocs/config";
import { Zap, GitBranch, Layers, Shield, Clock, Split, BarChart3, Code } from "lucide-react";
import { HeroLeft } from "./src/components/hero-left";

const iconClass = "h-5 w-5 text-fd-primary";

export default defineConfig({
  title: "Queuety",
  description: "The WordPress workflow engine.",
  logo: {
    light: "/logo-light.svg",
    dark: "/logo-dark.svg",
  },
  icon: { light: "/icon.png", dark: "/icon-dark.png" },
  nav: {
    github: "inline0/queuety",
  },
  footer: {
    links: [{ label: "Inline0.com", href: "https://inline0.com" }],
  },
  homepage: {
    hero: {
      left: HeroLeft,
    },
    features: [
      {
        title: "Fast Execution",
        description:
          "Workers skip the WordPress boot and claim work directly from MySQL. ~5ms overhead per batch.",
        icon: <Zap className={iconClass} />,
      },
      {
        title: "Durable Workflows",
        description:
          "Multi-step processes with persistent state that survive PHP timeouts, crashes, and retries.",
        icon: <GitBranch className={iconClass} />,
      },
      {
        title: "Priority Queues",
        description:
          "Four priority levels via type-safe enums. Higher priority jobs are always processed first.",
        icon: <Layers className={iconClass} />,
      },
      {
        title: "Rate Limiting",
        description:
          "Per-handler execution limits with sliding window. Workers skip rate-limited handlers automatically.",
        icon: <Shield className={iconClass} />,
      },
      {
        title: "Recurring Jobs",
        description:
          "Interval and cron-based scheduling. Built-in cron parser with no external dependencies.",
        icon: <Clock className={iconClass} />,
      },
      {
        title: "Parallel Steps",
        description:
          "Run workflow steps concurrently and wait for all to complete before advancing.",
        icon: <Split className={iconClass} />,
      },
      {
        title: "Metrics & Logging",
        description:
          "Permanent database log with throughput, latency percentiles, and error rates per handler.",
        icon: <BarChart3 className={iconClass} />,
      },
      {
        title: "PHP 8.2+",
        description:
          "Enums, readonly classes, match expressions, constructor promotion, and PHP attributes.",
        icon: <Code className={iconClass} />,
      },
    ],
  },
});
