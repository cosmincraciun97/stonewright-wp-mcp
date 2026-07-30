<?php
// FIXTURE: call_user_func with variable callable.

$cb = 'strtoupper';
call_user_func( $cb, 'value' );
