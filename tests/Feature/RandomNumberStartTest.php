<?php

use App\Support\RandomNumberStart;

it('shapes the start as 0-5, three random digits, then zeros ending in 1', function () {
    foreach ([8, 6] as $width) {
        for ($i = 0; $i < 200; $i++) {
            $n = RandomNumberStart::start($width);
            expect($n)->toBeGreaterThanOrEqual(1)->toBeLessThan(6 * 10 ** ($width - 1))
                ->and($n % 10 ** ($width - 4))->toBe(1);
        }
    }
});
