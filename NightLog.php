<?php

declare(strict_types=1);

namespace App\Observatory;

use App\Observatory\Attributes\Cached;
use App\Observatory\Events\PhaseChanged;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

enum MoonPhase: string
{
    case New = 'new';
    case Waxing = 'waxing';
    case Full = 'full';
    case Waning = 'waning';

    public function label(): string
    {
        return match ($this) {
            self::New, self::Full => ucfirst($this->value) . ' moon',
            default => ucfirst($this->value) . ' crescent',
        };
    }
}

/**
 * What the garden telescope saw each night.
 *
 * @param Collection<int, array{phase: MoonPhase, lux: float}> $readings
 */
final readonly class NightLog
{
    private const LUNAR_CYCLE_DAYS = 29.530588;
    private const KNOWN_NEW_MOON = 947_182_440; // 6 Jan 2000, 18:14 UTC

    public function __construct(
        private Collection $readings,
        private ?string $observer = null,
    ) {}

    #[Cached(ttl: 3600)]
    public function brightestNight(): ?array
    {
        return $this->readings
            ->filter(fn (array $reading): bool => $reading['lux'] > 0.25)
            ->sortByDesc('lux')
            ->first();
    }

    public function phaseOn(CarbonImmutable $date): MoonPhase
    {
        $days = ($date->timestamp - self::KNOWN_NEW_MOON) / 86_400;
        $age = fmod($days, self::LUNAR_CYCLE_DAYS);

        // Rough buckets: good enough for a back-garden telescope
        return match (true) {
            $age < 1.84 => MoonPhase::New,
            $age < 13.77 => MoonPhase::Waxing,
            $age < 15.77 => MoonPhase::Full,
            default => MoonPhase::Waning,
        };
    }

    public function summary(): string
    {
        $phase = $this->phaseOn(CarbonImmutable::now());
        event(new PhaseChanged($phase));

        return "{$this->readings->count()} nights logged by "
            . ($this->observer ?? 'anonymous')
            . " — tonight: {$phase->label()}";
    }
}
