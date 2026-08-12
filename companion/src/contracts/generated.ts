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
}
