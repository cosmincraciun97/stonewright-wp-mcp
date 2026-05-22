<?php
// FIXTURE: fully-qualified eval — PHP emits T_NAME_FULLY_QUALIFIED, not T_EVAL,
// for "\eval", so the eval-token-only scan misses it.

\eval( 'echo 1;' );
