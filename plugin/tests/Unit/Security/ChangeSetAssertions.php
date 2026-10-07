<?php
/**
 * Shared assertions: a change set must satisfy the PHP validator and the JSON schema.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use Stonewright\WpMcp\Security\ChangeSet;

trait ChangeSetAssertions {

	/** Absolute path of the published ChangeSetV1 schema. */
	private static function change_set_schema_path(): string {
		return dirname( __DIR__, 4 ) . '/docs/contracts/change-set-v1.schema.json';
	}

	/**
	 * Validation messages from the JSON schema; empty when the document is valid.
	 *
	 * @param array<string, mixed>       $document
	 * @param array<string, mixed>|null  $extension_schemas Property schemas merged into the schema, keyed by field name.
	 * @return list<string>
	 */
	private static function change_set_schema_errors( array $document, ?array $extension_schemas = null ): array {
		$raw    = (string) file_get_contents( self::change_set_schema_path() );
		$schema = json_decode( $raw );
		self::assertInstanceOf( \stdClass::class, $schema, 'The ChangeSetV1 schema must be valid JSON.' );
		if ( null !== $extension_schemas ) {
			foreach ( $extension_schemas as $name => $fragment ) {
				$schema->properties->{$name} = json_decode( (string) json_encode( $fragment ) );
			}
			$schema->{'$id'} = 'https://stonewright.dev/contracts/change-set-v1-extended-' . md5( (string) json_encode( $extension_schemas ) ) . '.schema.json';
		}
		$id        = (string) $schema->{'$id'};
		$validator = new \Opis\JsonSchema\Validator();
		$validator->resolver()->registerRaw( $schema, $id );
		$result = $validator->validate( json_decode( (string) json_encode( $document ) ), $id );
		if ( $result->isValid() ) {
			return [];
		}
		$error = $result->error();
		return [ null === $error ? 'invalid' : $error->message() ];
	}

	/**
	 * Assert that both validators accept the change set.
	 *
	 * @param array<string, mixed> $change_set
	 */
	private static function assertValidChangeSet( array $change_set, string $context = '' ): void {
		self::assertSame( [], ChangeSet::validate( $change_set ), $context . ' PHP validator' );
		self::assertSame( [], self::change_set_schema_errors( $change_set ), $context . ' JSON schema' );
	}
}
