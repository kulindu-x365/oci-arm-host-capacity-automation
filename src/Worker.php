<?php
declare(strict_types=1);

namespace Hitrov;

use Hitrov\Exception\ApiCallException;
use Hitrov\Exception\TooManyRequestsWaiterException;
use Hitrov\Interfaces\NotifierInterface;
use Hitrov\Interfaces\TooManyRequestsWaiterInterface;

class Worker
{
    private OciApi $api;
    private OciConfig $config;
    private string $shape;
    private int $maxRunningInstancesOfThatShape;
    private string $sshKey;
    private ?NotifierInterface $notifier;
    private ?TooManyRequestsWaiterInterface $waiter;
    private int $pollIntervalSeconds;

    public function __construct(
        OciApi $api,
        OciConfig $config,
        string $shape,
        int $maxRunningInstancesOfThatShape,
        string $sshKey,
        ?NotifierInterface $notifier = null,
        ?TooManyRequestsWaiterInterface $waiter = null,
        int $pollIntervalSeconds = 60
    ) {
        $this->api = $api;
        $this->config = $config;
        $this->shape = $shape;
        $this->maxRunningInstancesOfThatShape = $maxRunningInstancesOfThatShape;
        $this->sshKey = $sshKey;
        $this->notifier = $notifier;
        $this->waiter = $waiter;
        $this->pollIntervalSeconds = max(1, $pollIntervalSeconds);
    }

    /**
     * Calls attemptOnce() in a loop, sleeping between attempts, until it succeeds.
     * Never throws - every failure is reported to $onUpdate and the loop continues.
     *
     * @param callable|null $onUpdate function(array $result, int $attempts): void
     */
    public function run(?callable $onUpdate = null): void
    {
        $attempts = 0;

        while (true) {
            $attempts++;
            $result = $this->attemptOnce();

            if ($onUpdate) {
                $onUpdate($result, $attempts);
            }

            if ($result['status'] === 'success') {
                return;
            }

            sleep($this->sleepSecondsFor($result));
        }
    }

    /**
     * Makes a single attempt to find capacity and create the instance.
     * Never throws - all failures are reported through the returned array.
     *
     * @return array{status: string, message: string, instance: array|null}
     */
    public function attemptOnce(): array
    {
        try {
            return $this->doAttempt();
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'instance' => null,
            ];
        }
    }

    private function doAttempt(): array
    {
        $instances = $this->api->getInstances($this->config);

        $existingMessage = $this->api->checkExistingInstances(
            $this->config,
            $instances,
            $this->shape,
            $this->maxRunningInstancesOfThatShape
        );
        if ($existingMessage) {
            return [
                'status' => 'success',
                'message' => trim($existingMessage),
                'instance' => null,
            ];
        }

        if (!empty($this->config->availabilityDomains)) {
            $availabilityDomains = is_array($this->config->availabilityDomains)
                ? $this->config->availabilityDomains
                : [$this->config->availabilityDomains];
        } else {
            $availabilityDomains = $this->api->getAvailabilityDomains($this->config);
        }

        foreach ($availabilityDomains as $availabilityDomainEntity) {
            $availabilityDomain = is_array($availabilityDomainEntity)
                ? $availabilityDomainEntity['name']
                : $availabilityDomainEntity;

            try {
                $instanceDetails = $this->api->createInstance(
                    $this->config,
                    $this->shape,
                    $this->sshKey,
                    $availabilityDomain
                );
            } catch (TooManyRequestsWaiterException $e) {
                return [
                    'status' => 'rate_limited',
                    'message' => $e->getMessage(),
                    'instance' => null,
                ];
            } catch (ApiCallException $e) {
                $message = $e->getMessage();
                if (
                    $e->getCode() === 500 &&
                    strpos($message, 'InternalError') !== false &&
                    strpos($message, 'Out of host capacity') !== false
                ) {
                    // try next availability domain
                    sleep(16);
                    continue;
                }

                return [
                    'status' => 'error',
                    'message' => $message,
                    'instance' => null,
                ];
            }

            // success
            $this->notify($instanceDetails);

            return [
                'status' => 'success',
                'message' => "Instance created in $availabilityDomain",
                'instance' => $instanceDetails,
            ];
        }

        return [
            'status' => 'searching',
            'message' => 'Out of host capacity in all availability domains, will retry',
            'instance' => null,
        ];
    }

    private function notify(array $instanceDetails): void
    {
        if (!$this->notifier || !$this->notifier->isSupported()) {
            return;
        }

        try {
            $this->notifier->notify(json_encode($instanceDetails, JSON_PRETTY_PRINT));
        } catch (\Throwable $e) {
            // notification failures must never affect the outcome of the attempt
        }
    }

    private function sleepSecondsFor(array $result): int
    {
        if ($result['status'] === 'rate_limited' && $this->waiter && $this->waiter->isConfigured()) {
            $remaining = $this->waiter->secondsRemaining();
            if ($remaining > 0) {
                return $remaining;
            }
        }

        return $this->pollIntervalSeconds;
    }
}
