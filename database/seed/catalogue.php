<?php

declare(strict_types=1);

// Invented catalogue for a regional radio OB store. Generic descriptions only:
// no manufacturers, stations, clubs or venues.
return [
    // code => [name, category, serial prefix, how many the store holds]
    'gear' => [
        'CODEC-IP' => ['IP audio codec', 'Contribution', 'CDC', 16],
        'ROUTER-4G' => ['Bonded 4G router', 'Contribution', 'RTR', 12],
        'MIXER-6' => ['6-channel field mixer', 'Mixing', 'MXR', 14],
        'HP-AMP' => ['4-way headphone amp', 'Mixing', 'HPA', 16],
        'DI-BOX' => ['DI box', 'Mixing', 'DIB', 12],
        'HEADSET-COM' => ['Commentary headset', 'Commentary', 'HSC', 40],
        'MIC-LIP' => ['Lip ribbon mic', 'Commentary', 'LIP', 36],
        'STAND-DESK' => ['Desk mic stand', 'Commentary', 'STD', 36],
        'MIC-HH' => ['Handheld dynamic mic', 'Microphones', 'MHH', 30],
        'MIC-RADIO' => ['Radio mic kit', 'Microphones', 'MRD', 16],
        'IFB-TX' => ['IFB transmitter', 'Talkback', 'IFT', 10],
        'IFB-RX' => ['IFB belt receiver', 'Talkback', 'IFR', 24],
        'REC-FIELD' => ['Field recorder', 'Recording', 'REC', 18],
        'LAPTOP-PO' => ['Playout laptop', 'Playout', 'LPO', 8],
        'UPS-PORT' => ['Portable UPS', 'Power', 'UPS', 12],
    ],

    // code => [name, unit, reorder point, typical delivery size, lot prefix]
    'consumables' => [
        'AA-BATT' => ['AA battery', 'each', 120, 240, 'PWR'],
        '9V-BATT' => ['9V battery', 'each', 24, 48, 'PWN'],
        'XLR-5M' => ['XLR cable, 5 m', 'each', 30, 60, 'CBL'],
        'GAFFER' => ['Gaffer tape, 50 mm', 'roll', 12, 24, 'TPE'],
        'SD-64' => ['SD card, 64 GB', 'each', 16, 40, 'MEM'],
        'WINDSHIELD' => ['Foam windshield', 'each', 20, 50, 'FWS'],
        'CABLE-TIE' => ['Hook-and-loop cable tie', 'each', 60, 200, 'CTY'],
        'EAR-TIP' => ['IFB earpiece tip', 'each', 40, 100, 'EAR'],
    ],

    // code => [name, description, is_subkit, lines]
    // A line is [target, qty, per_position]; target is gear:CODE, item:CODE or kit:CODE.
    // Sub-kits come first so top-level templates can refer to them.
    'templates' => [
        'COMM-POS' => ['Commentary position', 'One commentator: headset, lip mic, stand and cabling.', true, [
            ['gear:HEADSET-COM', 1, false],
            ['gear:MIC-LIP', 1, false],
            ['gear:STAND-DESK', 1, false],
            ['item:XLR-5M', 2, false],
            ['item:WINDSHIELD', 1, false],
        ]],
        'FOOTY-BOX' => ['Footy commentary box', 'Ground commentary: one codec and mixer, N commentary positions.', false, [
            ['gear:CODEC-IP', 1, false],
            ['gear:ROUTER-4G', 1, false],
            ['gear:MIXER-6', 1, false],
            ['gear:HP-AMP', 1, false],
            ['gear:UPS-PORT', 1, false],
            ['item:GAFFER', 1, false],
            ['item:SD-64', 1, false],
            ['item:CABLE-TIE', 10, false],
            ['kit:COMM-POS', 1, true],
            ['gear:IFB-RX', 1, true],
            ['item:EAR-TIP', 2, true],
        ]],
        'CRICKET-BOX' => ['Cricket commentary box', 'All-day box: adds playout and spare media. N commentary positions.', false, [
            ['gear:CODEC-IP', 1, false],
            ['gear:ROUTER-4G', 1, false],
            ['gear:MIXER-6', 1, false],
            ['gear:HP-AMP', 1, false],
            ['gear:LAPTOP-PO', 1, false],
            ['gear:UPS-PORT', 1, false],
            ['gear:IFB-TX', 1, false],
            ['item:GAFFER', 2, false],
            ['item:SD-64', 2, false],
            ['kit:COMM-POS', 1, true],
            ['gear:IFB-RX', 1, true],
            ['item:EAR-TIP', 2, true],
        ]],
        'STREET-PACK' => ['Street talk backpack', 'Roving vox pops. One codec and router; N reporter crews.', false, [
            ['gear:CODEC-IP', 1, false],
            ['gear:ROUTER-4G', 1, false],
            ['gear:REC-FIELD', 1, true],
            ['gear:MIC-HH', 1, true],
            ['item:AA-BATT', 4, true],
            ['item:SD-64', 1, true],
            ['item:WINDSHIELD', 1, true],
        ]],
        'BREAKFAST-OB' => ['Breakfast show roadshow', 'Presenter desk on location; N guest seats.', false, [
            ['gear:CODEC-IP', 1, false],
            ['gear:ROUTER-4G', 1, false],
            ['gear:MIXER-6', 1, false],
            ['gear:HP-AMP', 1, false],
            ['gear:LAPTOP-PO', 1, false],
            ['gear:UPS-PORT', 1, false],
            ['gear:IFB-TX', 1, false],
            ['item:GAFFER', 1, false],
            ['gear:MIC-HH', 1, true],
            ['gear:STAND-DESK', 1, true],
            ['item:XLR-5M', 1, true],
            ['gear:IFB-RX', 1, true],
            ['item:EAR-TIP', 2, true],
        ]],
        'LIVE-MUSIC' => ['Live music session', 'Small acoustic session; N performers.', false, [
            ['gear:CODEC-IP', 1, false],
            ['gear:ROUTER-4G', 1, false],
            ['gear:MIXER-6', 2, false],
            ['gear:REC-FIELD', 1, false],
            ['gear:UPS-PORT', 1, false],
            ['item:GAFFER', 2, false],
            ['gear:MIC-HH', 2, true],
            ['gear:DI-BOX', 1, true],
            ['item:XLR-5M', 3, true],
        ]],
        'RACE-DAY' => ['Race day commentary', 'Two codecs for redundancy, roving radio mics; N commentary positions.', false, [
            ['gear:CODEC-IP', 2, false],
            ['gear:ROUTER-4G', 1, false],
            ['gear:MIXER-6', 1, false],
            ['gear:HP-AMP', 1, false],
            ['gear:MIC-RADIO', 2, false],
            ['item:9V-BATT', 4, false],
            ['kit:COMM-POS', 1, true],
            ['item:AA-BATT', 4, true],
        ]],
    ],
];
