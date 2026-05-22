<?php
// FIXTURE: fopen with read-only mode is allowed.

$h = fopen( '/var/www/config.txt', 'r' );
