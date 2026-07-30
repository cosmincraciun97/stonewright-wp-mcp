<?php
// FIXTURE: call_user_func_array with variable callable.

$cb = 'strtoupper';
call_user_func_array( $cb, [ 'value' ] );
