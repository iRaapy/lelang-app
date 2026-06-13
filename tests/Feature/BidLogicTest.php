<?php

use App\Models\Auction;
use App\Models\Bid;
use App\Models\User;

function createActiveAuction(array $overrides = []): Auction
{
    $seller = User::factory()->create();

    return Auction::factory()->create(array_merge([
        'seller_id' => $seller->id,
        'starting_price' => 100000,
        'bid_increment' => 10000,
        'current_price' => 100000,
        'status' => 'active',
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
    ], $overrides));
}

test('bid berhasil jika amount memenuhi minimum', function () {
    $auction = createActiveAuction();
    $bidder = User::factory()->create();

    $response = $this->actingAs($bidder, 'sanctum')
        ->postJson("/api/auctions/{$auction->id}/bids", [
            'amount' => 110000,
        ]);

    $response->assertStatus(201);

    $auction->refresh();
    expect((float) $auction->current_price)->toBe(110000.0);
    expect($auction->bids_count)->toBe(1);

    $bid = Bid::first();
    expect($bid->status)->toBe('active');
    expect($bid->bidder_id)->toBe($bidder->id);
});

test('bid ditolak jika kurang dari minimum', function () {
    $auction = createActiveAuction();
    $bidder = User::factory()->create();

    $response = $this->actingAs($bidder, 'sanctum')
        ->postJson("/api/auctions/{$auction->id}/bids", [
            'amount' => 105000,
        ]);

    $response->assertStatus(422);

    $auction->refresh();
    expect((float) $auction->current_price)->toBe(100000.0);
    expect($auction->bids_count)->toBe(0);
});

test('penjual tidak bisa bid pada lelang miliknya sendiri', function () {
    $seller = User::factory()->create();
    $auction = createActiveAuction(['seller_id' => $seller->id]);

    $response = $this->actingAs($seller, 'sanctum')
        ->postJson("/api/auctions/{$auction->id}/bids", [
            'amount' => 110000,
        ]);

    $response->assertStatus(422);
});

test('bid ditolak jika lelang belum aktif (scheduled)', function () {
    $auction = createActiveAuction([
        'status' => 'scheduled',
        'starts_at' => now()->addHour(),
        'ends_at' => now()->addHours(2),
    ]);
    $bidder = User::factory()->create();

    $response = $this->actingAs($bidder, 'sanctum')
        ->postJson("/api/auctions/{$auction->id}/bids", [
            'amount' => 110000,
        ]);

    $response->assertStatus(422);
});

test('bid ditolak jika lelang sudah berakhir', function () {
    $auction = createActiveAuction([
        'status' => 'active',
        'starts_at' => now()->subHours(2),
        'ends_at' => now()->subMinutes(5),
    ]);
    $bidder = User::factory()->create();

    $response = $this->actingAs($bidder, 'sanctum')
        ->postJson("/api/auctions/{$auction->id}/bids", [
            'amount' => 110000,
        ]);

    $response->assertStatus(422);
});

test('penawar tertinggi sebelumnya otomatis menjadi outbid', function () {
    $auction = createActiveAuction();
    $bidder1 = User::factory()->create();
    $bidder2 = User::factory()->create();

    $this->actingAs($bidder1, 'sanctum')
        ->postJson("/api/auctions/{$auction->id}/bids", ['amount' => 110000])
        ->assertStatus(201);

    $this->actingAs($bidder2, 'sanctum')
        ->postJson("/api/auctions/{$auction->id}/bids", ['amount' => 120000])
        ->assertStatus(201);

    $bid1 = Bid::where('bidder_id', $bidder1->id)->first();
    $bid2 = Bid::where('bidder_id', $bidder2->id)->first();

    expect($bid1->status)->toBe('outbid');
    expect($bid2->status)->toBe('active');

    $auction->refresh();
    expect((float) $auction->current_price)->toBe(120000.0);
    expect($auction->bids_count)->toBe(2);
});

test('buy now langsung mengakhiri lelang dan menetapkan pemenang', function () {
    $auction = createActiveAuction([
        'buy_now_price' => 200000,
    ]);
    $bidder = User::factory()->create();

    $response = $this->actingAs($bidder, 'sanctum')
        ->postJson("/api/auctions/{$auction->id}/bids", [
            'amount' => 200000,
        ]);

    $response->assertStatus(201);

    $auction->refresh();
    expect($auction->status)->toBe('ended');
    expect($auction->winner_id)->toBe($bidder->id);
});

test('anti-sniping memperpanjang waktu lelang jika bid di detik-detik akhir', function () {
    $auction = createActiveAuction([
        'ends_at' => now()->addSeconds(20),
    ]);
    $originalEndsAt = $auction->ends_at->copy();
    $bidder = User::factory()->create();

    $this->actingAs($bidder, 'sanctum')
        ->postJson("/api/auctions/{$auction->id}/bids", ['amount' => 110000])
        ->assertStatus(201);

    $auction->refresh();
    expect($auction->ends_at->gt($originalEndsAt))->toBeTrue();
});