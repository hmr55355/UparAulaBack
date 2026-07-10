<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Evaluates simple arithmetic formulas used by grade_columns.custom_formula,
 * e.g. "(ev1 + ev2 + ev3) / 3", without resorting to eval().
 * Supports +, -, *, /, parentheses and decimal numbers.
 */
class FormulaEvaluator
{
    /**
     * @param  array<string, float|null>  $values  short_name => score (or null if ungraded)
     */
    public static function evaluate(string $formula, array $values): ?float
    {
        $tokens = self::tokenize($formula, $values);

        if (in_array(null, $tokens, true)) {
            return null;
        }

        $position = 0;

        return self::parseExpression($tokens, $position);
    }

    /**
     * @return array<int, string|float|null>
     */
    private static function tokenize(string $formula, array $values): array
    {
        preg_match_all('/[A-Za-z_][A-Za-z0-9_]*|\d+\.?\d*|[()+\-*\/]/', $formula, $matches);

        return array_map(function ($token) use ($values) {
            if (is_numeric($token)) {
                return (float) $token;
            }
            if (in_array($token, ['(', ')', '+', '-', '*', '/'], true)) {
                return $token;
            }
            if (! array_key_exists($token, $values)) {
                throw new InvalidArgumentException("Fórmula referencia una columna desconocida: {$token}");
            }

            return $values[$token];
        }, $matches[0]);
    }

    private static function parseExpression(array $tokens, int &$position): float
    {
        $value = self::parseTerm($tokens, $position);

        while (isset($tokens[$position]) && in_array($tokens[$position], ['+', '-'], true)) {
            $op = $tokens[$position++];
            $rhs = self::parseTerm($tokens, $position);
            $value = $op === '+' ? $value + $rhs : $value - $rhs;
        }

        return $value;
    }

    private static function parseTerm(array $tokens, int &$position): float
    {
        $value = self::parseFactor($tokens, $position);

        while (isset($tokens[$position]) && in_array($tokens[$position], ['*', '/'], true)) {
            $op = $tokens[$position++];
            $rhs = self::parseFactor($tokens, $position);
            $value = $op === '*' ? $value * $rhs : $value / $rhs;
        }

        return $value;
    }

    private static function parseFactor(array $tokens, int &$position): float
    {
        $token = $tokens[$position] ?? null;

        if ($token === '(') {
            $position++;
            $value = self::parseExpression($tokens, $position);
            $position++; // consume ')'

            return $value;
        }

        if ($token === '-') {
            $position++;

            return -self::parseFactor($tokens, $position);
        }

        $position++;

        return (float) $token;
    }
}
