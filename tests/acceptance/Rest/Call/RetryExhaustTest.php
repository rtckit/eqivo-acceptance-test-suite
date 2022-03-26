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
use RTCKit\ESL;
use function React\Promise\{
    all,
    resolve
};
use function Clue\React\Block\await;

/**
 *
 */
class RetryExhaustTest extends AbstractAcceptanceTest
{
    public static array $deferred = [];

    public function testRetryExhaust(): void
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
                    'Gateways' => 'really/,bogus/,gateway/,strings/',
                    'AnswerUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testRetryExhaust/answer',
                    'HangupUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testRetryExhaust/hangup',
                    'GatewayTimeouts' => 1,
                    'GatewayRetries' => '3,3,3,3'
                ])
            )
            ->then(function ($response): PromiseInterface {
                $body = json_decode((string)$response->getBody());
                $this->assertNotNull($body);
                $this->assertInstanceOf('stdClass', $body);
                $this->assertTrue($body->Success);

                self::$deferred['testRetryExhaust/hangup'] = new Deferred;

                return self::$deferred['testRetryExhaust/hangup']->promise();
            });

        await($promise);
    }

    public static function onRestXmlRequest(ServerRequestInterface $request)
    {
        self::$deferred['testRetryExhaust/hangup']->resolve();

        return new Response(200, ['Content-Type' => 'text/plain'], 'OK');
    }
}
