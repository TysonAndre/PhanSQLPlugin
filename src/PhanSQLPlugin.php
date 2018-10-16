<?php declare(strict_types=1);

use Phan\AST\ContextNode;
use Phan\AST\UnionTypeVisitor;
use Phan\CodeBase;
use Phan\Issue;
use Phan\Language\Context;
use Phan\Language\Element\Method;
use Phan\Language\Type;
use Phan\Language\Type\ArrayShapeType;
use Phan\Language\Type\GenericArrayType;
use Phan\Language\Type\NullType;
use Phan\Language\UnionType;
use Phan\PluginV2;
use Phan\PluginV2\AnalyzeFunctionCallCapability;
use Phan\PluginV2\ReturnTypeOverrideCapability;

//use PhpMyAdmin\SqlParser\Lexer;
use PhpMyAdmin\SqlParser\Exceptions\LexerException;
use PhpMyAdmin\SqlParser\Exceptions\LoaderException;
use PhpMyAdmin\SqlParser\Exceptions\ParserException;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;

/**
 * This plugin analyzes the syntax of our SQL queries targeting Oracle databases.
 * (it checks Ora->execSql(), Ora->execLimitSql(), and Ora->getSelectRows())
 * - This does not analyze semantics, it does not know what tables or columns or procedures there are.
 * - This can catch mismatched brackets, missing keywords, etc.
 * - This has false positives in a small percentage of cases involving rarely used syntax and Oracle-specific syntax.
 *   (The parser library used only supports MySQL)
 * - It assumes that the code has {{pkey}} and {{tkey} as templates.
 * - It assumes that strings can be replaced with the empty string.
 *
 * This uses Phan's code style.
 *
 * This uses PhpMyAdmin\SqlParser, which is an open-source parser for MySQL SQL statements.
 * - This has some heuristics to analyze Oracle statements.
 *   FIXME: Make this configurable or have multiple subclasses
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
    const OraSqlSyntaxError = 'PhanPluginOraSqlSyntaxError';

    private function generateParamAnalyzer(int $sql_param_index, int $bind_vars_param_index) : Closure
    {
        // @phan-suppress-next-line PhanUnreferencedClosure
        return function (CodeBase $code_base, Context $context, Method $unused_method, array $args) use ($sql_param_index, $bind_vars_param_index) : void {
            // E.g. a class constant or a literal string (or a concatenation?)
            $sql_node = $args[$sql_param_index] ?? null;
            if (is_null($sql_node)) {
                return;
            }
            // Try to find the actual SQL used
            $sql_raw = (new ContextNode($code_base, $context, $sql_node))->getEquivalentPHPValue();
            // $sql_raw is conventionally the empty string if accessed as static::sql_overridden.
            if (!is_string($sql_raw) || $sql_raw === '') {
                return;
            }
            $this->checkForSQLSyntaxErrors($code_base, $context, $this->normalizeSQL($sql_raw));

            // $bind_vars_node is an array AST or a variable node pointing to an array. This does not attempt to analyze uses of OraBindVars instances
            $bind_vars_node = $args[$bind_vars_param_index] ?? null;
            if (is_null($bind_vars_node)) {
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

    private function checkForSQLSyntaxErrors(CodeBase $code_base, Context $context, string $sql_raw)
    {
        try {
            new Parser($sql_raw, /* strict = */ true);
        } catch (ParserException | LexerException | LoaderException $e) {
            $this->emitIssue(
                $code_base,
                $context,
                self::OraSqlSyntaxError,
                'Failed parsing sql {STRING_LITERAL}. {STRING_LITERAL}: ({STRING_LITERAL}) at "{STRING_LITERAL}"',
                [
                    json_encode($sql_raw),
                    get_class($e),
                    $e->getMessage(),
                    isset($e->token) ? $e->token->getInlineToken() : ($e->name ?? $e->ch ?? 'unknown'),
                ],
                Issue::SEVERITY_NORMAL,
                Issue::REMEDIATION_B,
                16005
            );
        }
    }

    /**
     * This aims to catch accidental typos in common SQL statements such as SELECTs, INSERTs, etc.
     *
     * This doesn't work well with oracle-specific features
     */
    private function checkForSQLSyntaxErrors(CodeBase $code_base, Context $context, string $sql_raw)
    {
        if (preg_match('/(^\s*begin\b)|\bRETURNING\b|\bover\(\)/i', $sql_raw) > 0) {
            // Give up on stored procedures
            return;
        }
        try {
            new Parser($sql_raw, /* strict = */ true);
        } catch (ParserException | LexerException | LoaderException $e) {
            $invalidToken = isset($e->token) ? $e->token->getInlineToken() : ($e->name ?? $e->ch ?? 'unknown');
            if (in_array(strtoupper($invalidToken), ['MERGE', 'RETURNING', 'FETCH', 'START', 'WITH', 'STD', 'KEY', 'UNION ALL'])) {
                // False positives involving keywords, usually
                return;
            }

            $this->emitIssue(
                $code_base,
                $context,
                self::OraSqlSyntaxError,
                'Failed parsing sql {STRING_LITERAL}. {STRING_LITERAL}: ({STRING_LITERAL}) at "{STRING_LITERAL}"',
                [
                    json_encode($sql_raw),
                    get_class($e),
                    $e->getMessage(),
                    $invalidToken,
                ],
                Issue::SEVERITY_NORMAL,
                Issue::REMEDIATION_B,
                16005
            );
        }
    }

    /**
     * @param array<string,true> $bind_vars
     * @return array<string,string>
     */
    private function normalizeBindVars(array $bind_vars) : array
    {
        $result = [];
        foreach ($bind_vars as $bind_var_name => $_) {
            $result[strtolower((string)$bind_var_name)] = $bind_var_name;
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
                if (is_string($key) && substr($key, 0, 1) !== ':') {
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
        if (!class_exists(Parser::class)) {
            // We need the SQL Parser library to be part of the Phan installation for parts of param analysis to work.
            // The Parser library won't be available by default for VS Code plugins, vim, other IDEs with Phan extensions.
            // - They have a different phan installation.
            // This will warn repeatedly in daemon mode due to plugins being created after forking, but that should be fine.
            fprintf(STDERR, "%s not found, %s will not analyze function calls of sql" . PHP_EOL, Parser::class, self::class);
            return [];
        }
        $exec_sql_analyzer = $this->generateParamAnalyzer(0, 1);
        $exec_limit_sql_analyzer = $this->generateParamAnalyzer(0, 3);

        return [
            '\\Ora::execLimitSql' => $exec_limit_sql_analyzer,
            '\\Ora::execSql' => $exec_sql_analyzer,
            '\\Ora::getSelectRows' => $exec_sql_analyzer,
        ];
    }


    /**
     * @param ?Closure(UnionType):UnionType $transform_array_shape
     * @suppress PhanTypeMismatchDeclaredParamNullable https://github.com/phan/phan/issues/1663
     */
    public function generateReturnAnalyzer(int $sql_param_index, UnionType $default_types, Closure $transform_array_shape = null) : \Closure
    {
        // @phan-suppress-next-line PhanUnreferencedClosure
        return function (CodeBase $code_base, Context $context, Method $method, array $args) use ($sql_param_index, $default_types, $transform_array_shape) : UnionType {
            // E.g. a class constant or a literal string (or a concatenation?)
            $sql_node = $args[$sql_param_index] ?? null;
            if (is_null($sql_node)) {
                return $method->getUnionType();
            }
            // Try to find the actual SQL used
            $sql_raw = (new ContextNode($code_base, $context, $sql_node))->getEquivalentPHPValue();
            // $sql_raw could be the empty string if accessed as static::sql_overridden.
            if (!is_string($sql_raw) || $sql_raw === '') {
                return $method->getUnionType();
            }

            // TODO: Move overly specific code into a subclass

            // if token is :\w+, then the variable is actually a bind var.
            // $tokens = (new Lexer($sql_raw))->tokens
            $override_type = $this->getReturnTypeForSQL($sql_raw, $default_types);
            if ($override_type !== null) {
                if ($transform_array_shape !== null) {
                    $override_type = $transform_array_shape($override_type);
                }
                return $override_type;
            }

            //var_dump((new Lexer($sql_raw))->list);

            return $method->getUnionType();
        };
    }

    /**
     * @return ?UnionType
     */
    protected function getReturnTypeForSQL(string $sql_raw, UnionType $default_types)
    {
        $sql_raw = $this->normalizeSQL($sql_raw);
        $parser = new Parser($sql_raw);
        if (count($parser->statements) !== 1) {
            return null;
        }
        $statement = $parser->statements[0];
        if ($statement instanceof SelectStatement) {
            $exprs = $statement->expr;
            $field_types = [];
            static $mixed_union_type;
            if ($mixed_union_type === null) {
                $mixed_union_type = UnionType::fromFullyQualifiedString('mixed');
            }
            foreach ($exprs as $expr) {
                $alias = strtolower($expr->alias ?? $expr->column ?? $expr->expr ?? '');
                if (preg_match('/^\([^()]+\)$/', $alias)) {
                    $alias = substr($alias, 1, -1);
                }
                if ($alias === '*') {
                    // `SELECT * from table WHERE cond` has unknown columns
                    return null;
                }
                if (in_array($alias, ['over', 'keep', 'trunc'])) {
                    // - sql-parser improperly parses `count(*) over()` as a column with name 'over'
                    // - and `SELECT x1, max(x2) KEEP (DENSE_RANK LAST ORDER BY x3) AS desired_column WHERE ... GROUP BY x4`
                    // - and TRUNC()
                    // TODO: Check if oracle specific, file a bug if oracle support is feasible
                    return null;
                }

                if (!$alias) {
                    return null;
                }
                // TODO: Infer a type based on knowledge of functions, tables, etc.
                // E.g. StringType (can the result be null?)
                $field_types[$alias] = $mixed_union_type;
            }
            $shape_type = ArrayShapeType::fromFieldTypes($field_types, false);
            return $default_types->withType(
                GenericArrayType::fromElementType($shape_type, false, GenericArrayType::KEY_INT)
            );
        }
    }

    protected function normalizeSQL(string $sql_raw) : string
    {
        // Strip out {{pkey}} and {{tkey}} from table names
        $sql = preg_replace('/\{\{[pt]key\}\}/i', '', $sql_raw);
        // Strip out strings to avoid false positive bind vars from hh:mm:ss
        $sql = preg_replace("/'[^']+'/", "''", $sql);

        $sql = preg_replace('/:([a-zA-Z_0-9]+)/', 'BINDVAR_\1', $sql);

        return $sql;
    }

    /**
     * @phan-override
     */
    public function getReturnTypeOverrides(CodeBase $unused_codebase) : array
    {
        if (!class_exists(Parser::class)) {
            // We need the sql parser library to be part of the Phan installation for the plugin to work.
            // This might not work for vs code plugins, etc.
            // This will warn repeatedly in daemon mode due to pcntl being used, but that should be fine
            fprintf(STDERR, "%s not found, %s will not analyze return statements of sql" . PHP_EOL, Parser::class, self::class);
            return [];
        }
        $null_union_type = NullType::instance(false);
        $analyzer = $this->generateReturnAnalyzer(0, $null_union_type->asUnionType(), null);
        $exec_analyzer = $this->generateReturnAnalyzer(0, UnionType::empty(), function (UnionType $shape_type) {
            // transform array{foo:mixed} to \TemplateOraResult<array{foo:mixed}>
            $t = Type::fromFullyQualifiedString('\TemplateOraResult');
            return Type::fromType($t, [$shape_type])->asUnionType();
        });

        return [
            // return objects
            '\\Ora::execSql' => $exec_analyzer,

            // return arrays
            '\\Ora::execLimitSql' => $analyzer,
            '\\Ora::getSelectRows' => $analyzer,
        ];
    }
}

// Every plugin needs to return an instance of itself at the
// end of the file in which its defined.
return new PhanSQLPlugin();
