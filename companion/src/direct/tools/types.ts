import type { WpRestClient } from "../wp-rest-client.js";
import type { DirectWriteMode } from "../writes.js";
import type { ResolvedSite } from "../sites-config.js";

export interface DirectToolContext {
  client: WpRestClient;
  site: ResolvedSite;
  writeMode: DirectWriteMode;
  env?: NodeJS.ProcessEnv | undefined;
  fetchImpl?: typeof fetch | undefined;
}
