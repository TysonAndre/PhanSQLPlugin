<?php declare(strict_types=1);

use Phan\AST\ContextNode;
use Phan\AST\UnionTypeVisitor;
use Phan\CodeBase;
use Phan\Issue;
use Phan\Language\Context;
use Phan\Language\Element\Method;
use Phan\Language\Type\ArrayShapeType;
use Phan\Language\UnionType;
use Phan\PluginV2;
use Phan\PluginV2\AnalyzeFunctionCallCapability;
use Phan\PluginV2\ReturnTypeOverrideCapability;

use PhpMyAdmin\SqlParser\Lexer;
use PhpMyAdmin\SqlParser\Parser;

// require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Checks of uses of databases.
 *
 * This uses Phan's code style
 *
 * @see DollarDollarPlugin for generic plugin documentation.
 */
class PhanSQLPlugin extends PluginV2 implements
    AnalyzeFunctionCallCapability,
    ReturnTypeOverrideCapability
{

    // Issue types emitted by this plugin.
    const OraMissingBindVar = 'PhanPluginOraMissingBindVar';
    const OraUnexpectedBindVar = 'PhanPluginOraUnexpectedBindVar';

    private function generateParamAnalyzer(int $sql_param_index, int $bind_vars_param_index) : Closure
    {
        return function(CodeBase $code_base, Context $context, Method $unused_method, array $args) use ($sql_param_index, $bind_vars_param_index) : void
        {
            // E.g. a class constant or a literal string (or a concatenation?)
            $sql_node = $args[$sql_param_index] ?? null;
            if (is_null($sql_node)) {
                return;
            }
            // $bind_vars_node is an array AST or a variable node pointing to an array. This does not attempt to analyze uses of OraBindVars instances
            $bind_vars_node = $args[$bind_vars_param_index] ?? null;
            if (is_null($bind_vars_node)) {
                return;
            }
            // Try to find the actual SQL used
            $sql_raw = (new ContextNode($code_base, $context, $sql_node))->getEquivalentPHPValue();
            // $sql_raw is conventionally the empty string if accessed as static::sql_overridden.
            if (!is_string($sql_raw) || $sql_raw === '') {
                return;
            }

            // Try to find the keys of the array literal, at least for array literals with 100% known keys.
            $bind_vars_union_type = UnionTypeVisitor::unionTypeFromNode($code_base, $context, $bind_vars_node);
            if (!$bind_vars_union_type->hasTopLevelArrayShapeTypeInstances()) {
                return;
            }
            if ($bind_vars_union_type->hasTopLevelNonArrayShapeTypeInstances()) {
                // Reduce false positives: don't analyze array{key:string}|array
                return;
            }

            // From the information Phan has about bind variables and the sql literal, guess the expected and actual bind variables used.
            $expected_bind_vars = self::extractExpectedBindVars($sql_raw);
            $actual_bind_vars = self::extractActualBindVarsFromArrayShapeTypes($bind_vars_union_type);
            $this->compareBindVars($code_base, $context, $expected_bind_vars, $actual_bind_vars);
        };
    }

    /**
     * @param array<string,true> $bind_vars
     * @return array<string,string>
     */
    private function normalizeBindVars(array $bind_vars) : array
    {
        $result = [];
        foreach ($bind_vars as $bind_var_name => $_) {
            $result[strtolower($bind_var_name)] = $bind_var_name;
        }
        return $result;
    }

    private function compareBindVars(CodeBase $code_base, Context $context, array $expected_bind_vars, array $actual_bind_vars) : void
    {
        // Maps lowercase name to original name. Bind variables are case insensitive, apparently?
        $expected_bind_vars = $this->normalizeBindVars($expected_bind_vars);
        $actual_bind_vars = $this->normalizeBindVars($actual_bind_vars);

        // NOTE: Not worth it right now, due to 'DD-MON-YYYY HH:MM:SS' in date format strings triggering a lot of false positives
        foreach (array_diff_key($expected_bind_vars, $actual_bind_vars) as $missing_expected_key) {
            $this->emitIssue(
                $code_base,
                $context,
                self::OraMissingBindVar,
                'Missing bind var {STRING_LITERAL}. Actual bind vars: ({STRING_LITERAL})',
                [$missing_expected_key, implode(', ', array_keys($actual_bind_vars))],
                Issue::SEVERITY_NORMAL,
                Issue::REMEDIATION_B,
                16004
            );
        }
        foreach (array_diff_key($actual_bind_vars, $expected_bind_vars) as $unexpected_key) {
            $this->emitIssue(
                $code_base,
                $context,
                self::OraUnexpectedBindVar,
                'Unexpected bind var {STRING_LITERAL}. Expected bind vars: ({STRING_LITERAL})',
                [$unexpected_key, implode(', ', array_keys($expected_bind_vars))],
                Issue::SEVERITY_NORMAL,
                Issue::REMEDIATION_B,
                16004
            );
        }
    }

    /**
     * Converts UnionType representing array{key:T} to an array literal `['key' => true]`
     * @return array<string,true>
     */
    private static function extractActualBindVarsFromArrayShapeTypes(UnionType $bind_vars_union_type) : array
    {
        $name_set = [];
        foreach ($bind_vars_union_type->getTypeSet() as $type) {
            if (!($type instanceof ArrayShapeType)) {
                continue;
            }
            foreach ($type->getFieldTypes() as $key => $_) {
                if (substr($key, 0, 1) !== ':') {
                    $key = ":$key";
                }
                $name_set[$key] = true;
            }
        }
        return $name_set;
    }

    /**
     * @return array<string,true>
     */
    private function extractExpectedBindVars(string $sql_raw) : array
    {
        // Hackish way to reduce false positives inside of date format strings...
        // TODO: Use an actual SQL tokenizer?
        $sql_raw = preg_replace("/'[^']+'/", "''", $sql_raw);

        preg_match_all('/:[a-zA-Z_0-9]+/', $sql_raw, $matches);
        $match_set = [];
        foreach ($matches[0] as $bind_var) {
            $match_set[$bind_var] = true;
        }
        return $match_set;
    }

    /**
     * @phan-override
     */
    public function getAnalyzeFunctionCallClosures(CodeBase $unused_codebase) : array
    {
        $exec_sql_analyzer = $this->generateParamAnalyzer(0, 1);
        $exec_limit_sql_analyzer = $this->generateParamAnalyzer(0, 3);

        return [
            '\\Ora::execLimitSql' => $exec_limit_sql_analyzer,
            '\\Ora::execSql' => $exec_sql_analyzer,
            '\\Ora::getSelectRows' => $exec_sql_analyzer,
        ];
    }


    public function generateReturnAnalyzer(int $sql_param_index) : \Closure
    {
        $parser = new Parser();
        return function(CodeBase $code_base, Context $context, Method $unused_method, array $args) use ($sql_param_index) : UnionType
        {
            // TODO: Make this generalizable
            $default_type = UnionType::fromFullyQualifiedString('array<int,string>|false');

            // E.g. a class constant or a literal string (or a concatenation?)
            $sql_node = $args[$sql_param_index] ?? null;
            if (is_null($sql_node)) {
                return $default_type;
            }
            // Try to find the actual SQL used
            $sql_raw = (new ContextNode($code_base, $context, $sql_node))->getEquivalentPHPValue();
            // $sql_raw could be the empty string if accessed as static::sql_overridden.
            if (!is_string($sql_raw) || $sql_raw === '') {
                return $default_type;
            }

            // TODO: Move overly specific code into a subclass
            $sql_raw = preg_replace('/\{\{[pt]key\}\}/i', '', $sql_raw);

            // if token is :\w+, then the variable is actually a bind var.
            // $tokens = (new Lexer($sql_raw))->tokens

            $parser = new Parser($sql_raw);
            //var_dump($parser->statements);
            //var_dump((new Lexer($sql_raw))->list);

            return $default_type;
        };
    }

    /**
     * @phan-override
     */
    public function getReturnTypeOverrides(CodeBase $unused_codebase) : array
    {
        $analyzer = $this->generateReturnAnalyzer(0);

        return [
            '\\Ora::execLimitSql' => $analyzer,
            '\\Ora::execSql' => $analyzer,
            '\\Ora::getSelectRows' => $analyzer,
        ];
    }
}

// Every plugin needs to return an instance of itself at the
// end of the file in which its defined.
return new PhanSQLPlugin();
