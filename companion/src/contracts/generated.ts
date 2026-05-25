/* eslint-disable @typescript-eslint/no-empty-interface */
// GENERATED FILE — do not edit manually.
// Regenerate with: npm run build:contracts

/**
 * GET /health has no request body.
 */
export interface HealthRequest {}

export interface HealthResponse {
	status: 'ok';
	/**
	 * Semantic version of this contract set. PHP checks major-version compatibility.
	 */
	contract_version: string;
}
