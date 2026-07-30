const SAFE_VALUE =
	/^(?:\[?redacted\]?|<[^>]+>|\$\{[A-Z_][A-Z0-9_]*\}?|\$[A-Z_][A-Z0-9_]*|x{4}(?:\s+x{4})*|your[-_ ]|placeholder\b|(?:required|enabled|disabled|never|none|null|true|false)\b)/i;

const CREDENTIAL_NAME =
	'(?:password|user_pass|pass|app_?password|application_password|wp_app_password|api[_ -]?key|client_secret|access_token|refresh_token|authorization|token|secret|cookie)';

function assignedCredentialValues(value: string): string[] {
	const matches = value.matchAll(
		new RegExp(
			`["']?\\b${CREDENTIAL_NAME}\\b["']?\\s*(?::|=|\\bis\\b|\\bwas\\b)\\s*(?:"([^"]+)"|'([^']+)'|([^\\s,;&}]+))`,
			'gi',
		),
	);
	return [...matches].map((match) => String(match[1] ?? match[2] ?? match[3] ?? '').trim());
}

export function containsSensitiveMaterial(value: string): boolean {
	if (/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/i.test(value)) {
		return true;
	}
	if (/\b(?:Basic|Bearer)\s+[A-Za-z0-9+/=_-]{12,}/i.test(value)) {
		return true;
	}
	if (/https?:\/\/[^/\s:@]+:[^/\s@]+@/i.test(value)) {
		return true;
	}
	const appPassword = value.match(/\b(?:[A-Za-z0-9]{4}\s+){5}[A-Za-z0-9]{4}\b/)?.[0];
	if (appPassword && !/^x{4}(?:\s+x{4}){5}$/i.test(appPassword)) {
		return true;
	}
	return assignedCredentialValues(value).some(
		(credential) => credential.length >= 4 && !SAFE_VALUE.test(credential),
	);
}

export function assertNoSensitiveMaterial(value: string, target: string): void {
	if (containsSensitiveMaterial(value)) {
		throw new Error(`${target} contains credential material and was not stored.`);
	}
}
