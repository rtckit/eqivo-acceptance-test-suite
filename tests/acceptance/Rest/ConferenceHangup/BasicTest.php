<?php

declare(strict_types = 1);

namespace RTCKit\Eqivo\Tests\Acceptance\Rest\ConferenceHangup;

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

    public static string $huped;

    public function testConferenceHangup(): void
    {
        $this->doConferenceHangup();
    }

    public function testPartlyBogusConferenceHangup(): void
    {
        $this->doConferenceHangup(',not,here');
    }

    public function doConferenceHangup(string $bogusMembers = ''): void
    {
        self::$members = [];

        $promise = all([
            self::$browser
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
                        'AnswerUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testConferenceHangup/answer',
                        'HangupUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testConferenceHangup/hangup',
                    ])
                ),
            self::$browser
                ->post(
                    '/v0.1/Call/',
                    [
                        'Authorization' => self::$authHeader,
                        'Content-type' => 'application/x-www-form-urlencoded',
                    ],
                    http_build_query([
                        'From' => 'Alice',
                        'To' => 'Carol',
                        'Gateways' => 'sofia/gateway/a/',
                        'AnswerUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testConferenceHangup/answer',
                        'HangupUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testConferenceHangup/hangup',
                    ])
                ),
        ])
            ->then(function ($response): PromiseInterface {
                self::$deferred['default/answer'] = new Deferred;

                return self::$deferred['default/answer']->promise();
            })
            ->then(function ($args): PromiseInterface {
                self::$deferred['testConferenceHangup/answer'] = new Deferred;
                self::$deferred['testConferenceHangup/enter'] = new Deferred;

                return all([
                    self::$deferred['testConferenceHangup/answer']->promise(),
                    self::$deferred['testConferenceHangup/enter']->promise(),
                ]);
            })
            ->then(function ($args) use ($bogusMembers): PromiseInterface {
                self::$huped = end(self::$members);

                return self::$browser->post(
                    '/v0.1/ConferenceHangup/',
                    [
                        'Authorization' => self::$authHeader,
                        'Content-type' => 'application/x-www-form-urlencoded',
                    ],
                    http_build_query([
                        'ConferenceName' => 'conf',
                        'MemberID' => self::$huped . $bogusMembers,
                    ])
                );
            })
            ->then(function ($response): PromiseInterface {
                $body = json_decode((string)$response->getBody());
                $this->assertNotNull($body);
                $this->assertInstanceOf('stdClass', $body);
                $this->assertTrue($body->Success);
                $this->assertEquals([self::$huped], $body->Members);

                $deferred = new Deferred();

                Loop::addTimer(5, function () use ($deferred) {
                    self::$esl->api((new ESL\Request\Api)->setParameters("conference conf xml_list"))
                        ->then(function ($response) use ($deferred) {
                            $deferred->resolve($response);
                        });
                });

                return $deferred->promise();
            })
            ->then(function ($response) use ($bogusMembers): PromiseInterface {
                $count = 0;
                $list = simplexml_load_string($response->getBody());
                $this->assertNotNull($list);

                foreach ($list->conference->members->member as $member) {
                    $attrs = $member->attributes();

                    if (!isset($attrs['type']) || ((string)$attrs['type'] !== 'caller')) {
                        continue;
                    }

                    $count++;
                    $this->assertNotEquals(self::$huped, (string)$member->id);
                }

                $this->assertEquals(count(self::$members), $count);

                self::$deferred['testConferenceHangup/hangup'] = new Deferred;
                self::$deferred['default/hangup'] = new Deferred;
                self::$deferred['testConferenceHangup/exit'] = new Deferred;

                return all([
                    'aliceHangup' => self::$deferred['testConferenceHangup/hangup']->promise(),
                    'bobHangup' => self::$deferred['default/hangup']->promise(),
                    'exit' => self::$deferred['testConferenceHangup/exit']->promise(),
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
                    '<Conference callbackUrl="http://' . self::CONSUMER_SOCKET_ADDR . '/testConferenceHangup/conference">conf</Conference>' .
                    '</Response>'
                );

            case '/testConferenceHangup/answer':
                self::$deferred['testConferenceHangup/answer']->resolve($args);

                return new Response(
                    200, ['Content-Type' => 'application/xml'],
                    '<?xml version="1.0" encoding="UTF-8"?><Response>' .
                    '<Wait length="3600" />' .
                    '</Response>'
                );

            case '/default/hangup':
                self::$deferred['default/hangup']->resolve($args);
                break;

            case '/testConferenceHangup/hangup':
                self::$deferred['testConferenceHangup/hangup']->resolve($args);
                break;

            case '/testConferenceHangup/conference':
                switch ($args['ConferenceAction']) {
                    case 'enter':
                        self::$members[] = $args['ConferenceMemberID'];

                        if (count(self::$members) === 2) {
                            self::$deferred['testConferenceHangup/enter']->resolve($args);
                        }
                        break;

                    case 'exit':
                        array_pop(self::$members);

                        if (count(self::$members) === 0) {
                            self::$deferred['testConferenceHangup/exit']->resolve($args);
                        }
                        break;
                }
                break;
        }

        return new Response(200, ['Content-Type' => 'text/plain'], 'OK');
    }
}
