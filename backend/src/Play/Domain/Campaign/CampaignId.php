<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign;

/**
 * Identifies a campaign. Opaque string (a UUID v7 in production).
 */
final readonly class CampaignId
{
    private function __construct(
        private string $value,
    ) {
    }

    /**
     * @throws InvalidCampaignId
     */
    public static function fromString(string $value): self
    {
        if ('' === trim($value)) {
            throw InvalidCampaignId::blank();
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
