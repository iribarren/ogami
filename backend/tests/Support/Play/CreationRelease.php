<?php

declare(strict_types=1);

namespace App\Tests\Support\Play;

use App\Tests\Support\Studio\ReleaseArrays;

/**
 * A release whose sheet creates Characters by roll or by points (ADR 0040): Strength and
 * Constitution are rolled or bought with 100 points (15 to 90 each), Luck is always rolled, and
 * Hit points start full at half of the two.
 */
final class CreationRelease
{
    public const string KEY = 'creation';

    /**
     * @return array<mixed>
     */
    public static function content(): array
    {
        $content = ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-minimal'), 'gameSystem', ['key' => self::KEY, 'name' => 'Creation', 'description' => 'Characters created by roll or by points.']);

        return ReleaseArrays::with($content, 'sheet', [
            'fields' => [
                ['key' => 'role', 'label' => 'Role', 'type' => 'text'],
                ['key' => 'str', 'label' => 'Strength', 'type' => 'number', 'min' => 1, 'max' => 99, 'roll' => ['dice' => '3d6*5']],
                ['key' => 'con', 'label' => 'Constitution', 'type' => 'number', 'min' => 1, 'max' => 99, 'roll' => ['dice' => '(2d6+6)*5']],
                ['key' => 'luck', 'label' => 'Luck', 'type' => 'number', 'min' => 1, 'max' => 99, 'roll' => ['dice' => '3d6*5']],
                ['key' => 'half', 'label' => 'Half', 'type' => 'derived', 'formula' => '({str}+{con})/2'],
                ['key' => 'hp', 'label' => 'Hit points', 'type' => 'track', 'startMax' => ['formula' => '{half}']],
            ],
            'pointBudget' => ['label' => 'Points', 'total' => 100, 'fields' => ['str', 'con'], 'min' => 15, 'max' => 90],
        ]);
    }
}
