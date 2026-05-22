<?php
// FIXTURE: dynamic dispatch via array subscript on a local variable.

$arr = [ 'fn' => 'shell_exec' ];
$arr['fn']( 'ls' );
