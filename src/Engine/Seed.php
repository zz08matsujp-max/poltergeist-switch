<?php

declare(strict_types=1);

namespace PoltergeistSwitch\Engine;

class Seed
{
    public readonly int $value;
    private \Random\Randomizer $randomizer;

    private function __construct(int $value)
    {
        $this->value      = $value;
        $this->randomizer = new \Random\Randomizer(new \Random\Engine\Mt19937($value));
    }

    public static function fromInt(int $n): self
    {
        return new self($n);
    }

    /**
     * Draws one integer in [$min, $max] using this seed's own Randomizer
     * instance. The sequence is reproducible: same $value always produces
     * the same sequence of draws because the Mt19937 engine is private
     * to this instance and no global RNG state is touched.
     *
     * @throws \InvalidArgumentException if $min > $max
     */
    public function next(int $min, int $max): int
    {
        if ($min > $max) {
            throw new \InvalidArgumentException(
                "min ($min) must not be greater than max ($max)"
            );
        }

        return $this->randomizer->getInt($min, $max);
    }
}
