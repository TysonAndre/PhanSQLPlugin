<?php
$ora = new Ora();
$ora->execSql('SELECT * FROM example where foo = :bar', ['baz' => 'x']);
