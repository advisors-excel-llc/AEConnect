<?php

namespace AE\ConnectBundle\Util;

class DestructuredQuery
{
    private $select;
    private $from;
    public $where;
    public $orderBy;
    public $limit;
    public $offset;

    public function __construct(string $query)
    {
        // We are going to EAT the query one piece at a time
        // CONSUME select
        $query = substr($query, 7);
        // CONSUME select pieces
        $fromPos = stripos($query, ' FROM ');
        $this->setSelect(substr($query, 0, $fromPos));
        $query = trim(substr($query, $fromPos));
        // CONSUME from
        $query = trim(substr($query, 5));
        // CONSUME from target
        $fromTargetLength = strpos($query, ' ');
        // In case there is nothing special about the query after FROM
        $fromTargetLength = $fromTargetLength ? $fromTargetLength : strlen($query);
        $this->from = substr($query, 0, $fromTargetLength);
        $query = trim(substr($query, $fromTargetLength));
        // CONSUME the other words
        while (strlen($query)) {
            $nextWord = strtolower(substr($query, 0, strpos($query, ' ')));
            $query = trim(substr($query, strpos($query, ' ')));
            // We have to make this small adjustment here just because order by is two words rather than 1 like offset limit
            if ('order' == $nextWord) {
                $nextWord = 'orderBy';
                $query = trim(substr($query, strpos($query, ' ')));
            }
            $nextClausePosition = min(array_filter([
                stripos($query, ' order by '),
                stripos($query, ' limit '),
                stripos($query, ' offset '),
                stripos($query, ' where '),
                strlen($query),
            ]));
            $this->$nextWord = substr($query, 0, $nextClausePosition);
            $query = trim(substr($query, $nextClausePosition));
        }
    }

    public function setSelect($select)
    {
        $this->select = strtoupper($select);
    }

    public function getSelect()
    {
        return $this->select;
    }

    public function getFrom()
    {
        return strtoupper($this->from);
    }

    public function __toString()
    {
        $str = "SELECT $this->select FROM $this->from";
        if ($this->where) {
            $str .= " WHERE $this->where";
        }
        if ($this->orderBy) {
            $str .= " ORDER BY $this->orderBy";
        }
        if ($this->limit) {
            $str .= " LIMIT $this->limit";
        }
        if ($this->offset) {
            $str .= " OFFSET $this->offset";
        }

        return $str;
    }
}
