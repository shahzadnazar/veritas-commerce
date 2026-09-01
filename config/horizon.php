<?php

declare(strict_types=1);

use App\Modules\Identity\Enums\AdminPermission;
use App\Support\Queues;
use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    |
    | This name appears in notifications and in the Horizon UI. Unique names
    | can be useful while running multiple instances of Horizon within an
    | application, allowing you to identify the Horizon you're viewing.
    |
    */

    'name' => env('HORIZON_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    // Under the admin prefix, because that is what it is: an operator
    // tool, not a public page. The middleware below is what actually
    // enforces that — the path only keeps the URL honest.
    'path' => env('HORIZON_PATH', 'admin/queues'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug((string) env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    /*
     * The full admin chain, not the framework default of ['web'].
     *
     * Horizon ships open to any signed-in session, which here would mean
     * any customer. Job payloads carry ids, email addresses and reasons, so
     * the dashboard sits behind the admin guard, a confirmed second factor,
     * and a permission that not every admin role holds.
     */
    'middleware' => ['web', 'auth:admin', 'admin.mfa', 'admin.can:'.AdminPermission::ViewQueues->value],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    'waits' => [
        'redis:default' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    /*
     * One supervisor per queue, not one pool draining all of them.
     *
     * The point of the separation is isolation: a thousand queued image
     * derivatives must not put a payment webhook behind them, and a search
     * reindex must not delay a seller's approval email. Sharing a pool
     * would give exactly that, however the queues were ordered.
     *
     * Timeouts are per queue's real work — image processing is allowed
     * minutes, a state transition is not — and every supervisor retries,
     * because the jobs are idempotent by design (see App\Support\Queues).
     */
    'defaults' => [
        'critical' => [
            'connection' => 'redis',
            'queue' => [Queues::CRITICAL],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 1,
            'maxProcesses' => 3,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            // Money and state changes: retried, and quickly, because a
            // transient failure here holds up an order.
            'tries' => 5,
            'backoff' => [5, 15, 60],
            'timeout' => 60,
            'nice' => 0,
        ],

        'emails' => [
            'connection' => 'redis',
            'queue' => [Queues::EMAILS],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 1,
            'maxProcesses' => 3,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            // A mail provider blip should not lose the message; the gaps
            // widen so a sustained outage is not hammered.
            'tries' => 5,
            'backoff' => [30, 120, 600],
            'timeout' => 60,
            'nice' => 0,
        ],

        'catalogue' => [
            'connection' => 'redis',
            'queue' => [Queues::CATALOGUE, Queues::DEFAULT],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 1,
            'maxProcesses' => 4,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 192,
            'tries' => 3,
            'backoff' => [10, 60],
            'timeout' => 120,
            'nice' => 0,
        ],

        'media' => [
            'connection' => 'redis',
            'queue' => [Queues::MEDIA],
            'balance' => 'auto',
            'autoScalingStrategy' => 'size',
            'minProcesses' => 1,
            'maxProcesses' => 4,
            'maxTime' => 0,
            'maxJobs' => 0,
            // Decoding a large image needs room; this is the one pool that
            // legitimately holds a bitmap in memory.
            'memory' => 512,
            'tries' => 3,
            'backoff' => [30, 120],
            'timeout' => 300,
            // Deliberately deprioritised at the OS level too.
            'nice' => 5,
        ],

        'search' => [
            'connection' => 'redis',
            'queue' => [Queues::SEARCH],
            'balance' => 'auto',
            'autoScalingStrategy' => 'size',
            'minProcesses' => 1,
            'maxProcesses' => 2,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 192,
            'tries' => 3,
            'backoff' => [30, 120],
            'timeout' => 120,
            'nice' => 5,
        ],
    ],

    'environments' => [
        'production' => [
            'critical' => ['maxProcesses' => 10, 'balanceMaxShift' => 2, 'balanceCooldown' => 3],
            'emails' => ['maxProcesses' => 6],
            'catalogue' => ['maxProcesses' => 8],
            'media' => ['maxProcesses' => 6],
            'search' => ['maxProcesses' => 4],
        ],

        'staging' => [
            'critical' => ['maxProcesses' => 3],
            'emails' => ['maxProcesses' => 2],
            'catalogue' => ['maxProcesses' => 3],
            'media' => ['maxProcesses' => 2],
            'search' => ['maxProcesses' => 2],
        ],

        'local' => [
            'critical' => ['maxProcesses' => 2],
            'emails' => ['maxProcesses' => 1],
            'catalogue' => ['maxProcesses' => 2],
            'media' => ['maxProcesses' => 1],
            'search' => ['maxProcesses' => 1],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watcher Configuration
    |--------------------------------------------------------------------------
    |
    | The following list of directories and files will be watched when using
    | the `horizon:listen` command. Whenever any directories or files are
    | changed, Horizon will automatically restart to apply all changes.
    |
    */

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
