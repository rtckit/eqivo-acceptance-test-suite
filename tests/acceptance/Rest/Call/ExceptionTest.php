<?php

declare(strict_types = 1);

namespace RTCKit\Eqivo\Tests\Acceptance\Rest\Call;

use RTCKit\Eqivo\Tests\Acceptance\AbstractAcceptanceTest;

use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;
use React\Http\Message\Response;
use React\Promise\{
    Deferred,
    PromiseInterface
};
use function React\Promise\{
    all,
    resolve
};
use function Clue\React\Block\await;

/**
 *
 */
class ExceptionTest extends AbstractAcceptanceTest
{
    public function testMissingArguments(): void
    {
        $args = [
            'From' => 'Alice',
            'To' => 'Bob',
            'Gateways' => 'user/',
            'AnswerUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testMissingArguments',
        ];

        foreach (self::getArgCombinations($args) as $comb) {
            $promise = self::$browser
                ->post(
                    '/v0.1/Call/',
                    [
                        'Authorization' => self::$authHeader,
                        'Content-type' => 'application/x-www-form-urlencoded',
                    ],
                    http_build_query($comb)
                )
                ->then(function ($response) use ($comb) {
                    $this->assertEquals(200, $response->getStatusCode());

                    $body = json_decode((string)$response->getBody());
                    $this->assertNotNull($body);
                    $this->assertInstanceOf('stdClass', $body);

                    if (count($comb) !== 4) {
                        $this->assertFalse($body->Success);
                    } else {
                        $this->assertTrue($body->Success);
                        $this->assertNotEmpty($body->RequestUUID);
                        $this->assertEquals(Uuid::UUID_TYPE_RANDOM, Uuid::fromString($body->RequestUUID)->getVersion());
                    }

                    return resolve();
                });

            await($promise);
        }
    }

    public function testBadAnswerUrl(): void
    {
        $promise = self::$browser
            ->post(
                '/v0.1/Call/',
                [
                    'Authorization' => self::$authHeader,
                    'Content-type' => 'application/x-www-form-urlencoded',
                ],
                http_build_query([
                    'From' => 'Alice',
                    'To' => 'Bob',
                    'Gateways' => 'user/',
                    'AnswerUrl' => 'rather:/bogus',
                ])
            )
            ->then(function ($response) {
                $body = json_decode((string)$response->getBody());
                $this->assertNotNull($body);
                $this->assertInstanceOf('stdClass', $body);
                $this->assertFalse($body->Success);

                return resolve();
            });

        await($promise);
    }

    public function testBadHangupUrl(): void
    {
        $promise = self::$browser
            ->post(
                '/v0.1/Call/',
                [
                    'Authorization' => self::$authHeader,
                    'Content-type' => 'application/x-www-form-urlencoded',
                ],
                http_build_query([
                    'From' => 'Alice',
                    'To' => 'Bob',
                    'Gateways' => 'user/',
                    'AnswerUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testBadHangupUrl',
                    'HangupUrl' => 'rather:/bogus',
                ])
            )
            ->then(function ($response) {
                $body = json_decode((string)$response->getBody());
                $this->assertNotNull($body);
                $this->assertInstanceOf('stdClass', $body);
                $this->assertFalse($body->Success);

                return resolve();
            });

        await($promise);
    }

    public function testBadRingUrl(): void
    {
        $promise = self::$browser
            ->post(
                '/v0.1/Call/',
                [
                    'Authorization' => self::$authHeader,
                    'Content-type' => 'application/x-www-form-urlencoded',
                ],
                http_build_query([
                    'From' => 'Alice',
                    'To' => 'Bob',
                    'Gateways' => 'user/',
                    'AnswerUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testBadRingUrl',
                    'RingUrl' => 'rather:/bogus',
                ])
            )
            ->then(function ($response) {
                $body = json_decode((string)$response->getBody());
                $this->assertNotNull($body);
                $this->assertInstanceOf('stdClass', $body);
                $this->assertFalse($body->Success);

                return resolve();
            });

        await($promise);
    }

    public function testUnknownCore(): void
    {
        $promise = self::$browser
            ->post(
                '/v0.1/Call/',
                [
                    'Authorization' => self::$authHeader,
                    'Content-type' => 'application/x-www-form-urlencoded',
                ],
                http_build_query([
                    'From' => 'Alice',
                    'CallerName' => 'Alice',
                    'To' => 'Bob',
                    'TimeLimit' => 3,
                    'Gateways' => 'sofia/gateway/a/',
                    'AnswerUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testUnknownCore/answer',
                    'HangupUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testUnknownCore/hangup',
                    'RingUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testUnknownCore/ring',
                    'GatewayCodecs' => "'PCMA'",
                    'GatewayTimeouts' => 1,
                    'GatewayRetries' => 1,
                    'AccountSID' => 'acceptance_test',
                    'CoreUUID' => '00000000-0000-0000-0000-000000000000',
                ])
            )
            ->then(function ($response): PromiseInterface {
                $this->assertEquals(200, $response->getStatusCode());

                $body = json_decode((string)$response->getBody());
                $this->assertNotNull($body);
                $this->assertInstanceOf('stdClass', $body);
                $this->assertFalse($body->Success);

                return resolve();
            });

        await($promise);
    }

    public function testNoCores(): void
    {
        $core = self::$app->allocateCore();
        $this->assertNotNull($core);
        self::$app->removeCore($core->uuid);

        $promise = self::$browser
            ->post(
                '/v0.1/Call/',
                [
                    'Authorization' => self::$authHeader,
                    'Content-type' => 'application/x-www-form-urlencoded',
                ],
                http_build_query([
                    'From' => 'Alice',
                    'CallerName' => 'Alice',
                    'To' => 'Bob',
                    'TimeLimit' => 3,
                    'Gateways' => 'sofia/gateway/a/',
                    'AnswerUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testNoCores/answer',
                    'HangupUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testNoCores/hangup',
                    'RingUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testNoCores/ring',
                    'GatewayCodecs' => "'PCMA'",
                    'GatewayTimeouts' => 1,
                    'GatewayRetries' => 1,
                    'AccountSID' => 'acceptance_test',
                ])
            )
            ->then(function ($response): PromiseInterface {
                $this->assertEquals(200, $response->getStatusCode());

                $body = json_decode((string)$response->getBody());
                $this->assertNotNull($body);
                $this->assertInstanceOf('stdClass', $body);
                $this->assertFalse($body->Success);

                return resolve();
            });

        await($promise);

        self::$app->addCore($core);
    }

    public static function onRestXmlRequest(ServerRequestInterface $request)
    {
        return new Response(
            200, ['Content-Type' => 'application/xml'],
            '<?xml version="1.0" encoding="UTF-8"?><Response>' .
            '<Hangup />' .
            '</Response>'
        );
    }
}
