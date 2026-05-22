<?php
// FIXTURE: Closure::bindTo() instance method form, equivalent to Closure::bind.

$c = function () {};
$c->bindTo( $this, 'PrivateClass' );
