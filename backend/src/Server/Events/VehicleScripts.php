<?php

declare(strict_types=1);

namespace App\Server\Events;

/**
 * The vehicles offered in the spawn dropdown.
 *
 * Build 42 defines well over a hundred once liveries, wrecks and burnt
 * variants are counted, which makes a poor dropdown. These are the base
 * types; anything else -- including a modded vehicle -- can be typed in,
 * and the server reports an unknown script back as "Unknown vehicle
 * script".
 */
final readonly class VehicleScripts
{
    public const NAMES = [
        'Base.CarNormal',
        'Base.CarLuxury',
        'Base.CarStationWagon',
        'Base.CarTaxi',
        'Base.SmallCar',
        'Base.SportsCar',
        'Base.ModernCar',
        'Base.OffRoad',
        'Base.SUV',
        'Base.PickUpTruck',
        'Base.PickUpVan',
        'Base.Van',
        'Base.VanSeats',
        'Base.VanAmbulance',
        'Base.StepVan',
        'Base.VanRadio',
        'Base.VanSpiffo',
    ];

    /**
     * A script name reaches the command unquoted, so only the characters
     * the command's own pattern accepts are let through.
     */
    public static function isValidName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9.-]*[A-Za-z][A-Za-z0-9_.-]*$/', $name) === 1;
    }
}
