/**
 * Authentication and recovery fields for connection status schema v3.
 *
 * Token-shaped properties are intentionally omitted from this contract.
 */

export type AuthenticationState = 'authenticated' | 'refreshing' | 'transient_failure' | 'reauth_required' | 'unknown';

export interface AuthenticationStatusV3 {
	configured: boolean;
	method: 'app-password' | 'authorization' | 'oauth' | 'none' | 'unknown';
	state: AuthenticationState;
	reason_code: string | null;
	last_success_at: string | null;
	refresh_expires_at: string | null;
	continuity_target_seconds: 604800;
	agent_notice_required: boolean;
	user_action: string | null;
}

export interface RecoveryStatus {
	catalog_preserved: boolean;
	remote_calls_available: boolean;
	last_success_at: string | null;
	reconnect_attempted: boolean;
	reconnect_coalesced: boolean;
}

export function reauthenticationRequiredStatus(reasonCode: string, userAction: string): AuthenticationStatusV3 {
	return {
		configured: true,
		method: 'oauth',
		state: 'reauth_required',
		reason_code: reasonCode,
		last_success_at: null,
		refresh_expires_at: null,
		continuity_target_seconds: 604800,
		agent_notice_required: true,
		user_action: userAction,
	};
}
