<?php
// FIXTURE: Closure::bind with arbitrary scope.

$bound = Closure::bind( $closure, $scope, 'SomeClass' );
