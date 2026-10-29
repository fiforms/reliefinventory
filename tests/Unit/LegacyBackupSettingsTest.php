<?php

// The old bare-metal backup panel's settings file, carried into the one scheduler's backup_schedule row (App\Support\LegacyBackupSettings).

use App\Support\LegacyBackupSettings;

function legacyConf(string $text): string
{
    $path = sys_get_temp_dir().'/ri-legacy-conf-'.bin2hex(random_bytes(4)).'.conf';
    file_put_contents($path, $text);

    return $path;
}

test('no file means no legacy schedule, and a file is read key by key with defaults for what it lacks', function () {
    expect(LegacyBackupSettings::read('/nonexistent/backup-settings.conf'))->toBeNull();

    $path = legacyConf("# managed by the panel\nBACKUP_HOUR=4\nKEEP_DAILY=\"30\"\nSOMETHING_ELSE=x\nnot a setting\n");
    $read = LegacyBackupSettings::read($path);
    unlink($path);

    expect($read['BACKUP_HOUR'])->toBe('4')->and($read['KEEP_DAILY'])->toBe('30')
        ->and($read['BACKUP_FREQUENCY'])->toBe('daily')->and($read['KEEP_MONTHLY'])->toBe('12')
        ->and($read)->not->toHaveKey('SOMETHING_ELSE');
});

test('the settings file is never executed', function () {
    $marker = sys_get_temp_dir().'/ri-legacy-conf-ran-'.bin2hex(random_bytes(4));
    $path = legacyConf("BACKUP_HOUR=\$(touch {$marker})\nKEEP_DAILY=7; touch {$marker}\n");

    LegacyBackupSettings::toSchedule(LegacyBackupSettings::read($path), 'UTC');
    unlink($path);

    expect(file_exists($marker))->toBeFalse();
});

test('a daily install becomes the daily tier, with monthly kept and nothing else', function () {
    $schedule = LegacyBackupSettings::toSchedule([
        'BACKUP_FREQUENCY' => 'daily', 'BACKUP_HOUR' => '2', 'BACKUP_DOW' => '7', 'HOURLY_BACKUPS' => '0', 'BACKUP_TZ' => 'UTC',
        'KEEP_HOURLY' => '48', 'KEEP_DAILY' => '14', 'KEEP_MONTHLY' => '12', 'KEEP_YEARLY' => '3',
    ], 'UTC');

    expect($schedule)->toMatchArray([
        'hourly_enabled' => false, 'hourly_keep' => 48,
        'daily_enabled' => true, 'daily_hour' => 2, 'daily_keep' => 14, 'daily_with_files' => false,
        'weekly_enabled' => false,
        'monthly_enabled' => true, 'monthly_keep' => 12, 'monthly_with_files' => false,
    ]);
});

test('a weekly install becomes the weekly tier on its day, kept as many as its daily directory held', function () {
    $legacy = ['BACKUP_FREQUENCY' => 'weekly', 'BACKUP_HOUR' => '3', 'BACKUP_DOW' => '1', 'HOURLY_BACKUPS' => '1', 'BACKUP_TZ' => 'UTC',
        'KEEP_HOURLY' => '24', 'KEEP_DAILY' => '8', 'KEEP_MONTHLY' => '0', 'KEEP_YEARLY' => '3'];

    $schedule = LegacyBackupSettings::toSchedule($legacy, 'UTC');

    expect($schedule)->toMatchArray([
        'daily_enabled' => false, 'weekly_enabled' => true, 'weekly_dow' => 1, 'weekly_keep' => 8,
        'hourly_enabled' => true, 'hourly_keep' => 24, 'monthly_enabled' => false,
    ]);
    // the old Sunday was 7; the new one is 0
    expect(LegacyBackupSettings::toSchedule(['BACKUP_DOW' => '7'] + $legacy, 'UTC')['weekly_dow'])->toBe(0)
        ->and(LegacyBackupSettings::toSchedule(['BACKUP_DOW' => '6'] + $legacy, 'UTC')['weekly_dow'])->toBe(6);
});

test('the numbers are held to what the new schedule allows, because the app prunes by them', function () {
    $schedule = LegacyBackupSettings::toSchedule([
        'BACKUP_FREQUENCY' => 'daily', 'BACKUP_HOUR' => '99', 'BACKUP_DOW' => '7', 'HOURLY_BACKUPS' => '1', 'BACKUP_TZ' => 'UTC',
        'KEEP_HOURLY' => '500', 'KEEP_DAILY' => '365', 'KEEP_MONTHLY' => '120', 'KEEP_YEARLY' => '3',
    ], 'UTC');

    expect($schedule['hourly_keep'])->toBe(168)->and($schedule['daily_keep'])->toBe(90)->and($schedule['monthly_keep'])->toBe(36)
        ->and($schedule['daily_hour'])->toBe(23)->and($schedule['weekly_keep'])->toBe(52);
});

test('the hour is converted from the old backup timezone into the server\'s, once', function () {
    // 02:00 in Los Angeles is 09:00 or 10:00 UTC depending on the date, so assert against what Carbon itself says for today.
    $expected = Carbon\Carbon::now('America/Los_Angeles')->setTime(2, 0)->setTimezone('UTC')->hour;

    expect(LegacyBackupSettings::hourIn('UTC', 2, 'America/Los_Angeles'))->toBe($expected)
        ->and(LegacyBackupSettings::hourIn('UTC', 2, 'UTC'))->toBe(2)
        ->and(LegacyBackupSettings::hourIn('America/Los_Angeles', 2, 'America/Los_Angeles'))->toBe(2)
        ->and(LegacyBackupSettings::hourIn('UTC', 5, 'Not/AZone'))->toBe(5);   // an unknown name leaves the hour alone
});

test('the whole step: a file becomes the columns of the schedule row, and no file leaves the defaults to the migration', function () {
    $path = legacyConf("BACKUP_FREQUENCY=weekly\nBACKUP_HOUR=5\nBACKUP_DOW=3\nBACKUP_TZ=UTC\nKEEP_DAILY=6\nHOURLY_BACKUPS=1\nKEEP_HOURLY=12\nKEEP_MONTHLY=0\n");
    $row = LegacyBackupSettings::scheduleRow($path, 'UTC');
    unlink($path);

    expect($row)->toMatchArray([
        'weekly_enabled' => true, 'weekly_dow' => 3, 'weekly_keep' => 6, 'daily_enabled' => false, 'daily_hour' => 5,
        'hourly_enabled' => true, 'hourly_keep' => 12, 'monthly_enabled' => false,
    ]);
    expect(LegacyBackupSettings::scheduleRow('/nonexistent/backup-settings.conf', 'UTC'))->toBeNull();
});
