<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later
// Derived from tests/oauth/AuthorizeRepairTest.php
// Source SHA-256: 2e74bce88a2216d49f50a7cec956b1340c07d5bfdac89b7c4948beb99f4a3c48

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\OAuth\Endpoints\Authorize;

final class AuthorizeRepairTest extends TestCase {

	protected function tearDown(): void {
		unset(
			$_GET['page'],
			$_GET['response_type'],
			$_GET['client_id'],
			$_SERVER['REQUEST_URI'],
			$_SERVER['QUERY_STRING'],
			$GLOBALS['stonewright_test_is_admin']
		);
	}

	public function test_repairs_folded_authorization_query(): void {
		$_GET['page']               = 'stonewright-oauth-authorize?response_type=code';
		$_GET['client_id']          = 'abc';
		$_SERVER['REQUEST_URI']     = '/wp-admin/admin.php?page=stonewright-oauth-authorize?response_type=code&client_id=abc';
		$_SERVER['QUERY_STRING']    = 'page=stonewright-oauth-authorize?response_type=code&client_id=abc';

		Authorize::repair_folded_request();

		self::assertSame( 'stonewright-oauth-authorize', $_GET['page'] );
		self::assertSame( 'code', $_GET['response_type'] );
		self::assertSame( 'abc', $_GET['client_id'] );
		self::assertStringContainsString( 'authorize&response_type', (string) $_SERVER['REQUEST_URI'] );
	}

	public function test_leaves_clean_or_non_admin_requests_untouched(): void {
		$_GET['page'] = 'stonewright-oauth-authorize';
		Authorize::repair_folded_request();
		self::assertSame( 'stonewright-oauth-authorize', $_GET['page'] );

		$GLOBALS['stonewright_test_is_admin'] = false;
		$_GET['page'] = 'stonewright-oauth-authorize?response_type=code';
		Authorize::repair_folded_request();
		self::assertSame( 'stonewright-oauth-authorize?response_type=code', $_GET['page'] );
	}
}
