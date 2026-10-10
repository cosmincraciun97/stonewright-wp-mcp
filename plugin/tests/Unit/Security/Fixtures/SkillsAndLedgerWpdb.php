<?php
/**
 * One database for a test that needs both the skill tables and the change ledger.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Fixtures;

use Stonewright\WpMcp\Tests\Unit\SkillLibrary\Site\SkillTablesDouble;
use Stonewright\WpMcp\Tests\Unit\Security\Adapters\PostLedgerWpdb;

/**
 * Statements about the skill tables go to the skill tables double, every other statement to the ledger
 * database.
 */
final class SkillsAndLedgerWpdb extends PostLedgerWpdb {

	public SkillTablesDouble $skills;

	public function __construct( string $prefix = 'wptests_' ) {
		parent::__construct( $prefix );
		$this->skills         = new SkillTablesDouble();
		$this->skills->prefix = $prefix;
	}

	public function insert( $table, $data, $format = null ) {
		if ( $this->is_skill_text( (string) $table ) ) {
			$result           = $this->skills->insert( (string) $table, (array) $data, (array) $format );
			$this->insert_id  = $this->skills->insert_id;
			$this->last_error = $this->skills->last_error;
			return $result;
		}
		return parent::insert( $table, $data, $format );
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		if ( $this->is_skill_text( (string) $table ) ) {
			return $this->skills->update( (string) $table, (array) $data, (array) $where, $format, $where_format );
		}
		return parent::update( $table, $data, $where, $format, $where_format );
	}

	public function delete( $table, $where, $where_format = null ) {
		if ( $this->is_skill_text( (string) $table ) ) {
			return $this->skills->delete( (string) $table, (array) $where, $where_format );
		}
		return parent::delete( $table, $where, $where_format );
	}

	public function get_row( $query = null, $output = 'OBJECT', $y = 0 ) {
		if ( $this->is_skill_text( (string) $query ) ) {
			return $this->skills->get_row( (string) $query, (string) $output );
		}
		return parent::get_row( $query, $output, $y );
	}

	public function get_results( $query = null, $output = 'OBJECT' ) {
		if ( $this->is_skill_text( (string) $query ) ) {
			return $this->skills->get_results( (string) $query, (string) $output );
		}
		return parent::get_results( $query, $output );
	}

	public function get_var( $query = null, $x = 0, $y = 0 ) {
		if ( $this->is_skill_text( (string) $query ) ) {
			return $this->skills->get_var( (string) $query );
		}
		return parent::get_var( $query, $x, $y );
	}

	public function query( $query ) {
		$text = strtoupper( trim( (string) $query ) );
		if ( $this->is_skill_text( (string) $query ) || str_starts_with( $text, 'START TRANSACTION' ) || str_starts_with( $text, 'COMMIT' ) || str_starts_with( $text, 'ROLLBACK' ) ) {
			return $this->skills->query( (string) $query );
		}
		return parent::query( $query );
	}

	private function is_skill_text( string $text ): bool {
		return str_contains( $text, 'stonewright_skill' );
	}
}
