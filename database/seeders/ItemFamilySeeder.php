<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace Database\Seeders;

use App\Helpers\UPCGenerator;
use App\Models\Category;
use App\Models\ItemType;
use App\Models\PackageType;
use App\Models\Unit;
use Illuminate\Database\Seeder;

/**
 * Seeds the full family/variant item-number catalog from
 * HANDOFF-item-numbering.md (draft v2, prepared from the ACS Item Number
 * Master List). Idempotent — safe to re-run; only inserts rows that don't
 * already exist for a given (family, variant) pair.
 *
 * Deliberately NOT seeded here (numbers stay reserved by simply never being
 * created, matching the source document's own deferrals):
 * - 404-20 (diapers, cloth) — "reserved... don't print unless cloth shows up"
 * - 270-91..95 (adult diaper sized gaylords) — "create only if sized gaylords
 *   actually arrive"
 * - 110/112/116/118/120/122/123 -90 institutional variants — "create the -90
 *   only when gallon cans actually show up on the dock" (only 114-90 exists
 *   today)
 * - 762-10..50 (specialty battery decades) — reserved band, not yet used
 * - 950 tarp specific sizes beyond the ones the source document names
 * - 510-02/06, 525-02/06 (sheets/blankets Twin XL & Cal King) — bed-code
 *   slots reserved for consistency with the mattress family, not currently
 *   stocked
 */
class ItemFamilySeeder extends Seeder
{
    private const BED_CODES = [
        '01' => 'Twin',
        '02' => 'Twin XL',
        '03' => 'Full',
        '04' => 'Queen',
        '05' => 'King',
        '06' => 'Cal King',
    ];

    public function run(): void
    {
        foreach (self::families() as $row) {
            $this->createItemType($row);
        }
    }

