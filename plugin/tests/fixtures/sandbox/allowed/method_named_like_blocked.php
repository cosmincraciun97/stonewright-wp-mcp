<?php
// FIXTURE: method/static call/namespace ref that share a name with a blocked
// builtin must NOT trigger the guard.

$obj->unlink( 'file.txt' );
Cleanup::rename( 'a', 'b' );
\App\copy( 'x', 'y' );
function my_chmod( $p, $m ) {
	return $p . $m;
}
