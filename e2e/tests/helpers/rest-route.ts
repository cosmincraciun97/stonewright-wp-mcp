/**
 * Match a WordPress REST route by name, whatever the permalink structure.
 *
 * On pretty permalinks a route is a path (/wp-json/stonewright/v1/...); on plain permalinks it is the rest_route query
 * value (/?rest_route=/stonewright/v1/... or, when a script encodes it, ?rest_route=%2Fstonewright%2Fv1%2F...). A URL
 * glob matches only the first form, so specs that intercept or count REST calls match through this instead.
 *
 * `route` is the part after the REST root, for example 'stonewright/v1/admin/abilities/toggle'. The match is on the
 * decoded route, so a longer route that merely starts with the same text (…/toggle-all) does not match.
 */
export function restRouteUrl(route: string): (url: URL) => boolean {
	const wanted = `/${route.replace(/^\/+|\/+$/g, '')}`;
	return (url: URL) => {
		const target = url.searchParams.get('rest_route') ?? decodePath(url.pathname).replace(/^.*?\/wp-json(?=\/)/, '');
		const normalized = decodePath(target).replace(/\/+$/, '');
		return normalized === wanted;
	};
}

/** The same test for a request URL string (page.on('request') and similar). */
export function isRestRoute(href: string, route: string): boolean {
	return restRouteUrl(route)(new URL(href));
}

function decodePath(value: string): string {
	try {
		return decodeURIComponent(value);
	} catch {
		return value;
	}
}
