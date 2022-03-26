<?php

declare(strict_types = 1);

namespace RTCKit\Eqivo\Tests\Acceptance\Rest\RecordStartStop;

use RTCKit\Eqivo\Tests\Acceptance\AbstractAcceptanceTest;

use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;
use React\EventLoop\Loop;
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

    public function testRecordStartStop(): void
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
                    'AnswerUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testRecordStartStop/answer',
                    'HangupUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testRecordStartStop/hangup',
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

                self::$deferred['testRecordStartStop/answer'] = new Deferred;

                return self::$deferred['testRecordStartStop/answer']->promise();
            })
            ->then(function ($args): PromiseInterface {
                $this->assertIsArray($args);
                $this->assertEquals(Uuid::UUID_TYPE_RANDOM, Uuid::fromString($args['CallUUID'])->getVersion());
                $this->assertEquals('Alice', $args['From']);
                $this->assertEquals('Bob', $args['To']);
                $this->assertEquals('outbound', $args['Direction']);
                $this->assertEquals('in-progress', $args['CallStatus']);

                return all([
                    'response' => self::$browser
                        ->post(
                            '/v0.1/RecordStart/',
                            [
                                'Authorization' => self::$authHeader,
                                'Content-type' => 'application/x-www-form-urlencoded',
                            ],
                            http_build_query([
                                'CallUUID' => $args['CallUUID'],
                                'FileFormat' => 'wav',
                                'FilePath' => '/tmp',
                                'FileName' => 'test',
                            ])
                        ),
                    'CallUUID' => $args['CallUUID'],
                ]);
            })
            ->then(function ($args): PromiseInterface {
                $body = json_decode((string)$args['response']->getBody());

                $this->assertNotNull($body);
                $this->assertInstanceOf('stdClass', $body);
                $this->assertTrue($body->Success);
                $this->assertEquals('/tmp/test.wav', $body->RecordFile);

                $deferred = new Deferred();
                self::$deferred['default/record'] = new Deferred();

                Loop::addTimer(3, function () use ($deferred, $args) {
                    self::$browser->post(
                        '/v0.1/RecordStop/',
                        [
                            'Authorization' => self::$authHeader,
                            'Content-type' => 'application/x-www-form-urlencoded',
                        ],
                        http_build_query([
                            'CallUUID' => $args['CallUUID'],
                            'RecordFile' => '/tmp/test.wav',
                        ])
                    )
                        ->then(function ($response) use ($deferred) {
                            $deferred->resolve($response);
                        });
                });

                return all([
                    'response' => $deferred->promise(),
                    'notify' => self::$deferred['default/record']->promise(),
                ]);
            })
            ->then(function ($args): PromiseInterface {
                $this->assertEquals('/tmp/test.wav', $args['notify']['RecordFile']);
                $this->assertGreaterThanOrEqual(3, (int)$args['notify']['RecordDuration']);

                $body = json_decode((string)$args['response']->getBody());
                $this->assertNotNull($body);
                $this->assertInstanceOf('stdClass', $body);
                $this->assertTrue($body->Success);

                self::$deferred['testRecordStartStop/hangup'] = new Deferred;
                self::$deferred['default/hangup'] = new Deferred;

                return all([
                    'aliceHangup' => self::$deferred['testRecordStartStop/hangup']->promise(),
                    'bobHangup' => self::$deferred['default/hangup']->promise(),
                    'hangup' => self::$esl->api((new ESL\Request\Api)->setParameters("fsctl hupall")),
                ]);
            })
            ->then(function ($args): PromiseInterface {
                $ended = microtime(true);

                $this->assertIsArray($args['aliceHangup']);
                $this->assertEquals(Uuid::UUID_TYPE_RANDOM, Uuid::fromString($args['aliceHangup']['CallUUID'])->getVersion());
                $this->assertEquals('Alice', $args['aliceHangup']['From']);
                $this->assertEquals('Bob', $args['aliceHangup']['To']);
                $this->assertEquals('outbound', $args['aliceHangup']['Direction']);
                $this->assertEquals('completed', $args['aliceHangup']['CallStatus']);
                $this->assertEquals('MANAGER_REQUEST', $args['aliceHangup']['HangupCause']);

                $this->assertIsArray($args['bobHangup']);
                $this->assertEquals(Uuid::UUID_TYPE_RANDOM, Uuid::fromString($args['bobHangup']['CallUUID'])->getVersion());
                $this->assertEquals('Alice', $args['bobHangup']['From']);
                $this->assertEquals('Bob', $args['bobHangup']['To']);
                $this->assertEquals('inbound', $args['bobHangup']['Direction']);
                $this->assertEquals('completed', $args['bobHangup']['CallStatus']);
                $this->assertEquals('MANAGER_REQUEST', $args['bobHangup']['HangupCause']);

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
                    '<Play>silence_stream://30000</Play>' .
                    '</Response>'
                );

            case '/testRecordStartStop/answer':
                self::$deferred['testRecordStartStop/answer']->resolve($args);

                return new Response(
                    200, ['Content-Type' => 'application/xml'],
                    '<?xml version="1.0" encoding="UTF-8"?><Response>' .
                    '<Play>silence_stream://30000</Play>' .
                    '</Response>'
                );

            case '/default/record':
                self::$deferred['default/record']->resolve($args);
                break;

            case '/default/hangup':
                self::$deferred['default/hangup']->resolve($args);
                break;

            case '/testRecordStartStop/hangup':
                self::$deferred['testRecordStartStop/hangup']->resolve($args);
                break;
        }

        return new Response(200, ['Content-Type' => 'text/plain'], 'OK');
    }
}
