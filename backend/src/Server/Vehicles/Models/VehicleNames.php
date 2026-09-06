<?php

declare(strict_types=1);

namespace App\Server\Vehicles\Models;

/**
 * What the game calls each vehicle.
 *
 * Read out of media/lua/shared/Translate/EN/IG_UI.json, where every
 * IGUI_VehicleName* entry names one script -- the same names the wiki's
 * vehicle list uses, and far more use than "Base.CarLights".
 *
 * Generated rather than written by hand: there are 213 of them.
 *
 * Not every script has one. Wrecks, burnt-out shells and several of
 * build 42's trade vans are unnamed in the game's own files, so
 * {@see self::of()} falls back to the script name spaced out.
 */
final class VehicleNames
{
    /** @var array<string, string> */
    private const NAMES = [
        'AmbulanceBurnt' => 'Ambulance',
        'BurntCar' => 'Burnt %1',
        'CarLights' => 'Chevalier Nyala',
        'CarLightsBulletinSheriff' => 'Bulletin Sheriff Chevalier Nyala',
        'CarLightsKST' => 'State Trooper Chevalier Nyala',
        'CarLightsLouisvilleCounty' => 'LCPD Chevalier Nyala',
        'CarLightsMuldraughPolice' => 'Muldraugh Police Chevalier Nyala',
        'CarLightsPolice' => 'Police Chevalier Nyala',
        'CarLightsRanger' => 'Ranger Chevalier Nyala',
        'CarLightsSmashedFront' => 'Wrecked Chevalier Nyala',
        'CarLightsSmashedLeft' => 'Wrecked Chevalier Nyala',
        'CarLightsSmashedRear' => 'Wrecked Chevalier Nyala',
        'CarLightsSmashedRight' => 'Wrecked Chevalier Nyala',
        'CarLuxury' => 'Mercia Lang 4000',
        'CarLuxurySmashedFront' => 'Wrecked Mercia Lang 4000',
        'CarLuxurySmashedLeft' => 'Wrecked Mercia Lang 4000',
        'CarLuxurySmashedRear' => 'Wrecked Mercia Lang 4000',
        'CarLuxurySmashedRight' => 'Wrecked Mercia Lang 4000',
        'CarNormal' => 'Chevalier Nyala',
        'CarNormalSmashedFront' => 'Wrecked Chevalier Nyala',
        'CarNormalSmashedLeft' => 'Wrecked Chevalier Nyala',
        'CarNormalSmashedRear' => 'Wrecked Chevalier Nyala',
        'CarNormalSmashedRight' => 'Wrecked Chevalier Nyala',
        'CarSmall02SmashedFront' => 'Wrecked Masterson Horizon',
        'CarSmall02SmashedLeft' => 'Wrecked Masterson Horizon',
        'CarSmall02SmashedRear' => 'Wrecked Masterson Horizon',
        'CarSmall02SmashedRight' => 'Wrecked Masterson Horizon',
        'CarSmallSmashedFront' => 'Wrecked Chevalier Dart',
        'CarSmallSmashedLeft' => 'Wrecked Chevalier Dart',
        'CarSmallSmashedRear' => 'Wrecked Chevalier Dart',
        'CarSmallSmashedRight' => 'Wrecked Chevalier Dart',
        'CarStationWagon' => 'Chevalier Cerise Wagon',
        'CarStationWagon2' => 'Chevalier Cerise Wagon',
        'CarStationWagonSmashedFront' => 'Wrecked Chevalier Cerise Wagon',
        'CarStationWagonSmashedLeft' => 'Wrecked Chevalier Cerise Wagon',
        'CarStationWagonSmashedRear' => 'Wrecked Chevalier Cerise Wagon',
        'CarStationWagonSmashedRight' => 'Wrecked Chevalier Cerise Wagon',
        'CarTaxi' => 'Taxi',
        'CarTaxi2' => 'Taxi',
        'KnoxDistillery' => 'Knox Distillery',
        'LectroMax' => 'Lectromax',
        'LuxuryCarBurnt' => 'Mercia Lang 4000',
        'MassGenFac' => 'Mass-Genfac',
        'MccoyLogging' => 'McCoy Logging',
        'ModernCar' => 'Dash Elite',
        'ModernCar02' => 'Chevalier Primani',
        'ModernCarLightsCityLouisvillePD' => 'Louisville Police Dash Elite',
        'ModernCarLightsMeadeSheriff' => 'Meade Sheriff Dash Elite',
        'ModernCarLightsWestPoint' => 'West Point Police Dash Elite',
        'NormalCarBurntPolice' => 'Chevalier Nyala',
        'OffRoad' => 'Dash Rancher',
        'OffRoadSmashedFront' => 'Wrecked Dash Rancher',
        'OffRoadSmashedLeft' => 'Wrecked Dash Rancher',
        'OffRoadSmashedRear' => 'Wrecked Dash Rancher',
        'OffRoadSmashedRight' => 'Wrecked Dash Rancher',
        'PickUpTruck' => 'Chevalier D6',
        'PickUpTruckJPLandscaping' => 'JP Landscaping Chevalier D6',
        'PickUpTruckLights' => 'Chevalier D6',
        'PickUpTruckLightsAirport' => 'Airport Chevalier D6',
        'PickUpTruckLightsAirportSecurity' => 'Airport Security Chevalier D6',
        'PickUpTruckLightsFire' => 'Fire Department Chevalier D6',
        'PickUpTruckLightsFossoil' => 'Fossoil Chevalier D6',
        'PickUpTruckLightsRanger' => 'Ranger Chevalier D6',
        'PickUpTruckLightsSmashedFront' => 'Wrecked Chevalier D6',
        'PickUpTruckLightsSmashedLeft' => 'Wrecked Chevalier D6',
        'PickUpTruckLightsSmashedRear' => 'Wrecked Chevalier D6',
        'PickUpTruckLightsSmashedRight' => 'Wrecked Chevalier D6',
        'PickUpTruckMccoy' => 'McCoy Chevalier D6',
        'PickUpTruckSmashedFront' => 'Wrecked Chevalier D6',
        'PickUpTruckSmashedLeft' => 'Wrecked Chevalier D6',
        'PickUpTruckSmashedRear' => 'Wrecked Chevalier D6',
        'PickUpTruckSmashedRight' => 'Wrecked Chevalier D6',
        'PickUpTruck_Camo' => 'Chevalier D6',
        'PickUpVan' => 'Dash Bulldriver',
        'PickUpVanBrickingIt' => 'Bricking It Dash Bulldriver',
        'PickUpVanBuilder' => 'Builder\'s Dash Bulldriver',
        'PickUpVanCallowayLandscaping' => 'Calloway Landscaping Dash Bulldriver',
        'PickUpVanHeltonMetalWorking' => 'Helton Metalworking Dash Bulldriver',
        'PickUpVanKimbleKonstruction' => 'Kimbler Konstruction Dash Bulldriver',
        'PickUpVanLights' => 'Dash Bulldriver',
        'PickUpVanLightsCarpenter' => 'Carpenter\'s Dash Bulldriver',
        'PickUpVanLightsFire' => 'Fire Department Dash Bulldriver',
        'PickUpVanLightsFossoil' => 'Fossoil Dash Bulldriver',
        'PickUpVanLightsKentuckyLumber' => 'Kentucky Lumber Dash Bulldriver',
        'PickUpVanLightsLouisvilleCounty' => 'LCPD Dash Bulldriver',
        'PickUpVanLightsPolice' => 'Police Dash Bulldriver',
        'PickUpVanLightsRanger' => 'Ranger Dash Bulldriver',
        'PickUpVanLightsSmashedFront' => 'Wrecked Dash Bulldriver',
        'PickUpVanLightsSmashedLeft' => 'Wrecked Dash Bulldriver',
        'PickUpVanLightsSmashedRear' => 'Wrecked Dash Bulldriver',
        'PickUpVanLightsSmashedRight' => 'Wrecked Dash Bulldriver',
        'PickUpVanLightsStatePolice' => 'State Trooper Dash Bulldriver',
        'PickUpVanMarchRidgeConstruction' => 'March Ridge Construction Dash Bulldriver',
        'PickUpVanMccoy' => 'McCoy Dash Bulldriver',
        'PickUpVanMetalworker' => 'Metalworker\'s Dash Bulldriver',
        'PickUpVanSmashedFront' => 'Wrecked Dash Bulldriver',
        'PickUpVanSmashedLeft' => 'Wrecked Dash Bulldriver',
        'PickUpVanSmashedRear' => 'Wrecked Dash Bulldriver',
        'PickUpVanSmashedRight' => 'Wrecked Dash Bulldriver',
        'PickUpVanWeldingbyCamille' => 'Welding by Camille Dash Bulldriver',
        'PickUpVanYingsWood' => 'Van Yings Wood Dash Bulldriver',
        'PickUpVan_Camo' => 'Dash Bulldriver',
        'PickupBurnt' => 'Chevalier D6',
        'PickupSpecialBurnt' => 'Chevalier D6',
        'Police' => 'Police',
        'Postal' => 'Postal',
        'RaceCar' => 'Race Car',
        'RaceCar12' => 'Race Car',
        'RaceCar34' => 'Race Car',
        'RaceCar58' => 'Race Car',
        'RaceCarBurnt' => 'Race Car',
        'SUV' => 'Franklin All-Terrain',
        'SUVSmashedFront' => 'Wrecked Franklin All-Terrain',
        'SUVSmashedLeft' => 'Wrecked Franklin All-Terrain',
        'SUVSmashedRear' => 'Wrecked Franklin All-Terrain',
        'SUVSmashedRight' => 'Wrecked Franklin All-Terrain',
        'SmallCar' => 'Chevalier Dart',
        'SmallCar02' => 'Masterson Horizon',
        'SportsCar' => 'Chevalier Cossette',
        'StepVan' => 'Chevalier Step Van',
        'StepVanAirportCatering' => 'Airport Catering Chevalier Step Van',
        'StepVanMail' => 'Mail Chevalier Step Van',
        'StepVanMailSmashedFront' => 'Wrecked Chevalier Step Van',
        'StepVanMailSmashedLeft' => 'Wrecked Chevalier Step Van',
        'StepVanMailSmashedRear' => 'Wrecked Chevalier Step Van',
        'StepVanMailSmashedRight' => 'Wrecked Chevalier Step Van',
        'StepVanRadio' => 'Chevalier Step Van',
        'StepVanSmashedFront' => 'Wrecked Chevalier Step Van',
        'StepVanSmashedLeft' => 'Wrecked Chevalier Step Van',
        'StepVanSmashedRear' => 'Wrecked Chevalier Step Van',
        'StepVanSmashedRight' => 'Wrecked Chevalier Step Van',
        'StepVan_Cereal' => 'Cereal Delivery Chevalier Step Van',
        'StepVan_Citr8' => 'Citr8 Chevalier Step Van',
        'StepVan_CompleteRepairShop' => 'Complete Repair Shop Chevalier Step Van',
        'StepVan_Genuine_Beer' => 'Genuine Beer Chevalier Step Van',
        'StepVan_Heralds' => 'KY Herald Chevalier Step Van',
        'StepVan_HuangsLaundry' => 'Huang\'s Laundry Chevalier Step Van',
        'StepVan_Jorgensen' => 'Jorgensen Chevalier Step Van',
        'StepVan_LouisvilleMotorShop' => 'Louisville Motorshop Chevalier Step Van',
        'StepVan_LouisvilleSWAT' => 'Louisville SWAT Chevalier Step Van',
        'StepVan_MarineBites' => 'Marine Bites Chevalier Step Van',
        'StepVan_Mechanic' => 'Mechanic\'s Chevalier Step Van',
        'StepVan_Plonkies' => 'Plonkies Chevalier Step Van',
        'StepVan_RandisPlants' => 'Randi\'s Plants Chevalier Step Van',
        'StepVan_Scarlet' => 'Scarlet Oak Chevalier Step Van',
        'StepVan_SouthEasternHosp' => 'South Eastern Hospitality Chevalier Step Van',
        'StepVan_SouthEasternPaint' => 'South Eastern Paint Chevalier Step Van',
        'StepVan_USL' => 'USL Chevalier Step Van',
        'StepVan_Zippee' => 'Zippee Chevalier Step Van',
        'TaxiBurnt' => 'Taxi',
        'Trailer' => 'Trailer',
        'TrailerAdvert' => 'Trailer',
        'TrailerCover' => 'Trailer',
        'Trailer_Horsebox' => 'Horse trailer',
        'Trailer_Livestock' => 'Livestock Trailer',
        'Van' => 'Franklin Valuline',
        'VanAmbulance' => 'Ambulance',
        'VanBeckmans' => 'Beckman\'s Building Franklin Valuline',
        'VanBrewsterHarbin' => 'Brewster & Harbin Franklin Valuline',
        'VanBuilder' => 'Builder\'s Franklin Valuline',
        'VanCarpenter' => 'Carpenter\'s Franklin Valuline',
        'VanCoastToCoast' => 'Coast 2 Coast Franklin Valuline',
        'VanDeerValley' => 'Deer Valley Power Franklin Valuline',
        'VanFossoil' => 'Fossoil Franklin Valuline',
        'VanGardenGods' => 'Garden Gods Franklin Valuline',
        'VanGardener' => 'Gardener\'s Franklin Valuline',
        'VanJohnMcCoy' => 'John McCoy Woodworking Franklin Valuline',
        'VanJonesFabrication' => 'Jones Fabrication Franklin Valuline',
        'VanKerrHomes' => 'Kerr Homes Franklin Valuline',
        'VanKnobCreekGas' => 'Knob Creek Gas Franklin Valuline',
        'VanKnoxCom' => 'Knox Telecommunications Franklin Valuline',
        'VanKorshunovs' => 'Korshunov\'s Car Center Franklin Valuline',
        'VanLouisvilleLandscaping' => 'Louisville Landscaping Franklin Valuline',
        'VanMail' => 'Mail Franklin Valuline',
        'VanMccoy' => 'McCoy Franklin Valuline',
        'VanMechanic' => 'Mechanic\'s Franklin Valuline',
        'VanMeltingPointMetal' => 'Melting Point Metal Franklin Valuline',
        'VanMetalheads' => 'Metalheads Franklin Valuline',
        'VanMetalworker' => 'Metalworker\'s Franklin Valuline',
        'VanMicheles' => 'Michele\'s Woodshop Franklin Valuline',
        'VanMobileMechanics' => 'Mobile Mechanics Franklin Valuline',
        'VanMooreMechanics' => 'Moore Mechanics Franklin Valuline',
        'VanOldMill' => 'Old Mill Water Company Franklin Valuline',
        'VanOvoFarm' => 'Franklin Valuline',
        'VanPennSHam' => 'Penn S. Ham Construction Franklin Valuline',
        'VanPlattAuto' => 'Platt Auto Repair Franklin Valuline',
        'VanPluggedInElectrics' => 'Plugged In Electrics Franklin Valuline',
        'VanRadio' => 'LBMW Radio Van',
        'VanRadio_3N' => 'Triple-N Van',
        'VanRiversideFabrication' => 'Riverside Fabrication Franklin Valuline',
        'VanRosewoodworking' => 'Rosewoodworking Franklin Valuline',
        'VanSchwabSheetMetal' => 'Schwab Sheet Metal Franklin Valuline',
        'VanSeats' => 'Franklin Valuline',
        'VanSeatsAirportShuttle' => 'Airport Franklin Valuline',
        'VanSeats_Creature' => 'Creature Cruiser',
        'VanSeats_LadyDelighter' => 'The Lady Delighter',
        'VanSeats_Prison' => 'Prisoner Transport Franklin Valuline',
        'VanSeats_Space' => 'Quantum Vessel',
        'VanSeats_Trippy' => 'Mesmer Wagon',
        'VanSeats_Valkyrie' => 'Valkyrie\'s Spear',
        'VanSpecial' => 'Franklin Valuline',
        'VanSpiffo' => 'Spiffo Van',
        'VanTreyBaines' => 'Trey Baines Franklin Valuline',
        'VanUncloggers' => 'Uncloggers Franklin Valuline',
        'VanUtility' => 'Utility Franklin Valuline',
        'VanVanGreenes' => 'Greenes Franklin Valuline',
        'VanWPCarpentry' => 'WP Carpentry Franklin Valuline',
        'Van_BugWipers' => 'Bug Wipers Franklin Valuline',
        'Van_KnoxDisti' => 'Knox Distillery Franklin Valuline',
        'Van_LectroMax' => 'Lectromax Franklin Valuline',
        'Van_MassGenFac' => 'Mass GenFac Franklin Valuline',
        'Van_Transit' => 'Transit Franklin Valuline',
        'Van_VoltMojo' => 'Volt Mojo Franklin Valuline',
    ];

    /** The display name for a script, with or without its module prefix. */
    public static function of(string $script): string
    {
        $bare = str_contains($script, '.')
            ? substr($script, strrpos($script, '.') + 1)
            : $script;

        return self::NAMES[$bare] ?? self::spaced($bare);
    }

    public static function isKnown(string $script): bool
    {
        $bare = str_contains($script, '.')
            ? substr($script, strrpos($script, '.') + 1)
            : $script;

        return isset(self::NAMES[$bare]);
    }

    /**
     * "Van_Locksmith" as "Van Locksmith", "CarNormalBurnt" as
     * "Car Normal Burnt": readable, and honest about being derived.
     */
    private static function spaced(string $script): string
    {
        $spaced = str_replace('_', ' ', $script);
        $spaced = (string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $spaced);

        return trim((string) preg_replace('/\s+/', ' ', $spaced));
    }
}
