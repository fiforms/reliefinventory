<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\DesignPoll;
use App\Models\DesignPollIdea;
use App\Models\DesignPollVote;
use App\Support\DesignPolls;
use Illuminate\Support\Facades\Http;

function openPoll(string $slug = 'delivery-days'): void
{
    DesignPoll::forSlug($slug)->update(['is_open' => true]);
}

test('the delivery-days poll starts closed and voting on a closed poll is refused', function () {
    expect(DesignPoll::where('slug', 'delivery-days')->value('is_open'))->toBeFalse();

    $this->postJson('/vote/delivery-days', ['option_key' => 'cycle'])->assertForbidden();
    expect(DesignPollVote::count())->toBe(0);
});

test('anyone can vote without signing in, and voting again from the same browser replaces the vote', function () {
    openPoll();

    $first = $this->postJson('/vote/delivery-days', [
        'option_key' => 'cycle', 'voter_name' => ' Ann ', 'voter_role' => 'volunteer', 'comment' => 'Fast to tap',
    ])->assertCreated()->assertCookie('design_vote_token');
    $token = $first->getCookie('design_vote_token')->getValue();

    $this->withCookie('design_vote_token', $token)->withCredentials()
        ->postJson('/vote/delivery-days', ['option_key' => 'grid'])->assertCreated();

    $vote = DesignPollVote::sole();
    expect($vote->option_key)->toBe('grid')
        ->and($vote->voter_token_hash)->toBe(hash('sha256', $token))
        ->and($vote->voter_name)->toBeNull();

    // A different browser (no cookie) is a second vote.
    $this->defaultCookies = [];
    $this->postJson('/vote/delivery-days', ['option_key' => 'star'])->assertCreated();
    expect(DesignPollVote::count())->toBe(2);
});

test('a signed-in person keeps one vote across devices', function () {
    openPoll();
    $person = userWithPermissions('general-access');

    $this->actingAs($person)->postJson('/vote/delivery-days', ['option_key' => 'cycle'])->assertCreated();
    $this->actingAs($person)->withCookie('design_vote_token', str_repeat('x', 40))->withCredentials()
        ->postJson('/vote/delivery-days', ['option_key' => 'star'])->assertCreated();

    expect(DesignPollVote::sole()->only(['option_key', 'person_id']))->toBe(['option_key' => 'star', 'person_id' => $person->id]);
});

test('an unknown option, role or poll is refused', function () {
    openPoll();

    $this->postJson('/vote/delivery-days', ['option_key' => 'nope'])->assertJsonValidationErrors('option_key');
    $this->postJson('/vote/delivery-days', ['option_key' => 'grid', 'voter_role' => 'boss'])->assertJsonValidationErrors('voter_role');
    $this->postJson('/vote/no-such-poll', ['option_key' => 'grid'])->assertNotFound();
    $this->get('/vote/no-such-poll')->assertNotFound();
});

test('Turnstile is required when it is switched on', function () {
    openPoll();
    config(['services.turnstile.enabled' => true, 'services.turnstile.site_key' => 'site', 'services.turnstile.secret_key' => 'secret']);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

    $this->postJson('/vote/delivery-days', ['option_key' => 'grid'])->assertJsonValidationErrors('cf-turnstile-response');
    $this->postJson('/vote/delivery-days', ['option_key' => 'grid', 'cf-turnstile-response' => 'tok'])->assertCreated();
});

test('the vote page hides results while open and shows them once closed', function () {
    openPoll();
    $this->postJson('/vote/delivery-days', ['option_key' => 'cycle']);
    $this->postJson('/vote/delivery-days', ['option_key' => 'cycle']);
    $this->postJson('/vote/delivery-days', ['option_key' => 'grid']);

    $this->get('/vote/delivery-days')->assertOk()
        ->assertInertia(fn ($page) => $page->component('DesignVote')->where('open', true)->where('results', null)->has('poll.options', 3));

    DesignPoll::forSlug('delivery-days')->update(['is_open' => false]);
    $this->get('/vote/delivery-days')->assertInertia(fn ($page) => $page
        ->where('results.total', 3)
        ->where('results.options.0', ['key' => 'cycle', 'votes' => 2, 'percent' => 67])
        ->where('results.options.1', ['key' => 'grid', 'votes' => 1, 'percent' => 33]));
});

test('the vote page shows this browser its own earlier vote', function () {
    openPoll();
    $token = $this->postJson('/vote/delivery-days', ['option_key' => 'star', 'comment' => 'Stars are clear'])
        ->getCookie('design_vote_token')->getValue();

    $this->withCookie('design_vote_token', $token)->get('/vote/delivery-days')
        ->assertInertia(fn ($page) => $page->where('my_vote.option_key', 'star')->where('my_vote.comment', 'Stars are clear'));
});

