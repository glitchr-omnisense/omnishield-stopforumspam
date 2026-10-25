<?php

namespace Omnishield\Stopforumspam\Tests;

use Omnishield\GatewayFactoryInterface;
use Omnishield\Model\Identity;
use Omnishield\Stopforumspam\StopforumspamGatewayFactory;
use Omnishield\Testing\GatewayContractTests;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** The family's contract, on StopForumSpam's recorded answers (Fixtures/). */
final class StopforumspamContractTest extends TestCase
{
    use GatewayContractTests;

    protected function factory(HttpClientInterface $http): GatewayFactoryInterface
    {
        return new StopforumspamGatewayFactory($http);
    }

    protected function options(): array
    {
        return [];
    }

    protected function recordedCases(): iterable
    {
        yield 'a Tor exit node' => [new Identity('185.220.101.1'), false, [self::recorded('lookup-tor-exit')]];
        yield 'a blacklisted e-mail' => [new Identity(email: 'testing@xrumer.ru'), false, [self::recorded('lookup-blacklisted-email')], ['hash_email' => false]];
        yield 'reported, without confidence' => [new Identity('1.2.3.4'), true, [self::recorded('lookup-low-confidence')]];
        yield 'no longer listed' => [new Identity('185.220.101.1'), true, [self::recorded('lookup-tor-exit-expired')]];
    }

    private static function recorded(string $name): MockResponse
    {
        return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$name.'.json'), ['response_headers' => ['content-type' => 'application/json;charset=utf-8']]);
    }
}
