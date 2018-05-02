<?php

class Ora {
    /**
     * @return array<int,array<string,mixed>>|false
     */
    public function execSql($sql, $bindVars = []) {
        throw new InvalidArgumentException("Not implemented");
    }

    /**
     * @return array<int,array<string,mixed>>|false
     */
    public function getSelectRows($sql, $bindVars = []) {
        throw new InvalidArgumentException("Not implemented");
    }
}
