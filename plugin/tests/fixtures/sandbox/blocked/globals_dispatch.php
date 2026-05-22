<?php
// FIXTURE: dynamic dispatch via $GLOBALS[...](...). The variable is followed
// by a subscript, then a call — invisible to a "T_VARIABLE directly followed
// by (" detector.

$GLOBALS["exec"]( 'id' );
