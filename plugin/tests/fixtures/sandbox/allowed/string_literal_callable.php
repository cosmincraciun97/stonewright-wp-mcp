<?php
// FIXTURE: call_user_func with a string literal callable name. Safe.

call_user_func( 'strtoupper', 'value' );
call_user_func_array( 'array_map', [ 'strtolower', [ 'A', 'B' ] ] );
