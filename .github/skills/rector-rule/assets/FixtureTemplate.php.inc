<?php

// INPUT: Code that should be transformed (before)
// Replace with the actual real-world code pattern that needs transforming.

$result = $this->oldMethodName($argument);

?>
-----
<?php

// EXPECTED OUTPUT: Code after transformation
// Replace with exactly what Rector should produce.

$result = $this->newMethodName($argument);
