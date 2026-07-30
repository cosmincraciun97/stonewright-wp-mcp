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
                'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
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
        return $this->audit(
            $args,
            function ( array $args ) {
                $post_id = (int) $args['post_id'];
                if ( ! get_post( $post_id ) ) {
                    return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ) );
                }

                // Take snapshot before any write.
                $snapshot_id = Backup::snapshot_post( $post_id );

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

`plugin/tests/Abilities/<Category>/<VerbName>Test.php`

Minimum test shape:

```php
<?php

namespace Stonewright\WpMcp\Tests\Abilities\<Category>;

use WP_UnitTestCase;
use Stonewright\WpMcp\Abilities\<Category>\<VerbName>;

class <VerbName>Test extends WP_UnitTestCase {

    public function test_returns_error_when_post_not_found(): void {
        $ability = new <VerbName>();
        $result  = $ability->execute( [ 'post_id' => 999999 ] );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'not_found', $result->get_error_code() );
    }

    public function test_happy_path(): void {
        $post_id = $this->factory()->post->create();
        $ability = new <VerbName>();
        $result  = $ability->execute( [ 'post_id' => $post_id ] );
        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'snapshot_id', $result );
    }
}
```

Run: `cd plugin && composer test`
