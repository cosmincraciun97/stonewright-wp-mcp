import type { WpRestClient } from "../wp-rest-client.js";
import type { DirectWriteMode } from "../writes.js";
import type { ResolvedSite } from "../sites-config.js";

export interface DirectToolContext {
  client: WpRestClient;
  site: ResolvedSite;
  writeMode: DirectWriteMode;
  env?: NodeJS.ProcessEnv | undefined;
  fetchImpl?: typeof fetch | undefined;
  /** Test seam for the front-end probe's host lookup; production resolves through DNS. */
  lookupImpl?: ((hostname: string) => Promise<Array<{ address: string; family: number }>>) | undefined;
}
