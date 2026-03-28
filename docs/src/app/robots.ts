import { generateRobots } from "onedocs/seo";

const baseUrl = "https://queuety.dev";

export default function robots() {
  return generateRobots({ baseUrl });
}
