<?php

namespace App\Ai;

/** Names the studio item a run of model calls belongs to, so each ledger row can say which item it paid for (and the item can show its cost). */
class CostScope
{
    private static ?int $itemId = null;

    public static function itemId(): ?int
    {
        return self::$itemId;
    }

    /** @template T @param  callable(): T  $callback @return T */
    public static function for(int $itemId, callable $callback): mixed
    {
        $previous = self::$itemId;
        self::$itemId = $itemId;

        try {
            return $callback();
        } finally {
            self::$itemId = $previous;
        }
    }
}
