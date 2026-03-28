<?php

$this->assertDeleted('users', ['email' => 'alice@example.com']);
$user = new stdClass();
$this->assertModelMissing($user);
