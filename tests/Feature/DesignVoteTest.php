<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Http\Controllers\DesignVoteController;
use App\Models\DesignPoll;
use App\Models\DesignPollIdea;
use App\Models\DesignPollReaction;
use App\Models\DesignPollVote;
use App\Support\DesignPolls;
use Illuminate\Support\Facades\Http;

function openPoll(string $slug = 'delivery-days'): void
{
    DesignPoll::forSlug($slug)->update(['is_open' => true]);
}

/** A ballot body: ['cycle' => 'like', 'grid' => ['dislike', 'Too many buttons']]. */
function ballot(array $reactions, array $extra = []): array
{
    return ['reactions' => collect($reactions)->map(fn ($r, $key) => [
        'option_key' => $key,
        'reaction' => is_array($r) ? $r[0] : $r,
        'comment' => is_array($r) ? $r[1] : null,
    ])->values()->all()] + $extra;
}

test('the delivery-days poll starts closed and voting on a closed poll is refused', function () {
    expect(DesignPoll::where('slug', 'delivery-days')->value('is_open'))->toBeFalse();

    $this->postJson('/vote/delivery-days', ballot(['cycle' => 'like']))->assertForbidden();
    expect(DesignPollVote::count())->toBe(0);
});

test('a voter can like some designs and dislike others, each with a why, and leave the rest neutral', function () {
    openPoll();

    $this->postJson('/vote/delivery-days', ballot([
        'cycle' => ['like', ' Fast to tap '],
        'grid' => ['dislike', 'Too many buttons on a phone'],
    ], ['voter_name' => ' Ann ']))->assertCreated()->assertCookie('design_vote_token');

    $vote = DesignPollVote::sole();
    expect($vote->voter_name)->toBe('Ann')
        ->and($vote->reactions()->orderBy('option_key')->get(['option_key', 'reaction', 'comment'])->toArray())->toBe([
            ['option_key' => 'cycle', 'reaction' => 'like', 'comment' => 'Fast to tap'],
            ['option_key' => 'grid', 'reaction' => 'dislike', 'comment' => 'Too many buttons on a phone'],
        ]);
});

test('disliking every design is a valid vote, but at least one reaction is required', function () {
    openPoll();

    $this->postJson('/vote/delivery-days', ['reactions' => []])->assertJsonValidationErrors('reactions');
    $this->postJson('/vote/delivery-days', [])->assertJsonValidationErrors('reactions');
    $this->postJson('/vote/delivery-days', ballot(['cycle' => 'dislike', 'star' => 'dislike', 'grid' => 'dislike']))->assertCreated();

    expect(DesignPollReaction::where('reaction', 'dislike')->count())->toBe(3);
});

test('voting again from the same browser replaces all of the earlier reactions', function () {
    openPoll();

    $token = $this->postJson('/vote/delivery-days', ballot(['cycle' => 'like', 'grid' => 'dislike'], ['voter_name' => 'Ann']))
        ->getCookie('design_vote_token')->getValue();
    $this->withCookie('design_vote_token', $token)->withCredentials()
        ->postJson('/vote/delivery-days', ballot(['star' => 'like']))->assertCreated();

    $vote = DesignPollVote::sole();
    expect($vote->voter_token_hash)->toBe(hash('sha256', $token))
        ->and($vote->voter_name)->toBeNull()
        ->and($vote->reactions()->pluck('reaction', 'option_key')->all())->toBe(['star' => 'like']);

    // A different browser (no cookie) is a second voter.
    $this->defaultCookies = [];
    $this->postJson('/vote/delivery-days', ballot(['star' => 'like']))->assertCreated();
    expect(DesignPollVote::count())->toBe(2);
});

test('a signed-in person keeps one ballot across devices', function () {
    openPoll();
    $person = userWithPermissions('general-access');

    $this->actingAs($person)->postJson('/vote/delivery-days', ballot(['cycle' => 'like']))->assertCreated();
    $this->actingAs($person)->withCookie('design_vote_token', str_repeat('x', 40))->withCredentials()
        ->postJson('/vote/delivery-days', ballot(['star' => 'dislike']))->assertCreated();

    $vote = DesignPollVote::sole();
    expect($vote->person_id)->toBe($person->id)
        ->and($vote->reactions()->pluck('reaction', 'option_key')->all())->toBe(['star' => 'dislike']);
});

