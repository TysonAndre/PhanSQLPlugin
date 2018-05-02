<?php

function test() : string {
    $ora = new Ora();
    $rows = $ora->getSelectRows('SELECT foo as bar, other, COUNT(*) FROM sometable where uid = :uid', ['uid' => 2]);
    return $rows;
}
