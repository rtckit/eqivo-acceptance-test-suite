<?php

declare(strict_types = 1);

namespace RTCKit\Eqivo\Tests\Acceptance\Rest\ConferenceList;

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

    public function testConferenceList(): void
    {
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
                        'AnswerUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testConferenceList/answer',
                        'HangupUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testConferenceList/hangup',
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
                        'AnswerUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testConferenceList/answer',
                        'HangupUrl' => 'http://' . self::CONSUMER_SOCKET_ADDR . '/testConferenceList/hangup',
                    ])
                ),
        ])
            ->then(function ($response): PromiseInterface {
                self::$deferred['default/answer'] = new Deferred;

                return self::$deferred['default/answer']->promise();
            })
            ->then(function ($args): PromiseInterface {
                self::$deferred['testConferenceList/answer'] = new Deferred;

                return self::$deferred['testConferenceList/answer']->promise();
            })
            ->then(function ($args): PromiseInterface {
                self::$deferred['testConferenceList/enter'] = new Deferred;

                return self::$deferred['testConferenceList/enter']->promise();
            })
            ->then(function ($args): PromiseInterface {
                return self::$browser->post(
                    '/v0.1/ConferenceList/',
                    [
                        'Authorization' => self::$authHeader,
                        'Content-type' => 'application/x-www-form-urlencoded',
                    ]
                );
            })
            ->then(function ($response): PromiseInterface {
                $body = json_decode((string)$response->getBody());
                $this->assertNotNull($body);
                $this->assertInstanceOf('stdClass', $body);
                $this->assertTrue($body->Success);
                $this->assertNotNull($body->List);
                $this->assertNotNull($body->List->Bob);
                $this->assertNotNull($body->List->Carol);
                $this->assertEquals(count(self::$members), $body->List->Bob->ConferenceMemberCount + $body->List->Carol->ConferenceMemberCount);

                return self::$esl->api((new ESL\Request\Api)->setParameters("conference xml_list"));
            })
            ->then(function ($response): PromiseInterface {
                $count = 0;
                $list = simplexml_load_string($response->getBody());
                $this->assertNotNull($list);

                foreach ($list as $conference) {
                    foreach ($conference->members->member as $member) {
                        $attrs = $member->attributes();

                        if (!isset($attrs['type']) || ((string)$attrs['type'] !== 'caller')) {
                            continue;
                        }

                        $count++;
                        $this->assertContains((string)$member->id, self::$members);
                    }
                }

                $this->assertEquals(count(self::$members), $count);

                self::$deferred['inbound/Alice/Bob/hangup'] = new Deferred;
                self::$deferred['inbound/Alice/Carol/hangup'] = new Deferred;
                self::$deferred['outbound/Alice/Bob/hangup'] = new Deferred;
                self::$deferred['outbound/Alice/Carol/hangup'] = new Deferred;
                self::$deferred['testConferenceList/exit'] = new Deferred;

                return all([
                    'inbound/Alice/Bob/hangup' => self::$deferred['inbound/Alice/Bob/hangup']->promise(),
                    'inbound/Alice/Carol/hangup' => self::$deferred['inbound/Alice/Carol/hangup']->promise(),
                    'outbound/Alice/Bob/hangup' => self::$deferred['outbound/Alice/Bob/hangup']->promise(),
                    'outbound/Alice/Carol/hangup' => self::$deferred['outbound/Alice/Carol/hangup']->promise(),
                    'exit' => self::$deferred['testConferenceList/exit']->promise(),
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
                    '<Conference callbackUrl="http://' . self::CONSUMER_SOCKET_ADDR . '/testConferenceList/conference">' . $args['To'] . '</Conference>' .
                    '</Response>'
                );

            case '/testConferenceList/answer':
                self::$deferred['testConferenceList/answer']->resolve($args);

                return new Response(
                    200, ['Content-Type' => 'application/xml'],
                    '<?xml version="1.0" encoding="UTF-8"?><Response>' .
                    '<Conference callbackUrl="http://' . self::CONSUMER_SOCKET_ADDR . '/testConferenceList/conference">' . $args['To'] . '</Conference>' .
                    '</Response>'
                );

            case '/default/hangup':
            case '/testConferenceList/hangup':
                self::$deferred["{$args['Direction']}/{$args['From']}/{$args['To']}/hangup"]->resolve($args);
                break;

            case '/testConferenceList/conference':
                switch ($args['ConferenceAction']) {
                    case 'enter':
                        self::$members[] = $args['ConferenceMemberID'];

                        if (count(self::$members) === 4) {
                            self::$deferred['testConferenceList/enter']->resolve($args);
                        }
                        break;

                    case 'exit':
                        array_pop(self::$members);

                        if (count(self::$members) === 0) {
                            self::$deferred['testConferenceList/exit']->resolve($args);
                        }
                        break;
                }
                break;
        }

        return new Response(200, ['Content-Type' => 'text/plain'], 'OK');
    }
}