    private function families(): array
    {
        $families = [];

        // ---- 0xx — Animal products & durable medical ----
        $families[] = ['0010', '00', 'Bird food', 'Bag'];
        $families[] = ['0020', '01', 'Cat food, dry', 'Bag'];
        $families[] = ['0020', '02', 'Cat food, wet (cans/pouches)', 'Case'];
        $families[] = ['0022', '00', 'Cat litter', 'Bag'];
        $families[] = ['0024', '00', 'Cat litter scoop', 'Case'];
        $families[] = ['0025', '00', 'Cat care', 'Case'];
        $families[] = ['0026', '00', 'Cat litter box / tray', 'Each'];
        $families[] = ['0030', '01', 'Dog food, dry', 'Bag'];
        $families[] = ['0030', '02', 'Dog food, wet (cans/pouches)', 'Case'];
        $families[] = ['0032', '00', 'Dog bones', 'Bag'];
        $families[] = ['0033', '00', 'Pet food / water bowl', 'Each'];
        $families[] = ['0034', '00', 'Dog care', 'Case'];
        $families[] = ['0042', '01', 'Horse feed', 'Bag']; // no bare row, matches 020/030 pattern
        $families[] = ['0052', '00', 'Collars, pet', 'Case'];
        $families[] = ['0054', '00', 'Leashes, dog/cat', 'Case'];
        $families[] = ['0056', '00', 'Kennels, dog/cat', 'Each'];
        $families[] = ['0060', '00', 'Pet food, misc (hamster, bunny, etc.)', 'Case'];
        $families[] = ['0061', '00', 'Pet supplies, misc', 'Case'];
        $families[] = ['0070', '00', 'Pet gate / playpen', 'Each'];
        $families[] = ['0081', '00', 'Wheelchairs', 'Each'];
        $families[] = ['0082', '00', 'Walkers', 'Each'];
        $families[] = ['0083', '00', 'Crutches / cane', 'Each'];
        $families[] = ['0084', '00', 'Bath chair', 'Each'];
        $families[] = ['0085', '00', 'Commode', 'Each'];

        // ---- 1xx — Food & water ----
        $families[] = ['0101', '00', 'Canned food, mixed', 'Case'];
        $families[] = ['0102', '00', 'MRE (meals ready to eat)', 'Case'];
        $families[] = ['0104', '00', 'Meal kits (not MREs)', 'Case'];
        $families[] = ['0105', '00', 'Mac & cheese', 'Case'];
        $families[] = ['0110', '00', 'Canned fruit', 'Case'];
        $families[] = ['0112', '00', 'Canned vegetables', 'Case'];
        $families[] = ['0114', '00', 'Canned beans', 'Case'];
        $families[] = ['0114', '90', 'Canned beans, #10 / gallon (institutional)', 'Case'];
        $families[] = ['0116', '00', 'Canned meats', 'Case'];
        $families[] = ['0118', '00', 'Canned pasta', 'Case'];
        $families[] = ['0120', '00', 'Canned soup', 'Case'];
        $families[] = ['0122', '00', 'Sauce / gravy', 'Case'];
        $families[] = ['0123', '00', 'Canned tomatoes', 'Case'];
        $families[] = ['0130', '00', 'Dried boxed food', 'Case'];
        $families[] = ['0132', '00', 'Cereals', 'Case'];
        $families[] = ['0134', '00', 'Dry pasta', 'Case'];
        $families[] = ['0136', '00', 'Dry beans', 'Case'];
        $families[] = ['0138', '00', 'Rice', 'Case'];
        $families[] = ['0140', '00', 'Peanut butter', 'Case'];
        $families[] = ['0141', '00', 'PB & jelly, mixed', 'Case'];
        $families[] = ['0142', '00', 'Jam / jelly', 'Case'];
        $families[] = ['0144', '00', 'Snacks, any kind', 'Case'];
        $families[] = ['0144', '10', 'Snacks, savory, any', 'Case'];
        $families[] = ['0144', '11', 'Chips', 'Case'];
        $families[] = ['0144', '12', 'Crackers', 'Case'];
        $families[] = ['0144', '20', 'Snacks, sweet, any', 'Case'];
        $families[] = ['0144', '21', 'Fruit cups', 'Case'];
        $families[] = ['0144', '22', 'Snack bars', 'Case'];
        $families[] = ['0150', '00', 'Salad dressing', 'Case'];
        $families[] = ['0151', '00', 'Vinegar', 'Case'];
        $families[] = ['0152', '00', 'Oil / shortening', 'Case'];
        $families[] = ['0153', '00', 'Flour', 'Case'];
        $families[] = ['0154', '00', 'Sugar', 'Case'];
        $families[] = ['0155', '00', 'Baking, misc', 'Case'];
        $families[] = ['0156', '00', 'Condiments', 'Case'];
        $families[] = ['0158', '00', 'Seasonings', 'Case'];
        $families[] = ['0159', '00', 'Mixed boxes, nonperishable', 'Case'];
        $families[] = ['0160', '00', 'Juices', 'Case'];
        $families[] = ['0161', '00', 'Coconut water', 'Case'];
        $families[] = ['0162', '00', 'Coffee', 'Case'];
        $families[] = ['0163', '00', 'Creamer', 'Case'];
        $families[] = ['0164', '00', 'Tea', 'Case'];
        $families[] = ['0166', '00', 'Rehydration drinks', 'Case'];
        $families[] = ['0168', '01', 'Supplement drink powders', 'Case'];
        $families[] = ['0168', '02', 'Supplement gummies', 'Case'];
        $families[] = ['0169', '00', 'Sparkling drinks', 'Case'];
        $families[] = ['0170', '00', 'Milk, canned / shelf-stable', 'Case'];
        $families[] = ['0171', '00', 'Milk, flavored / chocolate', 'Case'];
        $families[] = ['0172', '00', 'Milk, powdered', 'Case'];
        $families[] = ['0174', '00', 'Protein drinks', 'Case'];
        $families[] = ['0180', '00', 'Water', 'Case'];
        $families[] = ['0181', '00', 'Water, flavored', 'Case'];
        $families[] = ['0190', '00', 'Produce / perishable', 'Case', 'sort_hold'];

        // ---- 2xx — Personal care · pharmacy · safety ----
        $families[] = ['0200', '00', 'Hygiene kits (bathroom items only)', 'Each'];
        $families[] = ['0201', '00', 'Personal care, misc', 'Case'];
        $families[] = ['0202', '00', 'Toothbrushes', 'Case'];
        $families[] = ['0203', '00', 'Toothpaste', 'Case'];
        $families[] = ['0204', '00', 'Mouthwash', 'Case'];
        $families[] = ['0206', '00', 'Soap / body wash, mixed', 'Case'];
        $families[] = ['0206', '01', 'Bar soap', 'Case'];
        $families[] = ['0206', '02', 'Body wash', 'Case'];
        $families[] = ['0206', '03', 'Liquid hand soap, refill', 'Case'];
        $families[] = ['0206', '04', 'Liquid hand soap, pump dispenser', 'Case'];
        $families[] = ['0206', '90', 'Floor-stand soap dispenser', 'Case'];
        $families[] = ['0210', '00', 'Shampoo & conditioner, mixed', 'Case'];
        $families[] = ['0210', '01', 'Shampoo', 'Case'];
        $families[] = ['0210', '02', 'Conditioner', 'Case'];
        $families[] = ['0212', '00', 'Comb, brush', 'Case'];
        $families[] = ['0214', '00', 'Grooming', 'Case'];
        $families[] = ['0216', '00', 'Deodorant', 'Case'];
        $families[] = ['0218', '00', 'Shavers, shaving cream', 'Case'];
        $families[] = ['0220', '00', 'Feminine care products', 'Case'];
        $families[] = ['0221', '00', 'Male hygiene', 'Case'];
        $families[] = ['0222', '00', 'Lotions, powder', 'Case'];
        $families[] = ['0224', '00', 'Personal wipes', 'Case'];
        $families[] = ['0224', '99', 'Personal wipes, mixed gaylord', 'Gaylord'];
        $families[] = ['0226', '00', 'Sanitizer', 'Case'];
        $families[] = ['0230', '00', 'Denture products', 'Case'];
        $families[] = ['0232', '00', 'Eye care', 'Case'];
        $families[] = ['0240', '00', 'Sunglasses', 'Case'];
        $families[] = ['0241', '00', 'Personal care kit (beyond bathroom items)', 'Case'];
        $families[] = ['0242', '00', 'Sun block', 'Case'];
        $families[] = ['0250', '00', 'First aid kit', 'Case'];
        $families[] = ['0252', '00', 'First aid supplies', 'Case'];
        $families[] = ['0253', '00', 'Rehydration salts', 'Case'];
        $families[] = ['0254', '00', 'Gloves, disposable', 'Case'];
        $families[] = ['0258', '00', 'Protective suits', 'Case'];
        $families[] = ['0263', '00', 'OTC drugs, adult', 'Case'];
        $families[] = ['0265', '00', 'OTC drugs, child', 'Case'];
        $families[] = ['0270', '01', 'Adult diapers, case: Small', 'Case'];
        $families[] = ['0270', '02', 'Adult diapers, case: Medium', 'Case'];
        $families[] = ['0270', '03', 'Adult diapers, case: Large', 'Case'];
        $families[] = ['0270', '04', 'Adult diapers, case: XL', 'Case'];
        $families[] = ['0270', '05', 'Adult diapers, case: XXL', 'Case'];
        $families[] = ['0270', '99', 'Adult diapers, mixed gaylord', 'Gaylord'];
        $families[] = ['0272', '00', 'Bed liners / adult pads', 'Case'];
        $families[] = ['0276', '00', 'Medical supplies', 'Case'];
        $families[] = ['0278', '00', 'Isolation gown, hat, shoe covers', 'Case'];
        $families[] = ['0279', '00', 'Lab coat', 'Case'];
        $families[] = ['0280', '00', 'Head protection', 'Case'];
        $families[] = ['0282', '00', 'Gloves, work', 'Case'];
        $families[] = ['0283', '00', 'Boots, rubber', 'Case'];
        $families[] = ['0284', '00', 'PPE masks', 'Case'];
        $families[] = ['0286', '00', 'Eye protection', 'Case'];
        $families[] = ['0287', '00', 'Ear protection', 'Case'];

        // ---- 3xx — Paper & disposables ----
        $families[] = ['0300', '00', 'Disposables, misc', 'Case', 'sort_hold'];
        $families[] = ['0310', '00', 'Plates, disposable', 'Case'];
        $families[] = ['0312', '00', 'Bowls, disposable', 'Case'];
        $families[] = ['0314', '00', 'Cups, disposable', 'Case'];
        $families[] = ['0316', '00', 'Cutlery kits, disposable', 'Case'];
        $families[] = ['0318', '00', 'Paper towels, household rolls', 'Case'];
        $families[] = ['0318', '01', 'Paper towels, C-fold (dispenser)', 'Case'];
        $families[] = ['0318', '02', 'Paper towels, multi-fold (dispenser)', 'Case'];
        $families[] = ['0318', '03', 'Paper towels, single-fold packs', 'Case'];
        $families[] = ['0318', '90', 'Paper towels, industrial giant roll', 'Roll'];
        $families[] = ['0318', '91', 'Shop towels, boxed sheets', 'Case']; // was "Box" unit
        $families[] = ['0320', '00', 'Napkins, disposable', 'Case'];
        $families[] = ['0330', '00', 'Tissues', 'Case'];
        $families[] = ['0332', '00', 'Toilet paper, household', 'Case'];
        $families[] = ['0332', '90', 'Toilet paper, giant dispenser roll', 'Case'];
        $families[] = ['0340', '00', 'Table cloths', 'Case'];
        $families[] = ['0342', '00', 'Cooking / baking disposables', 'Case'];
        $families[] = ['0350', '00', 'Sandwich bags', 'Case'];
        $families[] = ['0352', '00', 'Garbage bags', 'Case'];
        $families[] = ['0354', '00', 'Wraps (aluminum, plastic, wax)', 'Case'];

        // ---- 4xx — Baby & child ----
        $families[] = ['0400', '00', 'Baby kit', 'Each'];
        $families[] = ['0401', '00', 'Baby supplies, misc', 'Case'];
        $families[] = ['0402', '00', 'Diaper bags', 'Each'];
        $families[] = ['0404', '00', 'Diapers, newborn', 'Case'];
        for ($size = 1; $size <= 8; $size++) {
            $families[] = ['0404', str_pad((string) $size, 2, '0', STR_PAD_LEFT), "Diapers, size {$size}", 'Case'];
        }
        $families[] = ['0404', '10', 'Diapers, overnight', 'Case'];
        $families[] = ['0404', '99', 'Diapers, mixed / gaylord', 'Gaylord'];
        $families[] = ['0410', '00', 'Baby wipes', 'Case'];
        $families[] = ['0412', '00', 'Baby washcloths', 'Case'];
        $families[] = ['0414', '00', 'Baby lotion', 'Case'];
        $families[] = ['0420', '00', 'Baby clothes', 'Case'];
        $families[] = ['0422', '00', 'Baby outerwear', 'Case'];
        $families[] = ['0424', '00', 'Baby blankets', 'Case'];
        $families[] = ['0430', '00', 'Nursing pads', 'Case'];
        $families[] = ['0440', '00', 'Baby food', 'Case'];
        $families[] = ['0442', '00', 'Baby formula, powder', 'Case'];
        $families[] = ['0442', '01', 'Baby formula, liquid', 'Case'];
        $families[] = ['0443', '00', 'Toddler milk', 'Case'];
        $families[] = ['0444', '00', 'PediaSure', 'Case'];
        $families[] = ['0446', '00', 'Pedialyte', 'Case'];
        $families[] = ['0448', '00', 'Baby juice', 'Case'];
        $families[] = ['0450', '00', 'Baby water', 'Case'];
        $families[] = ['0460', '00', 'Baby bottles', 'Case'];
        $families[] = ['0462', '00', 'Pacifiers & baby toys', 'Case'];
        $families[] = ['0470', '00', 'Pull-ups', 'Case'];
        $families[] = ['0480', '00', "Children's disaster kits", 'Case'];
        $families[] = ['0482', '00', 'Games, crafts', 'Case'];
        $families[] = ['0484', '00', 'Plush toys', 'Case'];
        $families[] = ['0486', '00', 'Toys, all', 'Case'];
        $families[] = ['0488', '00', 'Toy dolls', 'Case', 'retired'];
        $families[] = ['0490', '00', 'School kits', 'Each'];
        $families[] = ['0492', '00', 'School supplies', 'Case'];
        $families[] = ['0494', '00', 'Car seats, children', 'Each'];
        $families[] = ['0498', '00', 'Safety equipment', 'Case'];

        // ---- 5xx — Bedding & linens ----
        $families[] = ['0500', '00', 'Bedroom kits', 'Each'];
        $families[] = ['0501', '00', 'Linens, misc', 'Case'];
        $families[] = ['0502', '00', 'Bedding kits', 'Each'];
        foreach (['01', '03', '04', '05'] as $code) {
            $families[] = ['0510', $code, 'Sheets, '.self::BED_CODES[$code], 'Case'];
        }
        $families[] = ['0510', '99', 'Sheets, mixed', 'Case'];
        $families[] = ['0520', '00', 'Pillow cases, regular', 'Case'];
        $families[] = ['0522', '00', 'Pillow cases, king', 'Case'];
        $families[] = ['0524', '00', 'Pillows', 'Case'];
        foreach (['01', '03', '04', '05'] as $code) {
            $families[] = ['0525', $code, 'Blankets, '.self::BED_CODES[$code], 'Case'];
        }
        $families[] = ['0525', '99', 'Blankets, mixed', 'Case'];
        $families[] = ['0531', '00', 'Disaster blankets', 'Case'];
        $families[] = ['0532', '00', 'Throws (small blankets)', 'Case'];
        $families[] = ['0533', '00', 'Shipping blankets', 'Case'];
        $families[] = ['0534', '00', 'Bed liner', 'Case'];
        foreach (self::BED_CODES as $code => $label) {
            $families[] = ['0540', $code, "Mattress toppers, {$label}", 'Case'];
        }
        $families[] = ['0540', '99', 'Mattress toppers, mixed', 'Case'];
        $families[] = ['0550', '00', 'Bathroom kits', 'Each'];
        $families[] = ['0560', '00', 'Wash cloths', 'Case'];
        $families[] = ['0562', '00', 'Towels, hand', 'Case'];
        $families[] = ['0564', '00', 'Towels, bath', 'Case'];
        $families[] = ['0566', '00', 'Towels, misc', 'Case'];
        $families[] = ['0570', '00', 'Shower curtains', 'Case'];
        $families[] = ['0572', '00', 'Bathroom rugs', 'Case'];
        $families[] = ['0582', '00', 'Pillows, decorative', 'Case'];
        $families[] = ['0584', '00', 'Curtains', 'Case'];

        // ---- 6xx — Kitchen & dining ----
        $families[] = ['0600', '00', 'Cooking kit', 'Each'];
        $families[] = ['0601', '00', 'Kitchen, misc', 'Case', 'sort_hold'];
        $families[] = ['0610', '00', 'Kitchen start-up kit', 'Each'];
        $families[] = ['0612', '00', 'Plates', 'Case'];
        $families[] = ['0614', '00', 'Bowls', 'Case'];
        $families[] = ['0615', '00', 'Dinnerware sets', 'Case'];
        $families[] = ['0616', '00', 'Cups, glasses', 'Case'];
        $families[] = ['0617', '00', 'Beverage tumblers, plastic', 'Case'];
        $families[] = ['0618', '00', 'Flatware', 'Case'];
        $families[] = ['0620', '00', 'Serving platters', 'Case'];
        $families[] = ['0622', '00', 'Serving utensils', 'Case'];
        $families[] = ['0630', '00', 'Pots / pans', 'Case'];
        $families[] = ['0632', '00', 'Cooking accessories', 'Case'];
        $families[] = ['0634', '00', 'Food containers', 'Case'];
        $families[] = ['0636', '00', 'Water filters', 'Each'];
        $families[] = ['0640', '00', 'Kitchen towels', 'Case'];
        $families[] = ['0650', '00', 'Air fryer', 'Each'];
        $families[] = ['0655', '00', 'Pressure / instant pots', 'Each'];

        // ---- 7xx — Cleaning & household ----
        $families[] = ['0700', '00', 'Household kits', 'Each'];
        $families[] = ['0705', '00', 'Clean-up kits', 'Each'];
        $families[] = ['0706', '00', 'Moisture absorbers', 'Case'];
        $families[] = ['0710', '00', 'Flood buckets', 'Each'];
        $families[] = ['0720', '00', 'Laundry detergent', 'Case'];
        $families[] = ['0721', '00', 'Dish soap', 'Case'];
        $families[] = ['0722', '00', 'Cleaners, misc', 'Case'];
        $families[] = ['0722', '01', 'Glass cleaner spray', 'Case'];
        $families[] = ['0722', '02', 'Degreaser spray', 'Case'];
        $families[] = ['0723', '00', 'Outdoor cleaners', 'Case'];
        $families[] = ['0724', '00', 'Bleach / OxiClean', 'Case'];
        $families[] = ['0725', '00', 'General-purpose cleaner spray', 'Case'];
        $families[] = ['0726', '00', 'Disinfectant wipes', 'Case'];
        $families[] = ['0728', '00', 'Cleaning sponges', 'Case'];
        $families[] = ['0729', '00', 'Disinfectant aerosol', 'Case'];
        $families[] = ['0730', '00', 'Gloves, cleaning', 'Case'];
        $families[] = ['0732', '00', 'Dust masks', 'Case'];
        $families[] = ['0740', '00', 'Trash / garbage cans', 'Each'];
        $families[] = ['0741', '00', 'Cleaning spray bottles', 'Case'];
        $families[] = ['0742', '00', 'Buckets / pails, empty', 'Each'];
        $families[] = ['0744', '00', 'Mop / broom / dustpan', 'Each'];
        $families[] = ['0746', '00', 'Floor care chemicals', 'Case'];
        $families[] = ['0748', '00', 'Laundry baskets', 'Each'];
        $families[] = ['0749', '00', 'Laundry bags', 'Case'];
        $families[] = ['0750', '00', 'Clothesline, pins', 'Case'];
        $families[] = ['0760', '00', 'Flashlights', 'Case'];
        $families[] = ['0761', '00', 'Phone chargers', 'Case'];
        $families[] = ['0762', '00', 'Batteries, mixed/any', 'Case'];
        $families[] = ['0762', '01', 'Batteries, AAA', 'Case'];
        $families[] = ['0762', '02', 'Batteries, AA', 'Case'];
        $families[] = ['0762', '03', 'Batteries, C', 'Case'];
        $families[] = ['0762', '04', 'Batteries, D', 'Case'];
        $families[] = ['0762', '09', 'Batteries, 9-volt', 'Case'];
        $families[] = ['0762', '60', 'Power banks', 'Case'];
        $families[] = ['0763', '00', 'Lighters / matches', 'Case'];
        $families[] = ['0764', '00', 'Candles', 'Case'];
        $families[] = ['0765', '00', 'Fire extinguishers', 'Each'];
        $families[] = ['0766', '00', 'Umbrellas / ponchos', 'Case'];
        $families[] = ['0767', '00', 'CO2 detectors', 'Case'];
        $families[] = ['0768', '00', 'Backpacks / totes', 'Case'];
        $families[] = ['0769', '00', 'Luggage / suitcases', 'Each'];
        $families[] = ['0770', '00', 'Boxes, unmade', 'Case'];
        $families[] = ['0771', '00', 'Plastic bins', 'Case'];
        $families[] = ['0772', '00', 'Packing tape', 'Case'];
        $families[] = ['0780', '00', 'Musical instruments', 'Each'];
        $families[] = ['0781', '00', 'Computers', 'Each'];
        $families[] = ['0782', '00', 'Books', 'Case'];
        $families[] = ['0783', '00', 'Videos, DVDs', 'Case'];
        $families[] = ['0784', '00', 'Wrist watches', 'Case'];
        $families[] = ['0785', '00', 'Electronics, misc', 'Case'];
        $families[] = ['0786', '00', 'Radios, portable', 'Each'];
        $families[] = ['0787', '00', 'Wall mirrors', 'Each'];
        $families[] = ['0788', '00', 'Christmas trees', 'Each'];
        $families[] = ['0789', '00', 'Holiday decorations', 'Case'];
        $families[] = ['0791', '00', 'Household, misc', 'Case'];
        $families[] = ['0792', '00', 'Irons (clothes)', 'Each'];
        $families[] = ['0793', '00', 'Utility knives', 'Case'];

        // ---- 8xx — Clothing & accessories ----
        $families[] = ['0800', '00', 'Clothing packs', 'Case'];
        $families[] = ['0801', '00', 'Clothing, misc', 'Case'];
        $families[] = ['0810', '00', 'Clothing, men/boys', 'Case'];
        $families[] = ['0812', '00', 'Sweaters/jackets, men/boys', 'Case'];
        $families[] = ['0814', '00', 'Coats, men/boys', 'Case'];
        $families[] = ['0820', '00', 'Clothing, women/girls', 'Case'];
        $families[] = ['0822', '00', 'Sweaters/jackets, women/girls', 'Case'];
        $families[] = ['0823', '00', "Clothing, children's", 'Case'];
        $families[] = ['0824', '00', 'Coats, women/girls', 'Case'];
        $families[] = ['0830', '00', 'Underwear, men/boys', 'Case'];
        $families[] = ['0832', '00', 'Underwear, women/girls', 'Case'];
        $families[] = ['0834', '00', 'T-shirts', 'Case'];
        $families[] = ['0840', '00', 'Socks, men/boys', 'Case'];
        $families[] = ['0842', '00', 'Socks, women/girls', 'Case'];
        $families[] = ['0850', '00', 'Footwear, men/boys', 'Case'];
        $families[] = ['0852', '00', 'Footwear, women/girls', 'Case'];
        $families[] = ['0860', '00', "Accessories, men's", 'Case'];
        $families[] = ['0862', '00', "Accessories, women's", 'Case'];
        $families[] = ['0864', '00', 'Hangers', 'Case'];
        $families[] = ['0870', '00', 'Hats / beanies / visors', 'Case'];
        $families[] = ['0899', '00', 'Unsorted clothes', 'Pallet', 'sort_hold'];

        // ---- 9xx — Outdoor · emergency · tools ----
        $families[] = ['0900', '00', 'Evacuation kits', 'Each'];
        $families[] = ['0901', '00', 'Outdoor, misc', 'Case', 'sort_hold'];
        $families[] = ['0902', '00', 'Rope (clothesline, camping — 100 ft)', 'Each'];
        $families[] = ['0904', '00', 'Tents, canopies', 'Each'];
        $families[] = ['0905', '01', 'Grill fuels, charcoal', 'Bag'];
        $families[] = ['0905', '02', 'Grill fuels, lighter fluid', 'Case'];
        $families[] = ['0905', '03', 'Grill fuels, propane', 'Each'];
        $families[] = ['0906', '00', 'Sleeping bags', 'Each'];
        $families[] = ['0907', '00', 'Hand warmers', 'Case'];
        $families[] = ['0908', '00', 'Camping chairs', 'Each'];
        $families[] = ['0909', '00', 'Air / foam mattresses', 'Each'];
        $families[] = ['0910', '00', 'Cots, portable beds', 'Each'];
        $families[] = ['0912', '00', 'Camp stoves / grills', 'Each'];
        $families[] = ['0914', '00', 'Portable toilets', 'Each'];
        $families[] = ['0915', '00', 'Kerosene heaters', 'Each'];
        $families[] = ['0916', '00', 'Coolers', 'Each'];
        $families[] = ['0917', '00', 'Water jugs', 'Each'];
        $families[] = ['0918', '00', 'Storage containers, plastic', 'Each'];
        $families[] = ['0920', '00', 'Repellent / pest control', 'Case'];
        $families[] = ['0926', '00', 'Sporting goods', 'Case'];
        $families[] = ['0930', '00', 'Gardening tools', 'Case'];
        $families[] = ['0932', '00', 'Carts, wheelbarrows', 'Each'];
        $families[] = ['0938', '00', 'Roof tarping kit (nails, hammer, instructions)', 'Each'];
        $families[] = ['0939', '00', 'Roofing nails', 'Case'];
        $families[] = ['0940', '00', 'Hand tools', 'Case'];
        $families[] = ['0941', '00', 'Chainsaws', 'Each'];
        $families[] = ['0942', '00', 'Power tools', 'Each'];
        $families[] = ['0943', '00', 'Extension cords', 'Each'];
        $families[] = ['0944', '00', 'Shop vacs', 'Each'];
        $families[] = ['0946', '00', 'Automotive, misc', 'Case'];
        $families[] = ['0947', '00', 'Motor oil', 'Each'];
        $families[] = ['0947', '01', '2-cycle oil', 'Case'];
        $families[] = ['0947', '02', 'Bar & chain oil', 'Each'];
        $families[] = ['0950', '10', 'Tarps, small', 'Each'];
        $families[] = ['0950', '11', 'Tarps, 8×10', 'Each'];
        $families[] = ['0950', '12', 'Tarps, 8×12', 'Each'];
        $families[] = ['0950', '20', 'Tarps, medium', 'Each'];
        $families[] = ['0950', '21', 'Tarps, 16×20', 'Each'];
        $families[] = ['0950', '22', 'Tarps, 18×24', 'Each'];
        $families[] = ['0950', '30', 'Tarps, large', 'Each'];
        $families[] = ['0950', '31', 'Tarps, 20×30', 'Each'];
        $families[] = ['0950', '32', 'Tarps, 20×40', 'Each'];
        $families[] = ['0950', '40', 'Tarps, extra large', 'Each'];
        $families[] = ['0950', '41', 'Tarps, 40×60', 'Each'];
        $families[] = ['0950', '90', 'Tarps, roll (bulk material)', 'Roll'];
        $families[] = ['0950', '99', 'Tarps, mixed / all sizes', 'Each'];
        $families[] = ['0952', '00', 'Plastic sheeting', 'Each'];
        $families[] = ['0960', '00', 'Painting supplies', 'Case'];
        $families[] = ['0964', '00', 'Tape (electrical / duct / masking)', 'Case'];
        $families[] = ['0970', '00', 'Ladders', 'Each'];
        $families[] = ['0978', '00', 'Sump pumps', 'Each'];
        $families[] = ['0980', '00', 'Generators', 'Each'];
        $families[] = ['0982', '00', 'Fuel containers', 'Each'];
        $families[] = ['0982', '01', 'Fuel containers, kerosene', 'Each'];
        $families[] = ['0984', '00', 'Portable lights', 'Each'];
        $families[] = ['0986', '00', 'Air pumps', 'Each'];
        $families[] = ['0988', '00', 'Bicycles', 'Each'];
        $families[] = ['0990', '00', 'Overnight kits', 'Each'];
        $families[] = ['0991', '00', 'Communication equipment', 'Case'];
        $families[] = ['0994', '00', 'Casket, adult (Funeral)', 'Each'];
        $families[] = ['0995', '00', 'Gift cards', 'Each'];
        $families[] = ['0996', '00', 'Empty pallets', 'Each'];
        $families[] = ['0997', '00', 'Unsorted donations', 'Pallet', 'sort_hold'];
        $families[] = ['0998', '00', 'Non-distributable, non-clothing', 'Pallet', 'sort_hold'];
        $families[] = ['0999', '00', 'Non-distributable, clothing', 'Pallet', 'sort_hold'];

        // ---- 1xxx — Appliances (4-digit families) ----
        $families[] = ['1000', '00', 'Large appliances, misc', 'Each'];
        $families[] = ['1100', '00', 'Washing machines', 'Each'];
        $families[] = ['1155', '00', 'Clothes dryers', 'Each'];
        $families[] = ['1201', '00', 'Refrigerators', 'Each'];
        $families[] = ['1225', '00', 'Stoves / ranges & ovens', 'Each'];
        $families[] = ['1230', '00', 'Range hoods', 'Each'];
        $families[] = ['1275', '00', 'Dishwashers', 'Each'];
        $families[] = ['1301', '00', 'Hot water heaters', 'Each'];
        $families[] = ['1500', '00', 'Small appliances, misc', 'Each'];
        $families[] = ['1505', '00', 'Space heaters', 'Each'];
        $families[] = ['1525', '00', 'Microwaves', 'Each'];
        $families[] = ['1550', '00', 'Toasters', 'Each'];
        $families[] = ['1555', '00', 'Toaster ovens', 'Each'];
        $families[] = ['1575', '00', 'Coffeemakers', 'Each'];
        $families[] = ['1610', '00', 'Electric fans', 'Each'];

        // ---- 2xxx — Building supplies (4-digit families) ----
        $families[] = ['2202', '00', 'Floor tile', 'Case'];
        $families[] = ['2203', '00', 'Wall base trim', 'Case'];
        $families[] = ['2212', '00', 'Floor tile, vinyl', 'Case'];
        $families[] = ['2214', '00', 'Floor tile, ceramic', 'Case'];
        $families[] = ['2218', '00', 'Flooring, wood', 'Case'];
        $families[] = ['2230', '00', 'Carpet squares', 'Case'];
        $families[] = ['2302', '00', 'Sheetrock', 'Each'];
        $families[] = ['2310', '00', 'Spackling compound', 'Case'];
        $families[] = ['2332', '00', 'Wall tile, ceramic', 'Case'];
        $families[] = ['2380', '00', 'Roofing, aluminum', 'Case'];
        $families[] = ['2502', '00', 'Cabinetry', 'Each'];
        $families[] = ['2510', '00', 'Kitchen cabinets, upper', 'Each'];
        $families[] = ['2512', '00', 'Kitchen cabinets, upper corner', 'Each'];
        $families[] = ['2520', '00', 'Kitchen cabinets, lower', 'Each'];
        $families[] = ['2522', '00', 'Kitchen cabinets, lower corner', 'Each'];
        $families[] = ['2530', '00', 'Kitchen sinks', 'Each'];
        $families[] = ['2552', '00', 'Bathroom vanities', 'Each'];
        $families[] = ['2610', '00', 'Doors, interior', 'Each'];
        $families[] = ['2612', '00', 'Doors, interior panel', 'Each'];
        $families[] = ['2614', '00', 'Doors, interior bi-fold', 'Each'];
        $families[] = ['2630', '00', 'Doors, exterior misc', 'Each'];
        $families[] = ['2650', '00', 'Windows, misc', 'Each'];
        $families[] = ['2750', '00', 'Hot tubs', 'Each'];
        $families[] = ['2812', '00', 'Wood clamps', 'Each'];

        // ---- 3xxx — Furniture (4-digit families) ----
        $families[] = ['3100', '00', 'Furniture parts', 'Case'];
        $families[] = ['3121', '00', 'Chairs, dining', 'Each'];
        $families[] = ['3125', '00', 'Tables, dining', 'Each'];
        $families[] = ['3141', '00', 'TV tables / trays', 'Each'];
        $families[] = ['3150', '00', 'Buffets', 'Each'];
        $families[] = ['3207', '00', 'Chairs, office', 'Each'];
        $families[] = ['3305', '00', 'Chairs, indoor folding', 'Each'];
        $families[] = ['3307', '00', 'Couches / sofas', 'Each'];
        $families[] = ['3310', '00', 'Benches', 'Each'];
        $families[] = ['3320', '00', 'Entertainment centers', 'Each'];
        $families[] = ['3341', '00', 'End tables', 'Each'];
        $families[] = ['3345', '00', 'Coffee tables', 'Each'];
        $families[] = ['3350', '00', 'Lamps', 'Each'];
        foreach (self::BED_CODES as $code => $label) {
            $families[] = ['3401', $code, "Mattresses, {$label}", 'Each'];
        }
        $families[] = ['3401', '99', 'Mattresses, mixed/unknown', 'Each'];
        foreach (self::BED_CODES as $code => $label) {
            $families[] = ['3411', $code, "Box springs, {$label}", 'Each'];
        }
        $families[] = ['3411', '99', 'Box springs, mixed/unknown', 'Each'];
        $families[] = ['3425', '00', 'Bed frames', 'Each'];
        $families[] = ['3451', '00', 'Chests of drawers', 'Each'];
        $families[] = ['3924', '00', 'Folding card tables', 'Each'];

        // ---- 7777 — Holy Bible ----
        $families[] = ['7777', '00', 'Holy Bible', 'Each'];

        return $families;
    }

