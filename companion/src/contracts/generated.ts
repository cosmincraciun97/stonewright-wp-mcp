/* eslint-disable @typescript-eslint/no-explicit-any, @typescript-eslint/no-redundant-type-constituents */
// GENERATED FILE — do not edit manually.
// Regenerate with: npm run build:contracts
//
// Sources: companion/src/contracts/*.schema.json
// Tool: json-schema-to-typescript

/**
 * GET /health has no request body.
 */

export interface HealthRequest {}

export interface HealthResponse {
  status: "ok";
  /**
   * Semantic version of this contract set. PHP checks major-version compatibility.
   */
  contract_version: string;
  /**
   * Version of the running companion artifact.
   */
  version: string;
  /**
   * Official package for the running artifact version.
   */
  expected_companion_package: string;
  /**
   * Exact configured package, included only for a valid bearer and a validated configuration source.
   */
  configured_package?: string;
  configured_package_version?: string;
  configured_package_provenance?: "npm-registry" | "github-release";
  configured_package_source?: "authenticated-environment";
}
