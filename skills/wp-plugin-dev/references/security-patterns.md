# Security Patterns

## Permission callbacks

Always use the `Stonewright\WpMcp\Security\Permissions` helpers. Never write
raw capability checks inline and never pass a literal `true` return.

```php
// Read-only ability: require edit_posts
public function permission_callback( array $args ): bool|\WP_Error {
    return Permissions::edit_posts();
}

// Write to a specific post
public function permission_callback( array $args ): bool|\WP_Error {
    $id = (int) ( $args['post_id'] ?? 0 );
    return Permissions::edit_post( $id );
}

// Write to theme settings (kit, global styles, templates)
public function permission_callback( array $args ): bool|\WP_Error {
    return Permissions::edit_theme_options();
}

// Combined check: needs both
public function permission_callback( array $args ): bool|\WP_Error {
    $id = (int) ( $args['post_id'] ?? 0 );
    return Permissions::edit_post( $id ) && Permissions::edit_theme_options();
}
```

## Backup before Elementor write

```php
use Stonewright\WpMcp\Security\Backup;

// In execute():
$snapshot_id = Backup::snapshot_post( $post_id );
// ... now write Elementor data ...
return [ 'snapshot_id' => $snapshot_id, ... ];
```

The snapshot is stored as post meta. The returned `snapshot_id` is a string
like `snap_` plus 16 hex characters; it is empty when the snapshot could not be
stored, and then the write must not run. Include it in the ability response so
callers can display it in confirmation tokens.

## Spec validation before render

```php
use Stonewright\WpMcp\DesignSpec\Validator;

$result = Validator::validate( $spec );
if ( ! $result['valid'] ) {
    return $this->error( 'invalid_spec', __( 'Spec failed validation.', 'stonewright' ), $result['errors'] );
}
$normalized = $result['normalized'];
// Pass $normalized to the renderer, not the raw $spec.
```

## Input sanitization

```php
// String inputs
$title = sanitize_text_field( (string) ( $args['title'] ?? '' ) );

// Slug inputs
$slug = sanitize_title( (string) ( $args['slug'] ?? '' ) );

// Integer inputs
$post_id = (int) ( $args['post_id'] ?? 0 );

// Boolean inputs
$replace = ! isset( $args['replace'] ) || (bool) $args['replace'];

// Array inputs (whitelisted keys only)
$settings = array_intersect_key(
    (array) ( $args['settings'] ?? [] ),
    array_flip( [ 'title', 'content', 'status' ] )
);
```

## Audit wrapper

Wrap execute logic in `$this->audit_write()` (writes) or `$this->audit_read()`
(reads) to get automatic audit log entries. `audit_write()` also requires a valid
`confirmation_token` in production-safe mode, so declare `confirmation_token` in
the input schema of every write:

```php
public function execute( array $args ): array|\WP_Error {
    return $this->audit_write(
        $args,
        function ( array $args ) {
            // your logic
        }
    );
}
```

## Error helper

```php
return $this->error( 'error_code', __( 'Human message.', 'stonewright' ) );
// With extra data:
return $this->error( 'invalid_spec', __( 'Spec invalid.', 'stonewright' ), $result['errors'] );
```

`$this->error()` returns a `WP_Error` instance. The ability kernel converts it
to a structured JSON error response for the MCP client.

## What NOT to do

- Do not return `true` directly from `permission_callback`; use the Permissions helpers.
- Do not accept and execute raw PHP strings from ability args.
- Do not skip `Backup::snapshot_post` before Elementor or theme.json writes.
- Do not pass unvalidated specs to renderers.
- Do not use file-system paths supplied by the caller without sanitization and
  path-traversal checks.