    private function createItemType(array $row): void
    {
        [$family, $variant, $name, $packageTypeName] = $row;
        $status = $row[4] ?? 'orderable';

        $existing = ItemType::where('family', $family)->where('variant', $variant)->first();
        if ($existing) {
            return;
        }

        $category = $this->categoryForFamily($family);
        $unit = Unit::where('abbreviation', 'each')->first();
        $packageType = PackageType::where('singular', $packageTypeName)->first();

        $itemType = ItemType::create([
            'family' => $family,
            'variant' => $variant,
            'status' => $status,
            'category_id' => $category?->id,
            'unit_id' => $unit?->id,
            'name' => $name,
            'active' => $status !== 'retired',
        ]);

        if ($status === 'retired') {
            return; // no generic item for a retired number
        }

        $itemType->items()->create([
            'packagetypes_id' => $packageType?->id,
            'pluscode' => '0000',
            'size' => 1.0,
            'case_qty' => 1,
            'active' => true,
            'description' => $name.' GENERIC ITEM',
            'upc' => UPCGenerator::makeUPC($family, $variant),
        ]);
    }

    private function categoryForFamily(string $family): ?Category
    {
        $value = (int) $family;

        return Category::query()
            ->where('block_start', '<=', $value)
            ->where('block_end', '>=', $value)
            ->first();
    }
}
