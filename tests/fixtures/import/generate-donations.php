<?php
mt_srand( 4242 );

$out_path = '/Users/ecairol/www/giving-day/wp-content/plugins/giving-day-blocks/tests/fixtures/import/test-donations.csv';

$campaign_slug = 'sample-giving-day-2026';

$beneficiary_slugs = array(
	'college-of-arts-and-sciences',
	'college-of-engineering',
	'college-of-sciences',
	'library-fund',
	'music-department-fund',
	'drama-program-fund',
	'writing-center',
	'history-department-fund',
	'robotics-lab',
	'computer-science-department',
	'materials-science-fund',
	'renewable-energy-lab',
	'engineering-scholarships',
	'center-for-planetary-studies',
	'marine-biology-fund',
	'sustainable-agriculture-institute',
	'physics-department',
	'mathematics-department',
	'annual-fund',
	'general-scholarship-fund',
	'football-scholarship',
	'basketball-scholarship',
	'mental-health-services',
	'diversity-and-inclusion',
	'career-center',
);

$team_slugs = array(
	'class-of-2014','class-of-2015','class-of-2016','class-of-2017','class-of-2018',
	'class-of-2019','class-of-2020','class-of-2021','class-of-2022','class-of-2023',
	'football','basketball','soccer','swimming','baseball',
	'tennis','track-and-field','volleyball','lacrosse','golf',
);

$first_names = array(
	'Jane','John','Sarah','Michael','Emma','David','Olivia','James','Ava','Robert',
	'Sophia','William','Isabella','Daniel','Mia','Joseph','Charlotte','Thomas','Amelia','Christopher',
	'Harper','Matthew','Evelyn','Anthony','Abigail','Mark','Emily','Donald','Elizabeth','Steven',
	'Sofia','Paul','Madison','Andrew','Avery','Joshua','Ella','Kenneth','Scarlett','Kevin',
);
$last_names = array(
	'Smith','Johnson','Williams','Brown','Jones','Garcia','Miller','Davis','Rodriguez','Martinez',
	'Hernandez','Lopez','Gonzalez','Wilson','Anderson','Thomas','Taylor','Moore','Jackson','Martin',
	'Lee','Perez','Thompson','White','Harris','Sanchez','Clark','Ramirez','Lewis','Robinson',
	'Walker','Young','Allen','King','Wright','Scott','Torres','Nguyen','Hill','Flores',
);

function weighted_pick( array $weighted ) {
	$total = 0;
	foreach ( $weighted as $w ) {
		$total += $w[1];
	}
	$r   = mt_rand( 1, $total );
	$acc = 0;
	foreach ( $weighted as $w ) {
		$acc += $w[1];
		if ( $r <= $acc ) {
			return $w[0];
		}
	}
	return $weighted[ count( $weighted ) - 1 ][0];
}

$amount_weights = array(
	array( 25, 30 ),
	array( 50, 25 ),
	array( 100, 20 ),
	array( 250, 10 ),
	array( 500, 5 ),
	array( 1000, 5 ),
	array( 2500, 3 ),
	array( 5000, 2 ),
);

$tender_weights = array(
	array( 'check', 50 ),
	array( 'cash', 30 ),
	array( 'other', 20 ),
);

$other_references = array( 'wire transfer', 'venmo', 'Stripe', 'apple pay', 'in-kind' );
$notes_pool       = array( 'Gala donation', 'Recurring donor', 'Pledge fulfilled', 'Walk-in donation', 'Mailed-in pledge' );

$base_ts = strtotime( '2026-03-15 00:00:00 UTC' );

$f = fopen( $out_path, 'w' );
fwrite( $f, "\xEF\xBB\xBF" );
fputcsv(
	$f,
	array(
		'external_id',
		'campaign_slug',
		'beneficiary_slug',
		'team_slug',
		'amount',
		'donor_name',
		'donor_email',
		'donation_date',
		'tender',
		'reference',
		'anonymous',
		'notes',
	)
);

$used_emails = array();

for ( $i = 1; $i <= 500; $i++ ) {
	$external_id = sprintf( 'import-%04d', $i );

	$beneficiary = ( mt_rand( 1, 100 ) <= 70 )
		? $beneficiary_slugs[ mt_rand( 0, count( $beneficiary_slugs ) - 1 ) ]
		: '';

	$team = ( mt_rand( 1, 100 ) <= 35 )
		? $team_slugs[ mt_rand( 0, count( $team_slugs ) - 1 ) ]
		: '';

	$amount = (int) weighted_pick( $amount_weights );

	$first = $first_names[ mt_rand( 0, count( $first_names ) - 1 ) ];
	$last  = $last_names[ mt_rand( 0, count( $last_names ) - 1 ) ];
	$name  = "$first $last";

	$base_email = strtolower( $first . '.' . $last ) . '@example.com';
	$email      = $base_email;
	$suffix     = 1;
	while ( isset( $used_emails[ $email ] ) ) {
		++$suffix;
		$email = strtolower( $first . '.' . $last . $suffix ) . '@example.com';
	}
	$used_emails[ $email ] = true;

	$seconds  = mt_rand( 0, 86399 );
	$date_iso = gmdate( 'Y-m-d\TH:i', $base_ts + $seconds );

	$tender    = weighted_pick( $tender_weights );
	$reference = '';
	if ( 'check' === $tender && mt_rand( 1, 100 ) <= 80 ) {
		$reference = sprintf( '#%d', mt_rand( 1000, 9999 ) );
	} elseif ( 'other' === $tender && mt_rand( 1, 100 ) <= 60 ) {
		$reference = $other_references[ mt_rand( 0, count( $other_references ) - 1 ) ];
	}

	$anonymous = ( mt_rand( 1, 100 ) <= 10 ) ? 'yes' : '';

	$notes = ( mt_rand( 1, 100 ) <= 5 )
		? $notes_pool[ mt_rand( 0, count( $notes_pool ) - 1 ) ]
		: '';

	fputcsv(
		$f,
		array(
			$external_id,
			$campaign_slug,
			$beneficiary,
			$team,
			number_format( $amount, 2, '.', '' ),
			$name,
			$email,
			$date_iso,
			$tender,
			$reference,
			$anonymous,
			$notes,
		)
	);
}

fclose( $f );
echo "wrote 500 donation rows to {$out_path}\n";
