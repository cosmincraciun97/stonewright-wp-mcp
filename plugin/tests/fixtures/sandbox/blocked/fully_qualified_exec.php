<?php
// FIXTURE: fully-qualified call to a blocked function.
// PHP emits T_NAME_FULLY_QUALIFIED for the leading-backslash form, which is
// invisible to the T_STRING-only scan. The guard must catch this anyway.

\exec( 'id' );
