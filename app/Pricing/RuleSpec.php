<?php

namespace App\Pricing;

use App\Models\Rule;
use DateTimeImmutable;

/** A rule as the engine sees it, with no database behind it. */
final readonly class RuleSpec
{
    /**
     * @param  array<string, mixed>  $params
     * @param  list<string>|null  $groups
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $type,
        public array $params,
        public int $priority = 0,
        public bool $exclusive = false,
        public bool $active = true,
        public ?DateTimeImmutable $startsAt = null,
        public ?DateTimeImmutable $endsAt = null,
        public ?array $groups = null,
        public ?string $couponCode = null,
        public ?int $usageLimit = null,
        public int $timesUsed = 0,
    ) {}

    public static function fromModel(Rule $r): self
    {
        return new self(
            id: $r->id,
            name: $r->name,
            type: $r->type,
            params: $r->params,
            priority: $r->priority,
            exclusive: $r->exclusive,
            active: $r->active,
            startsAt: $r->starts_at?->toDateTimeImmutable(),
            endsAt: $r->ends_at?->toDateTimeImmutable(),
            groups: $r->customer_groups,
            couponCode: $r->coupon_code,
            usageLimit: $r->usage_limit,
            timesUsed: $r->times_used,
        );
    }
}
