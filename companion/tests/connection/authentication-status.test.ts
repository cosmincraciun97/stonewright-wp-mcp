import { describe, expect, it } from 'vitest';
import { reauthenticationRequiredStatus } from '../../src/connection/authentication-status.js';
import { STATUS_SCHEMA_VERSION } from '../../src/connection/status-contract.js';

describe('authentication status v3', () => {
  it('requires a model-visible notice for terminal OAuth state', () => {
    const status = reauthenticationRequiredStatus('refresh_token_expired', 'Reauthenticate this server.');
    expect(status.state).toBe('reauth_required');
    expect(status.agent_notice_required).toBe(true);
    expect(status.continuity_target_seconds).toBe(604800);
    expect(status.user_action).toBe('Reauthenticate this server.');
  });

  it('publishes schema version 3', () => {
    expect(STATUS_SCHEMA_VERSION).toBe(3);
  });
});
