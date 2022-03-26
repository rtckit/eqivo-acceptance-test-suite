<?php

declare(strict_types = 1);

namespace RTCKit\Eqivo\Tests\Acceptance\Rest\ConferenceSpeak;

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

    public static array $members = [];

    public function testConferenceSpeak(): void
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
                    'AnswerUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testConferenceSpeak/answer',
                    'HangupUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testConferenceSpeak/hangup',
                ])
            )
            ->then(function ($response): PromiseInterface {
                self::$deferred['default/answer'] = new Deferred;

                return self::$deferred['default/answer']->promise();
            })
            ->then(function ($args): PromiseInterface {
                self::$deferred['testConferenceSpeak/answer'] = new Deferred;

                return self::$deferred['testConferenceSpeak/answer']->promise();
            })
            ->then(function ($args): PromiseInterface {
                self::$deferred['testConferenceSpeak/enter'] = new Deferred;

                return self::$deferred['testConferenceSpeak/enter']->promise();
            })
            ->then(function ($args): PromiseInterface {
                return self::$browser->post(
                    '/v0.1/ConferenceSpeak/',
                    [
                        'Authorization' => self::$authHeader,
                        'Content-type' => 'application/x-www-form-urlencoded',
                    ],
                    http_build_query([
                        'ConferenceName' => 'conf',
                        'MemberID' => 'all',
                        'Text' => 'hello folks',
                    ])
                );
            })
            ->then(function ($response): PromiseInterface {
                $body = json_decode((string)$response->getBody());

                $this->assertNotNull($body);
                $this->assertInstanceOf('stdClass', $body);
                $this->assertTrue($body->Success);

                self::$deferred['testConferenceSpeak/hangup'] = new Deferred;
                self::$deferred['default/hangup'] = new Deferred;
                self::$deferred['testConferenceSpeak/exit'] = new Deferred;

                return all([
                    'aliceHangup' => self::$deferred['testConferenceSpeak/hangup']->promise(),
                    'bobHangup' => self::$deferred['default/hangup']->promise(),
                    'exit' => self::$deferred['testConferenceSpeak/exit']->promise(),
                    'hangup' => self::$esl->api((new ESL\Request\Api)->setParameters("fsctl hupall")),
                ]);
            })
            ->then(function ($args): PromiseInterface {
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
                    '<Conference callbackUrl="http://' . self::CONSUMER_SOCKET_ADDR . '/testConferenceSpeak/conference">conf</Conference>' .
                    '</Response>'
                );

            case '/testConferenceSpeak/answer':
                self::$deferred['testConferenceSpeak/answer']->resolve($args);

                return new Response(
                    200, ['Content-Type' => 'application/xml'],
                    '<?xml version="1.0" encoding="UTF-8"?><Response>' .
                    '<Conference callbackUrl="http://' . self::CONSUMER_SOCKET_ADDR . '/testConferenceSpeak/conference">conf</Conference>' .
                    '</Response>'
                );

            case '/default/hangup':
                self::$deferred['default/hangup']->resolve($args);
                break;

            case '/testConferenceSpeak/hangup':
                self::$deferred['testConferenceSpeak/hangup']->resolve($args);
                break;

            case '/testConferenceSpeak/conference':
                switch ($args['ConferenceAction']) {
                    case 'enter':
                        self::$members[] = $args['ConferenceMemberID'];

                        if (count(self::$members) === 2) {
                            self::$deferred['testConferenceSpeak/enter']->resolve($args);
                        }
                        break;

                    case 'exit':
                        array_pop(self::$members);

                        if (count(self::$members) === 0) {
                            self::$deferred['testConferenceSpeak/exit']->resolve($args);
                        }
                        break;
                }
                break;
        }

        return new Response(200, ['Content-Type' => 'text/plain'], 'OK');
    }
}
