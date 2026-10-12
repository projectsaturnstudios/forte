<?php

namespace ProjectSaturnStudios\Forte\Servers\Forte\Publishing;

use RuntimeException;

/** RESP2 encoder and incremental decoder. */
class Resp
{
    public const INCOMPLETE = "\0incomplete\0";

    protected string $buffer = '';

    /** @param list<string|int> $command */
    public static function encode(array $command): string
    {
        $out = '*'.count($command)."\r\n";
        foreach ($command as $part) {
            $part = (string) $part;
            $out .= '$'.strlen($part)."\r\n".$part."\r\n";
        }

        return $out;
    }

    public function feed(string $bytes): void
    {
        $this->buffer .= $bytes;
    }

    /** @return mixed a decoded value, or self::INCOMPLETE when the buffer holds no complete frame */
    public function next(): mixed
    {
        $offset = 0;
        $value = $this->decode($offset);

        if ($value === self::INCOMPLETE) {
            return self::INCOMPLETE;
        }

        $this->buffer = (string) substr($this->buffer, $offset);

        return $value;
    }

    protected function decode(int &$offset): mixed
    {
        if (! isset($this->buffer[$offset])) {
            return self::INCOMPLETE;
        }

        $type = $this->buffer[$offset];
        $eol = strpos($this->buffer, "\r\n", $offset + 1);

        if ($eol === false) {
            return self::INCOMPLETE;
        }

        $line = substr($this->buffer, $offset + 1, $eol - $offset - 1);
        $after = $eol + 2;

        switch ($type) {
            case '+':
                $offset = $after;

                return $line;
            case '-':
                $offset = $after;

                return new RespError($line);
            case ':':
                $offset = $after;

                return (int) $line;
            case '$':
                $length = (int) $line;
                if ($length === -1) {
                    $offset = $after;

                    return null;
                }
                if (strlen($this->buffer) < $after + $length + 2) {
                    return self::INCOMPLETE;
                }
                $offset = $after + $length + 2;

                return substr($this->buffer, $after, $length);
            case '*':
                $count = (int) $line;
                if ($count === -1) {
                    $offset = $after;

                    return null;
                }
                $items = [];
                $cursor = $after;
                for ($i = 0; $i < $count; $i++) {
                    $item = $this->decode($cursor);
                    if ($item === self::INCOMPLETE) {
                        return self::INCOMPLETE;
                    }
                    $items[] = $item;
                }
                $offset = $cursor;

                return $items;
            default:
                throw new RuntimeException("Unexpected RESP type byte [{$type}].");
        }
    }
}
