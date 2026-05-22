<?php
// FIXTURE: call_user_func with an inline closure.

call_user_func( function ( $x ) {
	return $x * 2;
}, 21 );
