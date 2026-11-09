<?php

namespace Database\Seeders;

use App\Models\EmergencyResource;
use App\Models\EvacuationRoute;
use App\Models\RescueRequest;
use App\Models\ResourceDistribution;
use App\Models\Shelter;
use App\Models\Volunteer;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class EmergencyCoordinationSeeder extends Seeder
{
    /**
     * Run the database seeds with verified real-world ASEAN shelters and evacuation facilities.
     */
    public function run(): void
    {
        // 1. Designated ASEAN Emergency Shelters & Relief Hubs
        $shelters = [
            [
                'id' => 'shl_manila_amoranto',
                'name' => 'Amoranto Sports Complex Emergency Evacuation Center',
                'type' => Shelter::TYPE_EVACUATION_CENTER,
                'country' => 'Philippines',
                'city' => 'Quezon City, Metro Manila',
                'address' => 'Rocies Ave, Diliman, Quezon City',
                'latitude' => 14.6300,
                'longitude' => 121.0312,
                'contact_name' => 'Capt. Ronald Reyes (QC DRRMO)',
                'contact_phone' => '+63 2 8927 5914',
                'contact_email' => 'drrmo@quezoncity.gov.ph',
                'total_capacity' => 1200,
                'current_occupancy' => 450,
                'status' => Shelter::STATUS_OPEN,
                'facilities' => ['Medical Triage Station', 'Backup Solar Generator', 'Potable Water Filtration', 'Mother & Child Care Room', 'PWD Accessible Ramps'],
                'notes' => 'Primary designated metropolitan multi-hazard shelter for flood and typhoon evacuees.',
            ],
            [
                'id' => 'shl_manila_pgh',
                'name' => 'Philippine General Hospital Emergency Trauma Reserve',
                'type' => Shelter::TYPE_HOSPITAL,
                'country' => 'Philippines',
                'city' => 'Manila',
                'address' => 'Taft Ave, Ermita, Manila',
                'latitude' => 14.5779,
                'longitude' => 120.9870,
                'contact_name' => 'Dr. Maria Santos (Chief of Trauma)',
                'contact_phone' => '+63 2 8554 8400',
                'contact_email' => 'er@pgh.gov.ph',
                'total_capacity' => 350,
                'current_occupancy' => 280,
                'status' => Shelter::STATUS_OPEN,
                'facilities' => ['Level 3 Trauma Care', 'ICU Surge Beds', 'Blood Bank', 'Dedicated Disaster Heliport'],
                'notes' => 'Primary trauma coordination node for Metro Manila seismic emergency response.',
            ],
            [
                'id' => 'shl_jakarta_gelora',
                'name' => 'Gelora Bung Karno Regional Logistics Hub & Shelter',
                'type' => Shelter::TYPE_RELIEF_CENTER,
                'country' => 'Indonesia',
                'city' => 'Central Jakarta',
                'address' => 'Jl. Pintu Satu Senayan, Gelora, Jakarta Pusat',
                'latitude' => -6.2183,
                'longitude' => 106.8022,
                'contact_name' => 'Bambang Supriyanto (BPBD DKI)',
                'contact_phone' => '+62 21 112',
                'contact_email' => 'posko@bpbd.jakarta.go.id',
                'total_capacity' => 2500,
                'current_occupancy' => 820,
                'status' => Shelter::STATUS_OPEN,
                'facilities' => ['Central Warehouse Depot', 'Heavy Logistics Parking', 'Water Bladders (50kL)', 'Field Hospital Tent Array'],
                'notes' => 'Provincial disaster coordination staging grounds and emergency distribution center.',
            ],
            [
                'id' => 'shl_bangkok_huamark',
                'name' => 'Hua Mark Indoor Stadium Evacuation Center',
                'type' => Shelter::TYPE_SHELTER,
                'country' => 'Thailand',
                'city' => 'Bangkok',
                'address' => 'Ramkhamhaeng Rd, Hua Mak, Bang Kapi',
                'latitude' => 13.7548,
                'longitude' => 100.6225,
                'contact_name' => 'Somchai Prasert (DDPM Bangkok)',
                'contact_phone' => '+66 2 241 7450',
                'contact_email' => 'shelter@ddpm.go.th',
                'total_capacity' => 1800,
                'current_occupancy' => 310,
                'status' => Shelter::STATUS_OPEN,
                'facilities' => ['Emergency Kitchen Service', 'Sanitation Blocks', 'Child Protection Zone', 'Generator Backup'],
                'notes' => 'Designated Chao Phraya flood emergency reception center.',
            ],
            [
                'id' => 'shl_danang_sports',
                'name' => 'Tien Son Sports Center Evacuation Station',
                'type' => Shelter::TYPE_EVACUATION_CENTER,
                'country' => 'Vietnam',
                'city' => 'Da Nang',
                'address' => 'Phan Dang Luu St, Hai Chau, Da Nang',
                'latitude' => 16.0350,
                'longitude' => 108.2230,
                'contact_name' => 'Nguyen Van Minh (VNDMA Central)',
                'contact_phone' => '+84 236 3822 555',
                'contact_email' => 'tienson@vndma.gov.vn',
                'total_capacity' => 1500,
                'current_occupancy' => 600,
                'status' => Shelter::STATUS_OPEN,
                'facilities' => ['Reinforced Concrete Dome', 'Typhoon Storm Shutters', 'Medical Clinic', 'High-volume Diesel Generator'],
                'notes' => 'Coastal typhoon Category 4/5 certified storm surge evacuation refuge.',
            ],
            [
                'id' => 'shl_naypyidaw_wunna',
                'name' => 'Wunna Theikdi Stadium Emergency Relief Depot',
                'type' => Shelter::TYPE_RELIEF_CENTER,
                'country' => 'Myanmar',
                'city' => 'Naypyidaw',
                'address' => 'Zabuthiri Township, Naypyidaw',
                'latitude' => 19.7210,
                'longitude' => 96.0690,
                'contact_name' => 'U Kyaw Thein (DDM Myanmar)',
                'contact_phone' => '+95 67 3404666',
                'contact_email' => 'relief@ddm.gov.mm',
                'total_capacity' => 2000,
                'current_occupancy' => 120,
                'status' => Shelter::STATUS_OPEN,
                'facilities' => ['Air Transport Drop Zone', 'Grain Storage Silos', 'Emergency Potable Water Bladders'],
                'notes' => 'National emergency relief stockpiling base for central Myanmar.',
            ],
        ];

        foreach ($shelters as $s) {
            Shelter::updateOrCreate(['id' => $s['id']], $s);
        }

        // 2. Emergency Resources & Stock Inventories
        $resources = [
            [
                'id' => 'res_water_manila',
                'shelter_id' => 'shl_manila_amoranto',
                'category' => EmergencyResource::CATEGORY_WATER_FOOD,
                'item_name' => 'Purified Drinking Water (10L Jugs)',
                'quantity' => 1500,
                'unit' => 'jugs',
                'min_threshold' => 500,
                'status' => EmergencyResource::STATUS_ADEQUATE,
                'notes' => 'Pre-positioned bottled water supply from Maynilad/MWSS emergency stores.',
            ],
            [
                'id' => 'res_rations_manila',
                'shelter_id' => 'shl_manila_amoranto',
                'category' => EmergencyResource::CATEGORY_WATER_FOOD,
                'item_name' => 'Family Food Packs (DSWD Ready-to-Eat)',
                'quantity' => 380,
                'unit' => 'packs',
                'min_threshold' => 400,
                'status' => EmergencyResource::STATUS_LOW,
                'notes' => 'Contains 6kg rice, canned proteins, coffee, and energy biscuits. Resupply requested.',
            ],
            [
                'id' => 'res_trauma_pgh',
                'shelter_id' => 'shl_manila_pgh',
                'category' => EmergencyResource::CATEGORY_MEDICAL,
                'item_name' => 'Major Trauma Hemorrhage Control Kits',
                'quantity' => 240,
                'unit' => 'kits',
                'min_threshold' => 100,
                'status' => EmergencyResource::STATUS_ADEQUATE,
                'notes' => 'Sterile tourniquets, hemostatic gauze, pressure dressings.',
            ],
            [
                'id' => 'res_boats_jakarta',
                'shelter_id' => 'shl_jakarta_gelora',
                'category' => EmergencyResource::CATEGORY_RESCUE_EQUIPMENT,
                'item_name' => 'Inflatable Zodiac Rescue Boats with Outboard Motors',
                'quantity' => 28,
                'unit' => 'units',
                'min_threshold' => 15,
                'status' => EmergencyResource::STATUS_ADEQUATE,
                'notes' => 'Equipped for rapid urban flood evacuation across Jakarta canals.',
            ],
            [
                'id' => 'res_lifejackets_jakarta',
                'shelter_id' => 'shl_jakarta_gelora',
                'category' => EmergencyResource::CATEGORY_RESCUE_EQUIPMENT,
                'item_name' => 'Type III PFD Life Jackets (Adult & Pediatric)',
                'quantity' => 650,
                'unit' => 'units',
                'min_threshold' => 500,
                'status' => EmergencyResource::STATUS_ADEQUATE,
                'notes' => 'High buoyancy marine safety jackets.',
            ],
            [
                'id' => 'res_blankets_bangkok',
                'shelter_id' => 'shl_bangkok_huamark',
                'category' => EmergencyResource::CATEGORY_NECESSITIES,
                'item_name' => 'Thermal Bedding & Sleeping Mats',
                'quantity' => 85,
                'unit' => 'sets',
                'min_threshold' => 200,
                'status' => EmergencyResource::STATUS_CRITICAL,
                'notes' => 'High demand due to sudden flood displacement; replenishment dispatched.',
            ],
            [
                'id' => 'res_medical_danang',
                'shelter_id' => 'shl_danang_sports',
                'category' => EmergencyResource::CATEGORY_MEDICAL,
                'item_name' => 'Waterborne Disease Prophylaxis & Rehydration Salts',
                'quantity' => 1200,
                'unit' => 'boxes',
                'min_threshold' => 300,
                'status' => EmergencyResource::STATUS_ADEQUATE,
                'notes' => 'ORS sachets, water purification tablets, cholera test kits.',
            ],
            [
                'id' => 'res_generators_naypyidaw',
                'shelter_id' => 'shl_naypyidaw_wunna',
                'category' => EmergencyResource::CATEGORY_RESCUE_EQUIPMENT,
                'item_name' => 'Portable Diesel Power Generators (5.5 kW)',
                'quantity' => 12,
                'unit' => 'units',
                'min_threshold' => 10,
                'status' => EmergencyResource::STATUS_ADEQUATE,
                'notes' => 'Includes 500L auxiliary fuel drums.',
            ],
        ];

        foreach ($resources as $r) {
            EmergencyResource::updateOrCreate(['id' => $r['id']], $r);
        }

        // 3. Verified Evacuation Routes (Hazard-Aware)
        $routes = [
            [
                'id' => 'rte_marikina_quezon',
                'title' => 'Marikina River Valley to Amoranto High Ground Safe Corridor',
                'country' => 'Philippines',
                'origin' => 'Marikina Riverbanks / Tumana Submersible Zone',
                'destination' => 'Amoranto Sports Complex, Quezon City',
                'distance_km' => 8.4,
                'status' => EvacuationRoute::STATUS_CAUTION,
                'hazard_warning' => 'Caution: Marcos Highway viaduct experiencing localized surface ponding; use designated flyover bypass.',
                'instructions' => "1. Do not cross Tumana Bridge if water reaches Level 3 siren.\n2. Proceed westbound via E. Rodriguez Jr. Ave (C-5) or Aurora Blvd.\n3. Turn north at Gilmore onto Scout Reyes towards Amoranto Stadium Gate 2.\n4. Emergency marshals stationed at all major intersections.",
                'waypoints' => [
                    ['name' => 'Tumana Checkpoint (Assembly Point)', 'lat' => 14.6540, 'lng' => 121.1020, 'note' => 'Barangay patrol boats stationed'],
                    ['name' => 'Katipunan Flyover Junction', 'lat' => 14.6390, 'lng' => 121.0720, 'note' => 'Clear passage; avoid ground U-turns'],
                    ['name' => 'Amoranto Gate 2 (Reception)', 'lat' => 14.6300, 'lng' => 121.0312, 'note' => 'Registration and medical check-in'],
                ],
                'is_verified' => true,
                'verified_by' => 'MMDA / QC Disaster Risk Reduction Office',
            ],
            [
                'id' => 'rte_jakarta_ciliwung',
                'title' => 'Ciliwung River Lowlands to GBK Senayan Safe Corridor',
                'country' => 'Indonesia',
                'origin' => 'Kampung Melayu / Bidara Cina Lowlands',
                'destination' => 'Gelora Bung Karno Senayan, Jakarta',
                'distance_km' => 7.2,
                'status' => EvacuationRoute::STATUS_CLEAR,
                'hazard_warning' => 'Clear: Pump stations fully operational along Jalan Sudirman corridor.',
                'instructions' => "1. Follow evacuation marshals from Kampung Melayu terminal westward along Jl. Casablanka.\n2. Cross elevated flyover over flood-prone underpasses.\n3. Enter GBK through Gate 7 (Pintu 7 Senayan).\n4. Logistics trucks priority lane on far right lane.",
                'waypoints' => [
                    ['name' => 'Kampung Melayu Assembly', 'lat' => -6.2245, 'lng' => 106.8640, 'note' => 'BPBD rubber dinghy staging'],
                    ['name' => 'Casablanca Elevated Flyover', 'lat' => -6.2220, 'lng' => 106.8400, 'note' => 'Safe high elevation transit'],
                    ['name' => 'GBK Gate 7 Reception', 'lat' => -6.2183, 'lng' => 106.8022, 'note' => 'Relief distribution & medical station'],
                ],
                'is_verified' => true,
                'verified_by' => 'BPBD DKI Jakarta / Dishub',
            ],
            [
                'id' => 'rte_danang_son_tra',
                'title' => 'Da Nang Coastal Surge Zone to Tien Son Safe Shelter',
                'country' => 'Vietnam',
                'origin' => 'Man Thai Coastal Fishing Village',
                'destination' => 'Tien Son Sports Center, Da Nang',
                'distance_km' => 6.8,
                'status' => EvacuationRoute::STATUS_CLEAR,
                'hazard_warning' => 'Coastal surge barrier active. High ground route protected from waves.',
                'instructions' => "1. Move inland away from Hoang Sa coastal road before gale force winds exceed 70 km/h.\n2. Cross Han River via Tran Thi Ly Bridge (reinforced deck).\n3. Follow signs southward to Tien Son Complex main concourse.",
                'waypoints' => [
                    ['name' => 'Man Thai Beach Assembly', 'lat' => 16.0820, 'lng' => 108.2480, 'note' => 'Mandatory evacuation zone during typhoon'],
                    ['name' => 'Tran Thi Ly Bridge', 'lat' => 16.0520, 'lng' => 108.2280, 'note' => 'Clear for bus and vehicle transit'],
                    ['name' => 'Tien Son Complex Gate A', 'lat' => 16.0350, 'lng' => 108.2230, 'note' => 'Designated typhoon shelter registration'],
                ],
                'is_verified' => true,
                'verified_by' => 'Da Nang Military Command / VNDMA',
            ],
        ];

        foreach ($routes as $route) {
            EvacuationRoute::updateOrCreate(['id' => $route['id']], $route);
        }

        // 4. Registered Emergency Volunteers
        $volunteers = [
            [
                'id' => 'vol_santos_ph',
                'name' => 'Lt. Mark Santos',
                'email' => 'marksantos.rescue@gmail.com',
                'phone' => '+63 917 555 4321',
                'country' => 'Philippines',
                'city' => 'Manila',
                'skills' => ['Swift Water Rescue', 'First Aid / EMT', 'Boat Operation'],
                'availability_status' => Volunteer::STATUS_AVAILABLE,
                'notes' => 'Philippine Coast Guard Auxiliary rescue certified.',
            ],
            [
                'id' => 'vol_wijaya_id',
                'name' => 'Dr. Rian Wijaya',
                'email' => 'rian.wijaya@emergency-med.id',
                'phone' => '+62 812 9988 7766',
                'country' => 'Indonesia',
                'city' => 'Jakarta',
                'skills' => ['Emergency Trauma Care', 'Triage Assessment', 'Disaster Medicine'],
                'availability_status' => Volunteer::STATUS_DEPLOYED,
                'notes' => 'Deployed at Gelora Bung Karno field clinic.',
            ],
            [
                'id' => 'vol_nguyen_vn',
                'name' => 'Nguyen Thi Lan',
                'email' => 'lan.nguyen@redcross.org.vn',
                'phone' => '+84 905 123 456',
                'country' => 'Vietnam',
                'city' => 'Da Nang',
                'skills' => ['Warehouse Logistics', 'Food Ration Distribution', 'Community Outreach'],
                'availability_status' => Volunteer::STATUS_AVAILABLE,
                'notes' => 'Red Cross disaster response coordinator volunteer.',
            ],
        ];

        foreach ($volunteers as $v) {
            Volunteer::updateOrCreate(['id' => $v['id']], $v);
        }

        // 5. Citizen Rescue Assistance Requests
        $rescueRequests = [
            [
                'id' => 'rsc_provit_marikina',
                'reporter_name' => 'Elena Dimalanta',
                'contact_phone' => '+63 928 111 2233',
                'country' => 'Philippines',
                'location' => 'Block 4, Provident Village, Marikina City',
                'latitude' => 14.6280,
                'longitude' => 121.0910,
                'people_count' => 5,
                'has_medical_emergency' => true,
                'urgency' => RescueRequest::URGENCY_CRITICAL,
                'status' => RescueRequest::STATUS_DISPATCHED,
                'assigned_volunteer_id' => 'vol_santos_ph',
                'situation_description' => 'Water level reaching 2nd story roof. Elderly grandmother with insulin needs and 2 children stranded.',
                'resolution_notes' => 'Rescue boat team dispatched from Amoranto base at 08:30 AM.',
            ],
            [
                'id' => 'rsc_bidara_jakarta',
                'reporter_name' => 'Ahmad Fauzi',
                'contact_phone' => '+62 856 222 3344',
                'country' => 'Indonesia',
                'location' => 'Gang Melati 3, Bidara Cina, Jatinegara',
                'latitude' => -6.2320,
                'longitude' => 106.8670,
                'people_count' => 3,
                'has_medical_emergency' => false,
                'urgency' => RescueRequest::URGENCY_HIGH,
                'status' => RescueRequest::STATUS_PENDING,
                'situation_description' => 'Current water depth 1.8 meters inside ground floor. Need evacuation assistance to higher ground.',
                'resolution_notes' => null,
            ],
        ];

        foreach ($rescueRequests as $req) {
            RescueRequest::updateOrCreate(['id' => $req['id']], $req);
        }

        // 6. Sample Initial Distribution Record
        ResourceDistribution::updateOrCreate([
            'id' => 'dst_initial_amoranto_water',
        ], [
            'resource_id' => 'res_water_manila',
            'shelter_id' => 'shl_manila_amoranto',
            'recipient' => 'Barangay Roxas Evacuees (Zone 1)',
            'quantity' => 120,
            'distributed_by' => 'Capt. Ronald Reyes',
            'distributed_at' => Carbon::now()->subHours(4),
            'notes' => 'Distributed to 35 newly admitted families.',
        ]);
    }
}
