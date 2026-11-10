<?php

namespace Omnishield\Stopforumspam\Tests;

use Omnisense\Contracts\Policy\UsageNotPermitted;
use Omnisense\Contracts\Policy\UsagePolicy;
use Omnishield\Exception\UnreachableException;
use Omnishield\Exception\UsageLimitReachedException;
use Omnishield\Model\Identity;
use Omnishield\Policy\GuardedReputation;
use Omnishield\Registry;
use Omnishield\Stopforumspam\StopforumspamGateway;
use Omnishield\Stopforumspam\StopforumspamGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Its usage policy: declared as data, read by the gateway, enforced through the registry. */
final class PolicyTest extends TestCase
{
    public function testThePackageDeclaresItsPolicy(): void
    {
        $policy = UsagePolicy::fromFile(StopforumspamGateway::POLICY);
        $gateway = (new StopforumspamGatewayFactory(new MockHttpClient()))->create([]);

        self::assertSame('stopforumspam', $policy->provider, 'named after the factory');
        self::assertSame('2026-11-10', $policy->provenance->verifiedAt);
        self::assertSame('contract', $policy->rights->commercial->value);
        self::assertSame([20000], array_map(static fn ($l) => $l->max, $policy->rateLimits ?? []));
        self::assertEquals($policy, $gateway->capabilities()->policy());
        self::assertMatchesRegularExpression('~"omnisense": \{\s*"policy": "policy\.json"~', (string) file_get_contents(__DIR__.'/../composer.json'), 'composer.json names it');
    }

    public function testA429AndItsRetryAfterHoldTheNextCallThroughTheRegistry(): void
    {
        $requests = 0;
        $http = new MockHttpClient(static function () use (&$requests) {
            ++$requests;

            return new MockResponse('Too Many Requests', ['http_code' => 429, 'response_headers' => ['retry-after' => '60']]);
        });
        $registry = new Registry([new StopforumspamGatewayFactory($http)], ['site' => ['factory' => 'stopforumspam', 'options' => [], 'usage' => ['commercial' => false]]]);
        foreach ([60, null] as $i => $wait) {
            try {
                $registry->reputation('site')->lookup(new Identity('192.0.2.10'));
                self::fail('refused');
            } catch (UnreachableException $e) {
                null === $wait ? self::assertGreaterThan(0, $e->retryAfter()) : self::assertSame($wait, $e->retryAfter());
            }
        }
        self::assertSame(1, $requests, 'the second call was held, nothing sent');
    }

    public function testACommercialSiteNeedsTheTermsExceptionAndTheDailyLimitIsGuarded(): void
    {
        $factory = new StopforumspamGatewayFactory(new MockHttpClient());
        $registry = new Registry([$factory], [
            'shop' => ['factory' => 'stopforumspam', 'usage' => ['commercial' => true]],
            'internal' => ['factory' => 'stopforumspam', 'usage' => ['commercial' => true, 'contract' => true]],
            'forum' => ['factory' => 'stopforumspam', 'policy' => ['rateLimits' => [['max' => 1, 'window' => 'day', 'operations' => ['lookup']]]]],
        ]);

        $list = $registry->reputation('internal');
        self::assertInstanceOf(GuardedReputation::class, $list, 'its daily limit: behind its guard');
        self::assertInstanceOf(StopforumspamGateway::class, $list->inner(), 'report() stays on the gateway itself');
        self::assertFalse($registry->reputation('forum')->lookup(new Identity())->known, 'an empty identity asks nothing');
        try {
            $registry->reputation('forum')->lookup(new Identity());
            self::fail('a second lookup the same day');
        } catch (UsageLimitReachedException $e) {
            self::assertSame(1, $e->limit?->max);
        }
        $this->expectException(UsageNotPermitted::class);
        $registry->reputation('shop');
    }
}
