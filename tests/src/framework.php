<?php

class Ora {
    /**
     * @return OraResult
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

class OraResult {
    /**
     * @return array<int,array> results of the query
     */
    public function getResults() {
        throw new InvalidArgumentException("Not implemented");
    }
}
