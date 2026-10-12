<?php

namespace Bench;

/** Uniform reservoir sample of latencies (Algorithm R), so memory stays flat at any event rate. */
final class LatencyReservoir
{
    /** @var list<float> */
    private array $samples = [];

    private int $seen = 0;

    public function __construct(private int $capacity = 20_000)
    {
    }

    public function add(float $ms): void
    {
        $this->seen++;

        if (count($this->samples) < $this->capacity) {
            $this->samples[] = $ms;

            return;
        }

        $slot = random_int(0, $this->seen - 1);

        if ($slot < $this->capacity) {
            $this->samples[$slot] = $ms;
        }
    }

    public function seen(): int
    {
        return $this->seen;
    }

    /** @return list<float> */
    public function samples(): array
    {
        return $this->samples;
    }

    /** Nearest-rank percentile. @param list<float|int> $samples */
    public static function percentile(array $samples, float $p): float|int|null
    {
        if ($samples === []) {
            return null;
        }

        sort($samples);

        return $samples[max(0, (int) ceil($p / 100 * count($samples)) - 1)];
    }
}
