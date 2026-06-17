export const APP_VERSION = '1.0.0-alpha.58';

export function companionPackageSpec(version: string = APP_VERSION): string {
	return `https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/v${version}/stonewright-companion-${version}.tgz`;
}
