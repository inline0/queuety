import { defineConfig } from "onedocs/config";
import { Zap, GitBranch, Layers, Shield, Clock, Split, BarChart3, Code } from "lucide-react";

const iconClass = "h-5 w-5 text-fd-primary";

export default defineConfig({
  title: "Queuety",
  description: "A job queue and durable workflow engine for WordPress that doesn't boot WordPress.",
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
    features: [
      {
        title: "Fast",
        description:
          "Workers process jobs from a minimal PHP bootstrap. No WordPress boot. Direct PDO database access.",
        icon: <Zap className={iconClass} />,
      },
      {
        title: "Durable Workflows",
        description:
          "Multi-step processes that survive PHP timeouts, retries, and crashes. State persists across steps.",
        icon: <GitBranch className={iconClass} />,
      },
      {
        title: "Priority Queues",
        description:
          "Four priority levels with type-safe PHP 8.2 enums. Higher priority jobs are processed first.",
        icon: <Layers className={iconClass} />,
      },
      {
        title: "Rate Limiting",
        description:
          "Per-handler rate limits to protect external APIs and control throughput.",
        icon: <Shield className={iconClass} />,
      },
      {
        title: "Recurring Jobs",
        description:
          "Cron expressions and interval-based scheduling. Built-in scheduler with automatic job dispatch.",
        icon: <Clock className={iconClass} />,
      },
      {
        title: "Parallel Steps",
        description:
          "Run workflow steps concurrently. The workflow advances when all parallel jobs complete.",
        icon: <Split className={iconClass} />,
      },
      {
        title: "Metrics",
        description:
          "Throughput, latency percentiles, and error rates per handler. Query via PHP API or CLI.",
        icon: <BarChart3 className={iconClass} />,
      },
      {
        title: "PHP 8.2+",
        description:
          "Enums, readonly classes, match expressions, named arguments. Modern PHP throughout.",
        icon: <Code className={iconClass} />,
      },
    ],
  },
});
