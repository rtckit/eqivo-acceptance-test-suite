<?php

declare(strict_types = 1);

namespace RTCKit\Eqivo\Tests\Acceptance\Rest\HangupAllCalls;

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
class BasicTest extends AbstractAcceptanceTest
{
    public static array $deferred = [];

    public function testHangupAllCalls(): void
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
                    'Gateways' => 'sofia/gateway/a/',
                    'AnswerUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testHangupAllCalls/answer',
                    'HangupUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testHangupAllCalls/hangup',
                ])
            )
            ->then(function ($response): PromiseInterface {
                $body = json_decode((string)$response->getBody());
                $this->assertNotNull($body);
                $this->assertInstanceOf('stdClass', $body);
                $this->assertTrue($body->Success);

                self::$deferred['default/answer'] = new Deferred;

                return self::$deferred['default/answer']->promise();
            })
            ->then(function ($args): PromiseInterface {
                $this->assertIsArray($args);
                $this->assertEquals(Uuid::UUID_TYPE_RANDOM, Uuid::fromString($args['CallUUID'])->getVersion());
                $this->assertEquals('Alice', $args['From']);
                $this->assertEquals('Bob', $args['To']);
                $this->assertEquals('inbound', $args['Direction']);
                $this->assertEquals('ringing', $args['CallStatus']);

                self::$deferred['testHangupAllCalls/answer'] = new Deferred;

                return self::$deferred['testHangupAllCalls/answer']->promise();
            })
            ->then(function ($args): PromiseInterface {
                $this->assertIsArray($args);
                $this->assertEquals(Uuid::UUID_TYPE_RANDOM, Uuid::fromString($args['CallUUID'])->getVersion());
                $this->assertEquals('Alice', $args['From']);
                $this->assertEquals('Bob', $args['To']);
                $this->assertEquals('outbound', $args['Direction']);
                $this->assertEquals('in-progress', $args['CallStatus']);

                self::$deferred['testHangupAllCalls/hangup'] = new Deferred;
                self::$deferred['default/hangup'] = new Deferred;

                return all([
                    'aliceHangup' => self::$deferred['testHangupAllCalls/hangup']->promise(),
                    'bobHangup' => self::$deferred['default/hangup']->promise(),
                    'hangup' => self::$browser->post(
                        '/v0.1/HangupAllCalls/',
                        [
                            'Authorization' => self::$authHeader,
                            'Content-type' => 'application/x-www-form-urlencoded',
                        ]
                    ),
                ]);
            })
            ->then(function ($args): PromiseInterface {
                $this->assertIsArray($args['aliceHangup']);
                $this->assertEquals(Uuid::UUID_TYPE_RANDOM, Uuid::fromString($args['aliceHangup']['CallUUID'])->getVersion());
                $this->assertEquals('Alice', $args['aliceHangup']['From']);
                $this->assertEquals('Bob', $args['aliceHangup']['To']);
                $this->assertEquals('outbound', $args['aliceHangup']['Direction']);
                $this->assertEquals('completed', $args['aliceHangup']['CallStatus']);
                $this->assertEquals('NORMAL_CLEARING', $args['aliceHangup']['HangupCause']);

                $this->assertIsArray($args['bobHangup']);
                $this->assertEquals(Uuid::UUID_TYPE_RANDOM, Uuid::fromString($args['bobHangup']['CallUUID'])->getVersion());
                $this->assertEquals('Alice', $args['bobHangup']['From']);
                $this->assertEquals('Bob', $args['bobHangup']['To']);
                $this->assertEquals('inbound', $args['bobHangup']['Direction']);
                $this->assertEquals('completed', $args['bobHangup']['CallStatus']);
                $this->assertEquals('NORMAL_CLEARING', $args['bobHangup']['HangupCause']);

                return resolve();
            });

        await($promise);
    }

    public static function onRestXmlRequest(ServerRequestInterface $request)
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        $args = $request->getParsedBody() ?? [];

        switch ($path) {
            case '/default/answer':
                self::$deferred['default/answer']->resolve($args);

                return new Response(
                    200, ['Content-Type' => 'application/xml'],
                    '<?xml version="1.0" encoding="UTF-8"?><Response>' .
                    '<Play>silence_stream://3600000</Play>' .
                    '</Response>'
                );

            case '/testHangupAllCalls/answer':
                self::$deferred['testHangupAllCalls/answer']->resolve($args);

                return new Response(
                    200, ['Content-Type' => 'application/xml'],
                    '<?xml version="1.0" encoding="UTF-8"?><Response>' .
                    '<Play>silence_stream://3600000</Play>' .
                    '</Response>'
                );

            case '/default/hangup':
                self::$deferred['default/hangup']->resolve($args);
                break;

            case '/testHangupAllCalls/hangup':
                self::$deferred['testHangupAllCalls/hangup']->resolve($args);
                break;

            case '/testHangupRequest/hangup':
                self::$deferred['testHangupRequest/hangup']->resolve($args);
                break;
        }

        return new Response(200, ['Content-Type' => 'text/plain'], 'OK');
    }
}
