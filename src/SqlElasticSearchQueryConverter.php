<?php

namespace Santik\SqlElasticSearchQueryConverter;

use Ramsey\Uuid\Uuid;

class SqlElasticSearchQueryConverter
{
    private $operators = ['AND', 'OR'];
    private $parts = [];

    private function __construct(){}


    public static function convert(string $query):string
    {
        $convert = new SqlElasticSearchQueryConverter();
        return $convert->parse($query);
    }


    public function parse(string $query): string
    {
        $this->checkQueryNumberOfParentheses($query);

        $parsedQuery = $this->proccessOperators(
            $this->parseParentheses($query)
        );

        return json_encode($this->makeEsQuery($parsedQuery));
    }


    private function makeEsQuery(array $parsedSQLQuery): array
    {
        $parts = [];
        foreach ($parsedSQLQuery as $operator => $subqueries) {
            foreach ($subqueries as $subquery) {
                if (is_array($subquery)) {
                    $parts[] = $this->makeEsQuery($subquery);
                } else {
                    $parts[] = ['match' => $subquery];
                }
            }

            if ($operator == 'AND') {
                $esOperator = 'must';
            } else {
                $esOperator = 'should';
            }

            return ['bool'=> [$esOperator => $parts]];

        }
    }


    private function parseParentheses(string $query):string
    {
        $pattern = '/\((?:[^()]+|\(?R\))*\)/';

        preg_match_all($pattern, $query, $groups);
        if (!count($groups) || (count($groups) == 1 && empty($groups[0]))) {
            return $query;
        }
        foreach ($groups[0] as $group) {
            $id = Uuid::uuid4()->toString();
            $this->parts[$id] = $group;
            $query = str_replace($group, $id, $query);
        }

        return $this->parseParentheses($query);
    }


    private function checkQueryNumberOfParentheses(string $query)
    {
        $openParenthesesNumber = substr_count($query, '(');
        $closeParenthesesNumber = substr_count($query, ')');

        if ($openParenthesesNumber != $closeParenthesesNumber) {
            throw new \Exception('Parentheses are incorrect');
        }
    }


    private function proccessOperators(string $parsedQuery)
    {
        $operatedString = $this->extractOperatedString($parsedQuery);
        $hasOperator = false;
        $queryStructured = [];

        // supports only 1 operator in single parenthesis
        // like "option1 OR option2"
        // and not "option1 OR option2 AND option3"
        foreach ($this->operators as $operator) {
            $stringParts = explode($operator, $operatedString);
            if (count($stringParts) > 1) {
                $queryStructured[$operator] = $stringParts;
                $hasOperator = true;
                break;
            }
        }

        if (!$hasOperator) {
            return $operatedString;
        }

        foreach ($queryStructured[$operator] as $i => $part) {
            $processed = $this->proccessOperators(trim($part));
            $queryStructured[$operator][$i] = $processed;
        }

        return $queryStructured;
    }


    private function extractOperatedString(string $parsedQuery): string
    {
        $parsedQuery = trim($parsedQuery);

        if (isset($this->parts[$parsedQuery])) {
            $parsedQuery = trim($this->parts[$parsedQuery], '()');
            return $this->extractOperatedString($parsedQuery);
        }

        return $parsedQuery;
    }
}