test('an unknown option, reaction, repeated option or poll is refused', function () {
    openPoll();

    $this->postJson('/vote/delivery-days', ballot(['nope' => 'like']))->assertJsonValidationErrors('reactions.0.option_key');
    $this->postJson('/vote/delivery-days', ballot(['grid' => 'love']))->assertJsonValidationErrors('reactions.0.reaction');
    $this->postJson('/vote/delivery-days', ['reactions' => [
        ['option_key' => 'grid', 'reaction' => 'like'], ['option_key' => 'grid', 'reaction' => 'dislike'],
    ]])->assertJsonValidationErrors('reactions.0.option_key');
    $this->postJson('/vote/no-such-poll', ballot(['grid' => 'like']))->assertNotFound();
    $this->get('/vote/no-such-poll')->assertNotFound();
    expect(DesignPollVote::count())->toBe(0);
});

test('Turnstile is required when it is switched on', function () {
    openPoll();
    config(['services.turnstile.enabled' => true, 'services.turnstile.site_key' => 'site', 'services.turnstile.secret_key' => 'secret']);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

    $this->postJson('/vote/delivery-days', ballot(['grid' => 'like']))->assertJsonValidationErrors('cf-turnstile-response');
    $this->postJson('/vote/delivery-days', ballot(['grid' => 'like'], ['cf-turnstile-response' => 'tok']))->assertCreated();
});

test('results rank by likes, then fewest dislikes, and stay hidden until voting closes', function () {
    openPoll();
    $this->postJson('/vote/delivery-days', ballot(['cycle' => 'like', 'grid' => 'like']));
    $this->postJson('/vote/delivery-days', ballot(['cycle' => 'like', 'grid' => 'dislike', 'star' => 'dislike']));
    $this->postJson('/vote/delivery-days', ballot(['grid' => 'like', 'star' => 'dislike']));
    $this->postJson('/vote/delivery-days', ballot(['cycle' => 'dislike', 'grid' => 'dislike']));

    $this->get('/vote/delivery-days')->assertOk()
        ->assertInertia(fn ($page) => $page->component('DesignVote')->where('open', true)->where('results', null)->has('poll.options', 3));

    DesignPoll::forSlug('delivery-days')->update(['is_open' => false]);
    $this->get('/vote/delivery-days')->assertInertia(fn ($page) => $page
        ->where('results.voters', 4)
        ->where('results.options.0', ['key' => 'cycle', 'likes' => 2, 'dislikes' => 1, 'percent' => 50])
        ->where('results.options.1', ['key' => 'grid', 'likes' => 2, 'dislikes' => 2, 'percent' => 50])
        ->where('results.options.2', ['key' => 'star', 'likes' => 0, 'dislikes' => 2, 'percent' => 0]));
});

test('the vote page shows this browser its own earlier reactions', function () {
    openPoll();
    $token = $this->postJson('/vote/delivery-days', ballot(['star' => ['like', 'Stars are clear']], ['voter_name' => 'Ann']))
        ->getCookie('design_vote_token')->getValue();

    $this->withCookie('design_vote_token', $token)->get('/vote/delivery-days')
        ->assertInertia(fn ($page) => $page
            ->where('my_vote.voter_name', 'Ann')
            ->where('my_vote.reactions', [['option_key' => 'star', 'reaction' => 'like', 'comment' => 'Stars are clear']]));
});

