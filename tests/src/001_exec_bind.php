<?php
$ora = new Ora();
$result = $ora->execSql("SELECT * FROM example{{pkey}} where foo = :bar AND x = 'HH:MM'", ['baz' => 'x']);
