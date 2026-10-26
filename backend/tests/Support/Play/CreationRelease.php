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
    public const string MIXED_KEY = 'creation-mixed';
    public const string BUDGETS_KEY = 'creation-budgets';

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

    /**
     * The same release with a budget that mixes dice and non-dice fields: Strength is rolled,
     * Sanity (no dice default) is set by the player, 15 to 90, whatever the method.
     *
     * @return array<mixed>
     */
    public static function mixedContent(): array
    {
        $content = ReleaseArrays::with(self::content(), 'gameSystem', ['key' => self::MIXED_KEY, 'name' => 'Mixed creation', 'description' => 'A budget over a rolled and a chosen field.']);

        return ReleaseArrays::with($content, 'sheet', [
            'fields' => [
                ['key' => 'str', 'label' => 'Strength', 'type' => 'number', 'min' => 1, 'max' => 99, 'roll' => ['dice' => '3d6*5']],
                ['key' => 'san', 'label' => 'Sanity', 'type' => 'number', 'min' => 1, 'max' => 99, 'default' => 50],
            ],
            'pointBudget' => ['label' => 'Points', 'total' => 100, 'fields' => ['str', 'san'], 'min' => 15, 'max' => 90],
        ]);
    }

    /**
     * Two point budgets (ADR 0043): Strength and Constitution are rolled or bought with 100 points
     * (15 to 90 each); Lore and Sneak are skills bought with 5 points, at most 3 each, never rolled.
     * Luck is always rolled.
     *
     * @return array<mixed>
     */
    public static function budgetsContent(): array
    {
        $content = ReleaseArrays::with(self::content(), 'gameSystem', ['key' => self::BUDGETS_KEY, 'name' => 'Two budgets', 'description' => 'Attribute points and skill points.']);

        return ReleaseArrays::with($content, 'sheet', [
            'fields' => [
                ['key' => 'str', 'label' => 'Strength', 'type' => 'number', 'min' => 1, 'max' => 99, 'roll' => ['dice' => '3d6*5']],
                ['key' => 'con', 'label' => 'Constitution', 'type' => 'number', 'min' => 1, 'max' => 99, 'roll' => ['dice' => '(2d6+6)*5']],
                ['key' => 'lore', 'label' => 'Lore', 'type' => 'number', 'min' => 0, 'max' => 5],
                ['key' => 'sneak', 'label' => 'Sneak', 'type' => 'number', 'min' => 0, 'max' => 5],
                ['key' => 'luck', 'label' => 'Luck', 'type' => 'number', 'min' => 1, 'max' => 99, 'roll' => ['dice' => '3d6*5']],
            ],
            'pointBudgets' => [
                ['key' => 'attributes', 'label' => 'Attribute points', 'total' => 100, 'fields' => ['str', 'con'], 'min' => 15, 'max' => 90],
                ['key' => 'skills', 'label' => 'Skill points', 'total' => 5, 'fields' => ['lore', 'sneak'], 'max' => 3],
            ],
        ]);
    }
}
