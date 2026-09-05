<?php

declare(strict_types=1);

namespace Tests\Invariants;

use App\Modules\Orders\Actions\MarkOrderPaid;
use App\Modules\Payments\Actions\FinalizePayment;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
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

    #[Test]
    public function only_the_verified_finalizer_can_hold_the_paid_transition(): void
    {
        /*
         * The same rule as the scan above, asked structurally instead of
         * textually.
         *
         * Reading files for the string "MarkOrderPaid" is deliberately
         * broad — a class that so much as names it is a refactor away from
         * calling it — but it is also a substring match, and substring
         * matches can be talked around: a container alias, a variable class
         * name, a string built at runtime. This asks the type system
         * instead. Whatever the file says, a class cannot invoke the action
         * without being handed one, and PHP records where that happens.
         *
         * Both together are the point. Either alone has a shape of evasion
         * the other catches.
         */
        $holders = [];

        foreach ($this->appClasses() as $class) {
            $reflection = new ReflectionClass($class);

            if ($reflection->getName() === MarkOrderPaid::class) {
                continue;
            }

            foreach ($this->declaredTypes($reflection->getName()) as $type) {
                if ($type === MarkOrderPaid::class) {
                    $holders[] = $reflection->getName();

                    break;
                }
            }
        }

        sort($holders);

        $this->assertSame(
            self::PERMITTED_CALLERS,
            $holders,
            "A class other than the verified finalizer can be handed MarkOrderPaid.\n\n"
                .'Being able to hold it is being able to call it. If this is intended, the reviewer '
                .'has to say so out loud by editing PERMITTED_CALLERS — and should be sure the new '
                .'holder re-reads the provider rather than believing a request.',
        );
    }

    #[Test]
    public function the_structural_scan_is_actually_looking_at_something(): void
    {
        // A reflection sweep that quietly matched nothing would pass
        // forever, so the fixture it is meant to find is asserted present.
        $classes = $this->appClasses();

        $this->assertGreaterThan(200, count($classes));
        $this->assertContains(FinalizePayment::class, $classes);

        $types = $this->declaredTypes(FinalizePayment::class);

        $this->assertContains(
            MarkOrderPaid::class,
            $types,
            'The finalizer must still be the class that holds the paid transition.',
        );
    }

    /**
     * Every type this class is handed or holds.
     *
     * Constructor parameters, method parameters and properties: the three
     * ways an object arrives somewhere it can be called from. Union and
     * intersection types are unwrapped, because hiding a dependency inside
     * one would otherwise be a way past this.
     *
     * @param  class-string  $className
     * @return array<int, string>
     */
    private function declaredTypes(string $className): array
    {
        $class = new ReflectionClass($className);
        $types = [];

        $collect = static function (?ReflectionType $type) use (&$types): void {
            if ($type === null) {
                return;
            }

            $parts = $type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType
                ? $type->getTypes()
                : [$type];

            foreach ($parts as $part) {
                if ($part instanceof ReflectionNamedType && ! $part->isBuiltin()) {
                    $types[] = $part->getName();
                }
            }
        };

        foreach ($class->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }

            foreach ($method->getParameters() as $parameter) {
                $collect($parameter->getType());
            }
        }

        foreach ($class->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() === $class->getName()) {
                $collect($property->getType());
            }
        }

        return array_values(array_unique($types));
    }

    /** @return array<int, class-string> */
    private function appClasses(): array
    {
        $classes = [];

        foreach ($this->phpFiles(app_path()) as $file) {
            $class = $this->classNameOf($file);

            if ($class !== '\\' && class_exists($class)) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
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
