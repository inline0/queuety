import { generateSitemap } from "onedocs/seo";
import { source } from "@/lib/source";

const baseUrl = "https://queuety.dev";

export default function sitemap() {
  const pages = source
    .getPages()
    .map((page) => page.url.replace(/^\/docs\/?/, ""))
    .map((slug) => (slug.length === 0 ? "index" : slug));

  return generateSitemap({
    baseUrl,
    pages,
  });
}
