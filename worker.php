<?php
declare(strict_types=1);

/*
 * Continuous-mode entrypoint (used by start.sh / Railway).
 *
 * Unlike index.php (one attempt per cron tick), this runs forever, retrying
 * every POLL_INTERVAL_SECONDS (default 60s) - or however long the OCI rate-limit
 * wait requires - until it successfully creates the instance, then stops.
 *
 * Progress is written to public/status.json on every attempt, for the status page.
 */

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Hitrov\FileCache;
use Hitrov\OciApi;
use Hitrov\OciConfig;
use Hitrov\StatusWriter;
use Hitrov\TooManyRequestsWaiter;
use Hitrov\Worker;

$envFilename = empty($argv[1]) ? '.env' : $argv[1];
$dotenv = Dotenv::createUnsafeImmutable(__DIR__, $envFilename);
$dotenv->safeLoad();

$config = new OciConfig(
    getenv('OCI_REGION'),
    getenv('OCI_USER_ID'),
    getenv('OCI_TENANCY_ID'),
    getenv('OCI_KEY_FINGERPRINT'),
    getenv('OCI_PRIVATE_KEY_FILENAME'),
    getenv('OCI_AVAILABILITY_DOMAIN') ?: null,
    getenv('OCI_SUBNET_ID'),
    getenv('OCI_IMAGE_ID'),
    (int) getenv('OCI_OCPUS'),
    (int) getenv('OCI_MEMORY_IN_GBS')
);

$bootVolumeSizeInGBs = (string) getenv('OCI_BOOT_VOLUME_SIZE_IN_GBS');
$bootVolumeId = (string) getenv('OCI_BOOT_VOLUME_ID');
if ($bootVolumeSizeInGBs) {
    $config->setBootVolumeSizeInGBs($bootVolumeSizeInGBs);
} elseif ($bootVolumeId) {
    $config->setBootVolumeId($bootVolumeId);
}

$api = new OciApi();
if (getenv('CACHE_AVAILABILITY_DOMAINS')) {
    $api->setCache(new FileCache($config));
}

$waiter = null;
if (getenv('TOO_MANY_REQUESTS_TIME_WAIT')) {
    $waiter = new TooManyRequestsWaiter((int) getenv('TOO_MANY_REQUESTS_TIME_WAIT'));
    $api->setWaiter($waiter);
}

$notifier = new \Hitrov\Notification\Telegram();

$shape = getenv('OCI_SHAPE');

$maxRunningInstancesOfThatShape = 1;
if (getenv('OCI_MAX_INSTANCES') !== false) {
    $maxRunningInstancesOfThatShape = (int) getenv('OCI_MAX_INSTANCES');
}

$pollIntervalSeconds = 60;
if (getenv('POLL_INTERVAL_SECONDS') !== false && getenv('POLL_INTERVAL_SECONDS') !== '') {
    $pollIntervalSeconds = (int) getenv('POLL_INTERVAL_SECONDS');
}

$worker = new Worker(
    $api,
    $config,
    $shape,
    $maxRunningInstancesOfThatShape,
    (string) getenv('OCI_SSH_PUBLIC_KEY'),
    $notifier,
    $waiter,
    $pollIntervalSeconds
);

$statusWriter = new StatusWriter(__DIR__ . '/public/status.json');
$startedAt = date(DATE_ATOM);
$region = getenv('OCI_REGION');

$statusWriter->write([
    'status' => 'starting',
    'message' => 'Worker starting up...',
    'instance' => null,
    'attempts' => 0,
    'startedAt' => $startedAt,
    'shape' => $shape,
    'region' => $region,
]);

echo "[{$startedAt}] worker started, polling every {$pollIntervalSeconds}s (shape: {$shape}, region: {$region})\n";

$worker->run(function (array $result, int $attempts) use ($statusWriter, $startedAt, $shape, $region) {
    echo sprintf("[%s] attempt #%d: %s - %s\n", date('c'), $attempts, $result['status'], $result['message']);

    $statusWriter->write(array_merge($result, [
        'attempts' => $attempts,
        'startedAt' => $startedAt,
        'shape' => $shape,
        'region' => $region,
    ]));
});

echo date('c') . " instance created (or already existed) - stopping, no further attempts will be made.\n";
