import { HomePage, CTASection } from "onedocs";
import config from "../../onedocs.config";

export default function Home() {
  return (
    <HomePage config={config}>
      <CTASection
        title="Ready to get started?"
        description="Create your first sandbox in under a minute."
        cta={{ label: "Read the Docs", href: "/docs" }}
      />
    </HomePage>
  );
}
