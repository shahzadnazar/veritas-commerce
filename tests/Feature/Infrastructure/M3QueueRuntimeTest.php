<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Modules\Catalog\Models\Product;
use App\Modules\Events\Enums\InteractionEventType;
use App\Modules\Events\Jobs\RecordInteractionEvent;
use App\Modules\Inventory\Actions\ReserveStock;
use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Inventory\Jobs\ExpireReservations;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\Search\Jobs\ReindexProduct;
use App\Support\Queues;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Inventory\StocksOffers;
use Tests\TestCase;

/**
 * M3's own jobs, run through a real Redis by a real worker.
 *
 * The generic runtime is proven in QueueRuntimeTest; what matters here is
 * that the three jobs this milestone added actually make the round trip
 * and do their work on the other side, on the queues they claim.
 */
final class M3QueueRuntimeTest extends TestCase
{
    use RefreshDatabase;
    use StocksOffers;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'redis']);

        Redis::connection('default')->client()->flushdb();
        Cache::flush();
    }

    #[Test]
    public function the_reservation_sweep_runs_through_redis_and_returns_the_stock(): void
    {
        ['offer' => $offer, 'balance' => $balance] = $this->stockedOffer(5);

        app(ReserveStock::class)([$offer->id => 3], 'cart-abandoned', ttlMinutes: 20);
        $this->assertSame(2, $balance->refresh()->available);

        Carbon::setTestNow(now()->addMinutes(21));
        ExpireReservations::dispatch();

        // On the wire, not yet run.
        $this->assertSame(1, Queue::connection('redis')->size(Queues::CRITICAL));
        $this->assertSame(2, $balance->refresh()->available);

        $this->workOnce(Queues::CRITICAL);

        $this->assertSame(5, $balance->refresh()->available, 'A worker returned the abandoned stock.');
        $this->assertSame(
            ReservationStatus::Expired,
            InventoryReservation::query()->where('reference', 'cart-abandoned')->firstOrFail()->status,
        );
    }

    #[Test]
    public function a_reindex_runs_through_redis_and_writes_a_document(): void
    {
        $product = Product::factory()->create(['title' => 'Aeris Kettle']);

        ReindexProduct::dispatch($product->id);

        $this->assertSame(1, Queue::connection('redis')->size(Queues::SEARCH));
        $this->assertDatabaseCount('product_search_documents', 0);

        $this->workOnce(Queues::SEARCH);

        $this->assertDatabaseHas('product_search_documents', ['product_id' => $product->id]);
    }

    #[Test]
    public function reindexing_the_same_product_twice_leaves_one_document(): void
    {
        $product = Product::factory()->create(['title' => 'Aeris Kettle']);

        ReindexProduct::dispatch($product->id);
        ReindexProduct::dispatch($product->id);

        $this->workOnce(Queues::SEARCH);
        $this->workOnce(Queues::SEARCH);

        // Idempotence is the requirement, not a nicety: this job is
        // retried, redelivered, and fired by several events at once.
        $this->assertDatabaseCount('product_search_documents', 1);
    }

    #[Test]
    public function an_analytics_write_runs_on_the_lowest_priority_queue(): void
    {
        $job = new RecordInteractionEvent(
            eventId: '01HZZZZZZZZZZZZZZZZZZZZZZZ',
            type: InteractionEventType::SearchPerformed,
            searchQuery: 'kettle',
        );

        dispatch($job);

        // Losing an analytics row is a shame; delaying a payment for one
        // is not acceptable, which is why it is not on `critical`.
        $this->assertSame(1, Queue::connection('redis')->size(Queues::DEFAULT));
        $this->assertSame(0, Queue::connection('redis')->size(Queues::CRITICAL));

        $this->workOnce(Queues::DEFAULT);

        $this->assertDatabaseHas('interaction_events', ['search_query' => 'kettle']);
    }

    #[Test]
    public function the_container_stack_still_declares_a_queue_worker_and_an_ssr_process(): void
    {
        // Read as text rather than parsed: the YAML extension is not a
        // dependency worth adding to assert on two service names.
        $compose = (string) file_get_contents(base_path('docker-compose.yml'));

        // §49.3: the two processes a deployment silently works without —
        // until nothing is queued and every page is client-rendered.
        $this->assertStringContainsString('    horizon:', $compose);
        $this->assertStringContainsString('    ssr:', $compose);
        $this->assertStringContainsString('php artisan horizon', $compose);
        $this->assertStringContainsString('node bootstrap/ssr/ssr.js', $compose);
    }

    #[Test]
    public function the_ci_pipeline_still_exercises_the_queue_and_the_ssr_smoke(): void
    {
        $workflow = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

        // A gate that quietly stopped running is worse than one that
        // never existed, so the pipeline's own content is asserted.
        $this->assertStringContainsString('queues:smoke', $workflow);
        $this->assertStringContainsString('horizon:status', $workflow);
        $this->assertStringContainsString('build:ssr', $workflow);
        $this->assertStringContainsString('docker compose up -d ssr app', $workflow);
        $this->assertStringContainsString('veritas:seed-demo-catalogue', $workflow);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Run exactly one job, the way a worker process would. */
    private function workOnce(string $queue): void
    {
        $this->app[Kernel::class]->call('queue:work', [
            'connection' => 'redis',
            '--queue' => $queue,
            '--once' => true,
        ]);
    }
}
