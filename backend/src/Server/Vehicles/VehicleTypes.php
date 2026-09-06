<?php

declare(strict_types=1);

namespace App\Server\Vehicles;

/**
 * The kind of vehicle a person would name: a small car, a van, a trailer.
 *
 * Derived from the paint mask, which is the shell, plus the service
 * words the game itself puts in a script name. Not from mechanicType:
 * that grades a vehicle's repair difficulty (1 standard, 2 heavy, 3
 * sports), which puts an ambulance beside a step van and a police
 * cruiser beside a family saloon.
 */
final class VehicleTypes
{
    public const SMALL = 'small';
    public const CAR = 'car';
    public const SPORTS = 'sports';
    public const SUV = 'suv';
    public const PICKUP = 'pickup';
    public const VAN = 'van';
    public const SERVICE = 'service';
    public const TRAILER = 'trailer';
    public const WRECK = 'wreck';

    /** The order the filter shows them in: everyday first, oddities last. */
    public const ORDER = [
        self::SMALL,
        self::CAR,
        self::SPORTS,
        self::SUV,
        self::PICKUP,
        self::VAN,
        self::SERVICE,
        self::TRAILER,
        self::WRECK,
    ];

    /** @var array<string, string> */
    private const BY_MASK = [
        'vehicle_smallcar_mask' => self::SMALL,
        'vehicle_smallcar02_mask' => self::SMALL,
        'vehicle_carnormal_mask' => self::CAR,
        'vehicle_taxi_mask' => self::CAR,
        'vehicle_carstationwagon_mask' => self::CAR,
        'vehicle_carmodern_mask' => self::SPORTS,
        'vehicle_carmodern2_mask' => self::SPORTS,
        'vehicle_carmodernlights_mask' => self::SPORTS,
        'vehicle_luxurycar_mask' => self::SPORTS,
        'vehicle_sportscar_mask' => self::SPORTS,
        'vehicle_racecar_mask' => self::SPORTS,
        'vehicle_suv_mask' => self::SUV,
        'vehicle_offroad_mask' => self::SUV,
        'vehicle_pickuptruck_mask' => self::PICKUP,
        'vehicle_van_mask' => self::VAN,
        'vehicle_vanseats_mask' => self::VAN,
        'vehicle_stepvan_mask' => self::VAN,
        'vehicle_vanambulance_mask' => self::SERVICE,
        'vehicle_utilitytrailer_mask' => self::TRAILER,
        'vehicle_adverttrailer_mask' => self::TRAILER,
    ];

    /**
     * Words the game uses for the liveries of a service vehicle. Matched
     * on the script name because the shell is shared: a police cruiser
     * and a family saloon are the same body with different paint.
     */
    private const SERVICE_WORDS = [
        'police',
        'sheriff',
        'ranger',
        'fire',
        'ambulance',
        'kst',
        'trooper',
        'lcpd',
        'swat',
        'lights',
    ];

    /** @param array<string, mixed>|null $artwork */
    public static function of(string $script, ?array $artwork): string
    {
        $mask = $artwork['mask'] ?? null;

        // No paint mask means a burnt-out hull, which is a wreck whatever
        // it used to be.
        if (!\is_string($mask)) {
            return self::WRECK;
        }

        $name = strtolower(
            str_contains($script, '.') ? substr($script, strrpos($script, '.') + 1) : $script,
        );

        foreach (self::SERVICE_WORDS as $word) {
            if (str_contains($name, $word)) {
                return self::SERVICE;
            }
        }

        return self::BY_MASK[pathinfo($mask, PATHINFO_FILENAME)] ?? self::CAR;
    }
}
