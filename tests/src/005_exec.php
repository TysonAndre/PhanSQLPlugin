<?php

function testExecSql() : string {
    $ora = new Ora();
    $rows = $ora->execSql('SELECT foo as bar, other, COUNT(*) FROM sometable where uid = :uid', ['uid' => 2]);
    return $rows->getResults();
}
