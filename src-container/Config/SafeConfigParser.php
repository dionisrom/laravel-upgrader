<?php

declare(strict_types=1);

namespace AppContainer\Config;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

/**
 * Safely parses a Laravel config PHP file into an array WITHOUT executing it.
 *
 * Uses nikic/php-parser to extract the returned array from:
 *   <?php return [...];
 *
 * Handles static values (strings, ints, booleans, null, arrays) and
 * common Laravel patterns like env('KEY', 'default') by resolving to the default.
 * Expressions that cannot be statically evaluated are stored as a sentinel string.
 */
final class SafeConfigParser
{
    private const UNPARSEABLE = '__UPGRADER_UNPARSEABLE__';

    /**
     * Parse a config file and return its array representation.
     *
     * @return array<string, mixed>
     * @throws \RuntimeException if the file cannot be parsed or doesn't return an array
     */
    public function parse(string $filePath): array
    {
        $code = file_get_contents($filePath);
        if ($code === false) {
            throw new \RuntimeException("Cannot read config file: {$filePath}");
        }

        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 2));
        $stmts = $parser->parse($code);

        if ($stmts === null) {
            throw new \RuntimeException("Failed to parse config file: {$filePath}");
        }

        $returnExpr = $this->findReturnExpression($stmts);
        if ($returnExpr === null) {
            throw new \RuntimeException("Config file does not contain a return statement: {$filePath}");
        }

        $result = $this->evaluateExpr($returnExpr);
        if (!is_array($result)) {
            throw new \RuntimeException("Config file does not return an array: {$filePath}");
        }

        /** @var array<string, mixed> $result */
        return $result;
    }

    /**
     * Find the first top-level return statement's expression.
     *
     * @param Node\Stmt[] $stmts
     */
    private function findReturnExpression(array $stmts): ?Expr
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Return_ && $stmt->expr !== null) {
                return $stmt->expr;
            }
        }
        return null;
    }

    /**
     * Statically evaluate an AST expression to a PHP value.
     */
    private function evaluateExpr(Expr $expr): mixed
    {
        // Array
        if ($expr instanceof Expr\Array_) {
            return $this->evaluateArray($expr);
        }

        // Scalar string
        if ($expr instanceof Scalar\String_) {
            return $expr->value;
        }

        // Scalar integer
        if ($expr instanceof Scalar\Int_) {
            return $expr->value;
        }

        // Scalar float
        if ($expr instanceof Scalar\Float_) {
            return $expr->value;
        }

        // true/false/null constants
        if ($expr instanceof Expr\ConstFetch) {
            $name = strtolower($expr->name->toString());
            return match ($name) {
                'true' => true,
                'false' => false,
                'null' => null,
                default => self::UNPARSEABLE,
            };
        }

        // Function calls: env(), storage_path(), base_path(), etc.
        if ($expr instanceof Expr\FuncCall) {
            return $this->evaluateFuncCall($expr);
        }

        // String concatenation
        if ($expr instanceof Expr\BinaryOp\Concat) {
            $left = $this->evaluateExpr($expr->left);
            $right = $this->evaluateExpr($expr->right);
            if (is_string($left) && is_string($right)) {
                return $left . $right;
            }
            return self::UNPARSEABLE;
        }

        // Unary minus (negative numbers)
        if ($expr instanceof Expr\UnaryMinus) {
            $val = $this->evaluateExpr($expr->expr);
            if (is_int($val) || is_float($val)) {
                return -$val;
            }
            return self::UNPARSEABLE;
        }

        // Class constant fetch (e.g. Monolog\Logger::DEBUG)
        if ($expr instanceof Expr\ClassConstFetch) {
            return self::UNPARSEABLE;
        }

        return self::UNPARSEABLE;
    }

    private function evaluateArray(Expr\Array_ $expr): array
    {
        $result = [];
        foreach ($expr->items as $item) {
            /** @phpstan-ignore identical.alwaysFalse */
            if ($item === null) {
                continue;
            }

            $value = $this->evaluateExpr($item->value);

            if ($item->key !== null) {
                $key = $this->evaluateExpr($item->key);
                if (is_string($key) || is_int($key)) {
                    $result[$key] = $value;
                }
            } else {
                $result[] = $value;
            }
        }
        return $result;
    }

    /**
     * Evaluate common Laravel helper function calls.
     * env('KEY', 'default') → returns the default value.
     */
    private function evaluateFuncCall(Expr\FuncCall $expr): mixed
    {
        if (!$expr->name instanceof Node\Name) {
            return self::UNPARSEABLE;
        }

        $funcName = strtolower($expr->name->toString());
        $args = $expr->args;

        return match ($funcName) {
            'env' => $this->evaluateEnvCall($args),
            'storage_path' => $this->evaluatePathCall('/storage', $args),
            'base_path' => $this->evaluatePathCall('', $args),
            'public_path' => $this->evaluatePathCall('/public', $args),
            'resource_path' => $this->evaluatePathCall('/resources', $args),
            'database_path' => $this->evaluatePathCall('/database', $args),
            'config_path' => $this->evaluatePathCall('/config', $args),
            default => self::UNPARSEABLE,
        };
    }

    /**
     * env('KEY', default) → check real env, else return default arg, else null.
     *
     * @param array<Node\Arg|Node\VariadicPlaceholder> $args
     */
    private function evaluateEnvCall(array $args): mixed
    {
        if (count($args) === 0 || !($args[0] instanceof Node\Arg)) {
            return self::UNPARSEABLE;
        }

        $keyExpr = $args[0]->value;
        $key = $this->evaluateExpr($keyExpr);
        if (!is_string($key)) {
            return self::UNPARSEABLE;
        }

        // Check actual environment first
        $envValue = getenv($key);
        if ($envValue !== false) {
            return $envValue;
        }

        // Return default if provided
        if (count($args) >= 2 && $args[1] instanceof Node\Arg) {
            return $this->evaluateExpr($args[1]->value);
        }

        return null;
    }

    /**
     * @param array<Node\Arg|Node\VariadicPlaceholder> $args
     */
    private function evaluatePathCall(string $base, array $args): string
    {
        if (count($args) >= 1 && $args[0] instanceof Node\Arg) {
            $sub = $this->evaluateExpr($args[0]->value);
            if (is_string($sub) && $sub !== '') {
                return $base . '/' . $sub;
            }
        }
        return $base;
    }
}