test('admins open and close polls, and see live results, comments and the CSV', function () {
    $admin = userWithPermissions('manage-design-votes');

    $this->actingAs($admin)->putJson('/json/design-votes/delivery-days/open', ['open' => true])
        ->assertOk()->assertJsonPath('polls.0.open', true);
    $this->postJson('/vote/delivery-days', ['option_key' => 'grid', 'voter_name' => 'Bo', 'voter_role' => 'partner', 'comment' => 'The grid reads well']);

    $this->actingAs($admin)->getJson('/json/design-votes')->assertOk()
        ->assertJsonPath('polls.0.results.total', 1)
        ->assertJsonPath('polls.0.results.by_role.partner.grid', 1)
        ->assertJsonPath('polls.0.comments.0.comment', 'The grid reads well')
        ->assertJsonPath('polls.0.url', route('design-vote', 'delivery-days'))
        ->assertJsonMissingPath('polls.0.comments.0.voter_token_hash');

    $csv = $this->actingAs($admin)->get('/json/design-votes/delivery-days/csv')->assertOk()->streamedContent();
    expect($csv)->toContain('The grid reads well')->toContain('Partner organization')->toContain('Bo');

    $this->actingAs($admin)->putJson('/json/design-votes/delivery-days/open', ['open' => false])->assertJsonPath('polls.0.open', false);
    expect(DesignPoll::forSlug('delivery-days')->closed_at)->not->toBeNull();
});

test('the admin page and its endpoints need manage-design-votes', function () {
    $user = userWithPermissions('general-access');

    $this->actingAs($user)->get('/setup/design-votes')->assertForbidden();
    $this->actingAs($user)->getJson('/json/design-votes')->assertForbidden();
    $this->actingAs($user)->putJson('/json/design-votes/delivery-days/open', ['open' => true])->assertForbidden();
    $this->actingAs($user)->get('/json/design-votes/delivery-days/csv')->assertForbidden();
});

test('every poll option has a demo component registered', function () {
    $index = file_get_contents(resource_path('js/DesignPolls/index.js'));

    foreach (DesignPolls::all() as $poll) {
        preg_match("/'".preg_quote($poll['slug'], '/')."'\\s*:\\s*\\{([^}]*)\\}/", $index, $m);
        expect($m)->not->toBeEmpty("no demos registered for {$poll['slug']}");
        preg_match_all('/(\w+)\s*:/', $m[1], $keys);
        expect($keys[1])->toEqualCanonicalizing(array_keys($poll['options']));
    }
});

test('anyone can send other ideas, as many as they like, with or without voting, even once voting has closed', function () {
    $this->postJson('/vote/delivery-days/idea', ['idea' => '  Let partners set their own days online  ', 'voter_name' => 'Cy'])->assertCreated();
    openPoll();
    $this->postJson('/vote/delivery-days', ['option_key' => 'grid'])->assertCreated();
    $this->postJson('/vote/delivery-days/idea', ['idea' => 'A big print button on pick sheets'])->assertCreated();

    expect(DesignPollIdea::orderBy('id')->pluck('idea')->all())->toBe(['Let partners set their own days online', 'A big print button on pick sheets'])
        ->and(DesignPollVote::sole()->option_key)->toBe('grid');

    $this->postJson('/vote/delivery-days/idea', ['idea' => ''])->assertJsonValidationErrors('idea');
    $this->postJson('/vote/no-such-poll/idea', ['idea' => 'x'])->assertNotFound();
});

test('ideas need Turnstile when it is switched on', function () {
    config(['services.turnstile.enabled' => true, 'services.turnstile.site_key' => 'site', 'services.turnstile.secret_key' => 'secret']);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

    $this->postJson('/vote/delivery-days/idea', ['idea' => 'x'])->assertJsonValidationErrors('cf-turnstile-response');
    $this->postJson('/vote/delivery-days/idea', ['idea' => 'x', 'cf-turnstile-response' => 'tok'])->assertCreated();
});

test('admins see the ideas and get them in the CSV', function () {
    $admin = userWithPermissions('manage-design-votes');
    $this->postJson('/vote/delivery-days/idea', ['idea' => 'Show the truck schedule', 'voter_name' => 'Di']);

    $this->actingAs($admin)->getJson('/json/design-votes')
        ->assertJsonPath('polls.0.ideas.0.idea', 'Show the truck schedule')
        ->assertJsonPath('polls.0.ideas.0.voter_name', 'Di')
        ->assertJsonMissingPath('polls.0.ideas.0.ip_address');

    $csv = $this->actingAs($admin)->get('/json/design-votes/delivery-days/csv')->streamedContent();
    expect($csv)->toContain('Idea')->toContain('Show the truck schedule');
});