test('admins open and close polls, and see live results, why-comments by option and the CSV', function () {
    $admin = userWithPermissions('manage-design-votes');

    $this->actingAs($admin)->putJson('/json/design-votes/delivery-days/open', ['open' => true])
        ->assertOk()->assertJsonPath('polls.0.open', true);
    $this->postJson('/vote/delivery-days', ballot(['grid' => ['dislike', 'Too many buttons'], 'cycle' => 'like'], ['voter_name' => 'Bo']));

    $this->actingAs($admin)->getJson('/json/design-votes')->assertOk()
        ->assertJsonPath('polls.0.results.voters', 1)
        ->assertJsonCount(1, 'polls.0.comments')
        ->assertJsonPath('polls.0.comments.0.option_key', 'grid')
        ->assertJsonPath('polls.0.comments.0.reaction', 'dislike')
        ->assertJsonPath('polls.0.comments.0.comment', 'Too many buttons')
        ->assertJsonPath('polls.0.comments.0.voter_name', 'Bo')
        ->assertJsonPath('polls.0.url', route('design-vote', 'delivery-days'))
        ->assertJsonMissingPath('polls.0.comments.0.voter_token_hash');

    $csv = $this->actingAs($admin)->get('/json/design-votes/delivery-days/csv')->assertOk()->streamedContent();
    expect($csv)->toContain('Day grid')->toContain('Not for them')->toContain('Too many buttons')
        ->toContain('Tap to cycle')->toContain('Likes it')->toContain('Bo');

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
    $this->postJson('/vote/delivery-days', ballot(['grid' => 'like']))->assertCreated();
    $this->postJson('/vote/delivery-days/idea', ['idea' => 'A big print button on pick sheets'])->assertCreated();

    expect(DesignPollIdea::orderBy('id')->pluck('idea')->all())->toBe(['Let partners set their own days online', 'A big print button on pick sheets'])
        ->and(DesignPollReaction::sole()->option_key)->toBe('grid');

    $this->postJson('/vote/delivery-days/idea', ['idea' => ''])->assertJsonValidationErrors('idea');
    $this->postJson('/vote/no-such-poll/idea', ['idea' => 'x'])->assertNotFound();
});

test('an idea can leave an email, which is needed only when it is OK to contact them', function () {
    $this->postJson('/vote/delivery-days/idea', ['idea' => 'x', 'email' => ' cy@example.org '])->assertCreated();
    $this->postJson('/vote/delivery-days/idea', ['idea' => 'y', 'ok_to_contact' => true])
        ->assertJsonValidationErrors(['email' => 'Add your email so we can contact you, or untick the box.']);
    $this->postJson('/vote/delivery-days/idea', ['idea' => 'z', 'email' => 'not-an-email'])->assertJsonValidationErrors('email');
    $this->postJson('/vote/delivery-days/idea', ['idea' => 'w', 'email' => 'di@example.org', 'ok_to_contact' => true])->assertCreated();

    expect(DesignPollIdea::orderBy('id')->get(['idea', 'email', 'ok_to_contact'])->toArray())->toBe([
        ['idea' => 'x', 'email' => 'cy@example.org', 'ok_to_contact' => false],
        ['idea' => 'w', 'email' => 'di@example.org', 'ok_to_contact' => true],
    ]);
});

test('ideas need Turnstile when it is switched on', function () {
    config(['services.turnstile.enabled' => true, 'services.turnstile.site_key' => 'site', 'services.turnstile.secret_key' => 'secret']);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

    $this->postJson('/vote/delivery-days/idea', ['idea' => 'x'])->assertJsonValidationErrors('cf-turnstile-response');
    $this->postJson('/vote/delivery-days/idea', ['idea' => 'x', 'cf-turnstile-response' => 'tok'])->assertCreated();
});

test('admins see the ideas and get them in the CSV', function () {
    $admin = userWithPermissions('manage-design-votes');
    $this->postJson('/vote/delivery-days/idea', ['idea' => 'Show the truck schedule', 'voter_name' => 'Di', 'email' => 'di@example.org', 'ok_to_contact' => true]);

    $this->actingAs($admin)->getJson('/json/design-votes')
        ->assertJsonPath('polls.0.ideas.0.idea', 'Show the truck schedule')
        ->assertJsonPath('polls.0.ideas.0.voter_name', 'Di')
        ->assertJsonPath('polls.0.ideas.0.email', 'di@example.org')
        ->assertJsonPath('polls.0.ideas.0.ok_to_contact', true)
        ->assertJsonMissingPath('polls.0.ideas.0.ip_address');

    $csv = $this->actingAs($admin)->get('/json/design-votes/delivery-days/csv')->streamedContent();
    expect($csv)->toContain('Idea')->toContain('Show the truck schedule')->toContain('di@example.org,Yes');
});

test('reactions to a design dropped from the poll stop counting', function () {
    openPoll();
    $this->postJson('/vote/delivery-days', ballot(['grid' => 'like']));
    $old = DesignPollVote::create(['poll_slug' => 'delivery-days', 'voter_token_hash' => hash('sha256', 'old')]);
    $old->reactions()->create(['option_key' => 'pick', 'reaction' => 'like']);

    $results = DesignVoteController::results('delivery-days');
    expect($results['voters'])->toBe(1)
        ->and(collect($results['options'])->pluck('key')->all())->not->toContain('pick');
});
