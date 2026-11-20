<?php

namespace App\Ai;

final class Reservation
{
    public function __construct(public readonly ?int $id, public readonly int $userId, public readonly int $amount, public readonly bool $fromEpisode = false) {}
}
