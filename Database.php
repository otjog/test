<?php

namespace FpDbTest;

use Exception;
use mysqli;

class Database implements DatabaseInterface
{
    private mysqli $mysqli;

    private array $strictPatternRules;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;

        $this->strictPatternRules = $this->getStrictPatternRules();
    }

    /**
     * @throws Exception
     */
    public function buildQuery(string $query, array $args = []): string
    {
        $result = $this->strictHandler($query, $args);

        return $this->conditionalHandler($result);
    }

    /**
     * @throws Exception
     */
    private function strictHandler(string $query, array $args): string
    {
        return preg_replace_callback($this->getStrictPatternString(), function($match) use (&$args) {

            if (count($args) > 0) {
                $value = array_shift($args);
            } else {
                throw new Exception('Too few parameters');
            }

            $handler = $this->strictPatternRules[$match[0]];

            return $this->skipSymbolHandler($handler, $value);
        }, $query);
    }

    /**
     * @throws Exception
     */
    private function conditionalHandler(string $query, bool $isNestedCheck = false): string
    {
        return preg_replace_callback($this->getConditionalPatternString(), function ($matches) use ($isNestedCheck) {
            $str = rtrim($matches[1]);

            $nestedBlock = $this->conditionalHandler($matches[1], true);

            if($isNestedCheck && $nestedBlock)
                throw new Exception('A conditional block has nested elements');

            return (str_contains($str, $this->getSkipSymbol())) ? '' : $str;
        }, $query);
    }

    public function skip(): string
    {
        return $this->getSkipSymbol();
    }

    private function getStrictPatternRules(): array
    {
        return [
            '?d' => 'decimalHandler',
            '?f' => 'floatHandler',
            '?a' => 'arrayHandler',
            '?#' => 'identifierHandler',
            '?'  => 'defaultHandler',
            '(?a)' =>'arrayHandler'
        ];
    }

    private function getStrictPatternString(): string
    {
        $patternArray = array_keys($this->strictPatternRules);

        return '/(' . implode('|', array_map('preg_quote', $patternArray)) . ')/';
    }

    private function getConditionalPatternString(): string
    {
        return '/\{([^{}]*(?:(?R)[^{}]*)*)\}/';
    }

    private function getSkipSymbol(): string
    {
        return '!?';
    }

    /**
     * @throws Exception
     */
    private function defaultHandler($value): int|string|null|float
    {
        return match (gettype($value)) {
            'boolean'   => $this->booleanHandler($value),
            'string'    => $this->stringHandler($value),
            'NULL'      => $this->nullHandler(),
            'integer', 'double' => $value,
            default => throw new Exception('Invalid data type'),
        };
    }

    private function booleanHandler(bool $value): int
    {
        return (int)$value;
    }

    private function stringHandler(string $value): string
    {
        return "'" . $this->mysqli->real_escape_string($value) . "'";
    }

    private function nullHandler(): string
    {
        return "NULL";
    }

    private function decimalHandler($value): string|int
    {
        return is_null($value) ? $this->nullHandler() : (int)$value;
    }

    private function floatHandler($value): string|float
    {
        return is_null($value) ? $this->nullHandler() : (int)$value;
    }

    /**
     * @throws Exception
     */
    private function identifierHandler(string|array $value): string
    {
        if (is_string($value)) {
            return "`" . $this->mysqli->real_escape_string($value) . "`";
        }

        if (is_array($value)) {
            $formattedValues = array_map([$this, 'identifierHandler'], $value);
            return $this->formattedValuesToString($formattedValues);
        }

        throw new Exception('Invalid data type');
    }

    /**
     * @throws Exception
     */
    private function arrayHandler(array $values): string
    {
        return $this->isAssociativeArray($values)
            ? $this->associativeArrayHandler($values)
            : $this->sequentialArrayHandler($values);
    }

    private function skipSymbolHandler($handler, $value)
    {
        return ($value !== $this->getSkipSymbol()) ? $this->{$handler}($value) : $value;
    }

    /**
     * @throws Exception
     */
    private function associativeArrayHandler(array $values): string
    {
        $formattedValues = [];

        foreach ($values as $key => $value) {
            $formattedValues[] = $this->identifierHandler($key) . " = " . $this->defaultHandler($value);
        }

        return $this->formattedValuesToString($formattedValues);
    }

    /**
     * @throws Exception
     */
    private function sequentialArrayHandler(array $values): string
    {
        $formattedValues = [];

        foreach ($values as $value) {
            $formattedValues[] = $this->defaultHandler($value);
        }

        return '(' . $this->formattedValuesToString($formattedValues) . ')';
    }

    private function isAssociativeArray(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }

    private function formattedValuesToString(array $array): string
    {
        return implode(', ', $array);
    }
}
