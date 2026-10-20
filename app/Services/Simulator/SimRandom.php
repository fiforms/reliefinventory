<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Simulator;

/**
 * The simulator's only source of randomness, built on mt_rand so that
 * mt_srand($seed) makes a whole run reproducible (same seed + same catalog =
 * same data). Collection::random()/shuffle() use random_int and can't be
 * seeded, so the simulator never calls them.
 */
class SimRandom
{
    public function __construct(?int $seed = null)
    {
        if ($seed !== null) {
            mt_srand($seed);
        }
    }

    public function int(int $min, int $max): int
    {
        return mt_rand($min, $max);
    }

    public function float(): float
    {
        return mt_rand() / mt_getrandmax();
    }

    /** True with the given probability (0..1). */
    public function chance(float $p): bool
    {
        return $this->float() < $p;
    }

    public function pick(array $items): mixed
    {
        $items = array_values($items);

        return $items[mt_rand(0, count($items) - 1)];
    }

    /** @return array<int, mixed> $n distinct picks (fewer if the list is shorter) */
    public function pickMany(array $items, int $n): array
    {
        $items = array_values($items);
        $this->shuffle($items);

        return array_slice($items, 0, $n);
    }

    public function shuffle(array &$items): void
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }
    }

    /**
     * Weighted pick: $weights is key => weight; returns the chosen key.
     *
     * @param  array<int|string, float|int>  $weights
     */
    public function weighted(array $weights): int|string
    {
        $total = array_sum($weights);
        $roll = $this->float() * $total;
        foreach ($weights as $key => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $key;
            }
        }

        return array_key_last($weights);
    }

    /** Log-uniform integer: many small quantities, a few big ones — how real order/donation sizes look. */
    public function logInt(int $min, int $max): int
    {
        $v = exp(log($min) + $this->float() * (log($max) - log($min)));

        return max($min, min($max, (int) round($v)));
    }

    /** Poisson-distributed count with the given mean (Knuth) — a day's worth of arrivals. */
    public function poisson(float $mean): int
    {
        if ($mean <= 0) {
            return 0;
        }
        $limit = exp(-$mean);
        $k = 0;
        $p = 1.0;
        do {
            $k++;
            $p *= $this->float();
        } while ($p > $limit);

        return $k - 1;
    }
}
