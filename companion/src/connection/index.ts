export {
	PERMANENT_GATEWAY_TOOL_NAMES,
	PERMANENT_GATEWAY_TOOL_NAME_SET,
	isPermanentGatewayTool,
	permanentGatewayMembership,
	type PermanentGatewayToolName,
} from './permanent-gateways.js';

export {
	computeSurfaceDigest,
	SurfaceRevisionTracker,
} from './surface-digest.js';

export {
	ConnectionStateMachine,
	type ConnectionStage,
} from './state-machine.js';

export {
	classifyClientReliability,
	defaultProxyProfileForClient,
	isImplicitFullForbidden,
	type ClientReliability,
} from './client-defaults.js';

export {
	STATUS_SCHEMA_VERSION,
	clientHasTool,
	defaultClientVisibility,
	clientVisibilityFromEvidence,
	computeRefreshRequiredToolNames,
	mapConfiguredMode,
	buildConnectionStatusV2,
	normalizeToolName,
	modeCapabilitiesComparison,
	type ConfiguredMode,
	type ActiveMode,
	type ClientVisibility,
	type ClientVisibilityState,
	type SurfaceStatus,
	type PluginStatus,
	type ConnectionStatusV2,
	type ClientHasToolContext,
	type ModeCapabilityRow,
} from './status-contract.js';

export {
	RegistryBarrier,
	type CatalogEntry,
	type RegistrySnapshot,
} from './registry-barrier.js';

export {
	ReconnectController,
	type ReconnectInput,
	type ReconnectResult,
	type ReconnectExecutor,
} from './reconnect.js';
