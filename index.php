<?php
declare(strict_types=1);


// useful when script is being executed by cron user
$pathPrefix = ''; // e.g. /usr/share/nginx/oci-arm-host-capacity/

require "{$pathPrefix}vendor/autoload.php";

use Dotenv\Dotenv;
use Hitrov\FileCache;
use Hitrov\OciApi;
use Hitrov\OciConfig;
use Hitrov\TooManyRequestsWaiter;
use Hitrov\Worker;

$envFilename = empty($argv[1]) ? '.env' : $argv[1];
$dotenv = Dotenv::createUnsafeImmutable(__DIR__, $envFilename);
$dotenv->safeLoad();

/*
 * No need to modify any value in this file anymore!
 * Copy .env.example to .env and adjust there instead.
 *
 * README.md now has all the information.
 */
$config = new OciConfig(
    getenv('OCI_REGION'),
    getenv('OCI_USER_ID'),
    getenv('OCI_TENANCY_ID'),
    getenv('OCI_KEY_FINGERPRINT'),
    getenv('OCI_PRIVATE_KEY_FILENAME'),
    getenv('OCI_AVAILABILITY_DOMAIN') ?: null, // null or '' or 'jYtI:PHX-AD-1' or ['jYtI:PHX-AD-1','jYtI:PHX-AD-2']
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

/*
 * if you have own https://core.telegram.org/bots
 * and set TELEGRAM_BOT_API_KEY and your TELEGRAM_USER_ID in .env
 *
 * then you can get notified when script will succeed.
 * otherwise - don't mind OR develop you own NotifierInterface
 * to e.g. send SMS or email.
 */
$notifier = new \Hitrov\Notification\Telegram();

$shape = getenv('OCI_SHAPE');

$maxRunningInstancesOfThatShape = 1;
if (getenv('OCI_MAX_INSTANCES') !== false) {
    $maxRunningInstancesOfThatShape = (int) getenv('OCI_MAX_INSTANCES');
}

$worker = new Worker(
    $api,
    $config,
    $shape,
    $maxRunningInstancesOfThatShape,
    (string) getenv('OCI_SSH_PUBLIC_KEY'),
    $notifier,
    $waiter
);

// makes exactly one attempt, same as before - meant to be re-run by cron/GitHub Actions.
// unlike before, a rate-limit wait (TooManyRequestsWaiterException) is reported as a normal
// message instead of an uncaught fatal error.
$result = $worker->attemptOnce();

echo "{$result['message']}\n";
if ($result['status'] === 'success' && !empty($result['instance'])) {
    echo json_encode($result['instance'], JSON_PRETTY_PRINT) . "\n";
}
