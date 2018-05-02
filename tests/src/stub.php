<?php

/**
 * Placeholder class definition for the OraResult plugin
 * @template RowT (an array shape)
 */
class TemplateOraResult extends OraResult {
    /**
     * @param RowT $result
     */
    public function __construct($result) {
        throw new RuntimeException("Not implemented");
    }

    /**
     * @return RowT
     * @suppress PhanTypeMismatchReturn
     * @suppress PhanParamSignatureMismatch
     */
    public function getResults() {
        return parent::getResults();
    }
}
