<?php

$user = new stdClass();
$this->assertDeleted($user);
-----
<?php

$user = new stdClass();
$this->assertModelMissing($user);
