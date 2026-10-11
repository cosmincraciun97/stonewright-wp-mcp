# Ability Scaffold

Copy this template when creating a new ability. Replace all placeholders.

## File location

`plugin/includes/Abilities/<Category>/<VerbName>.php`

## Template

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\<Category>;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;

final class <VerbName> extends AbilityKernel {

    public function name(): string {
        return 'stonewright/<category>-<verb>';
    }

    public function label(): string {
        return __( '<Human label>', 'stonewright' );
    }

    public function description(): string {
        return __( '<One-line description.>', 'stonewright' );
    }

    public function category(): string {
        return '<category>';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => [
                'post_id'            => [ 'type' => 'integer', 'minimum' => 1 ],
                'confirmation_token' => [ 'type' => 'string' ],
                // add your fields here
            ],
            'required'             => [ 'post_id' ],
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'post_id'     => [ 'type' => 'integer' ],
                'snapshot_id' => [ 'type' => 'string' ],
            ],
        ];
    }

    public function permission_callback( array $args ): bool|\WP_Error {
        $id = (int) ( $args['post_id'] ?? 0 );
        return Permissions::edit_post( $id );
    }

    public function execute( array $args ): array|\WP_Error {
        // audit_write() records the audit row and, in production-safe mode, refuses a call without a valid
        // confirmation_token. A read-only ability uses audit_read() and declares no confirmation_token.
        return $this->audit_write(
            $args,
            function ( array $args ) {
                $post_id = (int) $args['post_id'];
                if ( ! get_post( $post_id ) ) {
                    return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ) );
                }

                // Take snapshot before any write.
                $snapshot_id = Backup::snapshot_post( $post_id );
                if ( '' === $snapshot_id ) {
                    return $this->backup_failed_error();
                }

                // ... your logic here ...

                return [
                    'post_id'     => $post_id,
                    'snapshot_id' => $snapshot_id,
                ];
            }
        );
    }
}
```

## Register the ability

In `plugin/includes/Core/AbilityRegistry.php`, add to the registry map:

```php
\Stonewright\WpMcp\Abilities\<Category>\<VerbName>::class,
```

## Write the test first

`plugin/tests/Unit/Abilities/<Category>/<VerbName>Test.php`

The unit suite runs on the WordPress stubs in `plugin/tests/bootstrap.php` (no
WordPress install): set the user, capabilities and options through the
`$GLOBALS['stonewright_test_*']` arrays, as the existing tests under
`plugin/tests/Unit/Abilities/` do.

Minimum test shape:

```php
<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\<Category>;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\<Category>\<VerbName>;

final class <VerbName>Test extends TestCase {

    protected function setUp(): void {
        $GLOBALS['stonewright_test_user_logged_in'] = true;
        $GLOBALS['stonewright_test_user_caps']      = [ 'edit_post' => true ];
        $GLOBALS['stonewright_test_options']        = [ 'stonewright_mode' => 'development' ];
        $GLOBALS['stonewright_test_posts']          = [];
    }

    protected function tearDown(): void {
        $GLOBALS['stonewright_test_user_caps'] = [];
        $GLOBALS['stonewright_test_options']   = [];
        $GLOBALS['stonewright_test_posts']     = [];
    }

    public function test_refuses_a_caller_without_the_capability(): void {
        $GLOBALS['stonewright_test_user_caps'] = [];
        self::assertFalse( ( new <VerbName>() )->permission_callback( [ 'post_id' => 1 ] ) );
    }

    public function test_returns_error_when_post_not_found(): void {
        $result = ( new <VerbName>() )->execute( [ 'post_id' => 999999 ] );
        self::assertInstanceOf( \WP_Error::class, $result );
        // $this->error() prefixes the code with "stonewright_".
        self::assertSame( 'stonewright_not_found', $result->get_error_code() );
    }
}
```

Run: `cd plugin && composer test`. After adding or changing an ability, regenerate the
generated files with `composer docs:matrix` and `composer contracts:generate`.
