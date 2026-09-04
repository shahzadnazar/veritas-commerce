<?php

declare(strict_types=1);

namespace Tests\Invariants;

use App\Modules\Orders\Actions\MarkOrderPaid;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use Tests\TestCase;

/**
 * Invariant 9 — a browser can never say that money arrived.
 *
 * The most expensive thing a marketplace can get wrong is believing its own
 * customer about payment. A redirect back from a provider, a query string
 * saying `?paid=true`, a PaymentIntent id typed into a URL: none of these
 * are evidence. They are all attacker-controlled, and every one of them has
 * been used to take goods for free from a real shop.
 *
 * So the rule is structural rather than careful: the action that marks an
 * order paid is reachable from exactly one place — verified provider-event
 * processing — and this test fails the build if a second caller appears.
 * A future milestone adding a "confirm payment" controller has to delete
 * this test to ship, which is precisely the conversation that should happen.
 */
final class PaymentAuthorityTest extends TestCase
{
    /**
     * The only classes permitted to call MarkOrderPaid, and why.
     *
     * FinalizePayment is the verified-provider path: it re-reads the
     * provider's own record of the payment, checks the amount, the
     * currency and the reference against the order, and only then
     * transitions. Nothing else may.
     */
    private const PERMITTED_CALLERS = [
        'App\Modules\Payments\Actions\FinalizePayment',
    ];

    #[Test]
    public function only_verified_payment_processing_can_mark_an_order_paid(): void
    {
        $callers = [];

        foreach ($this->phpFiles(app_path()) as $file) {
            $contents = (string) file_get_contents($file);

            if (! str_contains($contents, 'MarkOrderPaid')) {
                continue;
            }

            $class = $this->classNameOf($file);

            if ($class === 'App\Modules\Orders\Actions\MarkOrderPaid') {
                continue;
            }

            $callers[] = $class;
        }

        sort($callers);

        $this->assertSame(
            self::PERMITTED_CALLERS,
            $callers,
            "MarkOrderPaid gained a caller.\n\n".
            'Marking an order paid is authorised by a verified provider event and by nothing else. '.
            "A controller, a job or a listener that calls it directly is a route by which a customer's ".
            'browser can claim money arrived. If this is genuinely intended, the reviewer of that change '.
            'has to say so out loud by editing PERMITTED_CALLERS.',
        );
    }

    #[Test]
    public function no_http_route_reaches_the_paid_transition(): void
    {
        $offenders = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $action = $route->getActionName();

            if (! str_contains($action, '@') && ! str_contains($action, 'Controller')) {
                continue;
            }

            $class = explode('@', $action)[0];

            if (! class_exists($class)) {
                continue;
            }

            $file = (new ReflectionClass($class))->getFileName();

            if ($file === false || ! str_contains((string) file_get_contents($file), 'MarkOrderPaid')) {
                continue;
            }

            $offenders[] = $route->uri().' → '.$action;
        }

        // Not "no route calls it today" but "no route can": a controller
        // that so much as mentions the class is a request away from being
        // the thing that decides a payment succeeded.
        $this->assertSame([], $offenders, 'An HTTP route reaches the paid transition: '.implode(', ', $offenders));
    }

    #[Test]
    public function the_paid_transition_takes_no_amount_from_its_caller(): void
    {
        $reflection = new ReflectionMethod(MarkOrderPaid::class, '__invoke');

        $parameters = array_map(
            static fn (ReflectionParameter $p): string => $p->getName(),
            $reflection->getParameters(),
        );

        /*
         * The action reads what to charge from the order it was given. If
         * it ever accepted an amount, a currency or a provider reference as
         * an argument, the caller would become the authority on those — and
         * the caller is one refactor away from being a controller.
         */
        $this->assertSame(['order'], $parameters);
    }

    /** @return iterable<int, string> */
    private function phpFiles(string $root): iterable
    {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }

    private function classNameOf(string $file): string
    {
        $contents = (string) file_get_contents($file);

        preg_match('/^namespace\s+([^;]+);/m', $contents, $namespace);
        preg_match('/^(?:final\s+)?(?:abstract\s+)?(?:class|interface|trait|enum)\s+(\w+)/m', $contents, $class);

        return ($namespace[1] ?? '').'\\'.($class[1] ?? basename($file, '.php'));
    }
}
