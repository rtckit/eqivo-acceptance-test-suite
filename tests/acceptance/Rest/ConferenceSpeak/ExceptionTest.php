<?php

declare(strict_types = 1);

namespace RTCKit\Eqivo\Tests\Acceptance\Rest\ConferenceSpeak;

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
            'ConferenceName' => 'conf',
            'Text' => 'this is a test',
            'MemberID' => 'all',
        ];

        foreach (self::getArgCombinations($args) as $comb) {
            $promise = self::$browser
                ->post(
                    '/v0.1/ConferenceSpeak/',
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

                    if (count($comb) !== 3) {
                        $this->assertFalse($body->Success);
                    } else {
                        // Still fails since the conference doesn't exist
                        $this->assertFalse($body->Success);
                    }

                    return resolve();
                });

            await($promise);
        }
    }

    public function testBogusConferenceName(): void
    {
        $promise = self::$browser
            ->post(
                '/v0.1/ConferenceSpeak/',
                [
                    'Authorization' => self::$authHeader,
                    'Content-type' => 'application/x-www-form-urlencoded',
                ],
                http_build_query([
                    'ConferenceName' => 'bogus',
                    'Text' => 'hello everyone',
                    'MemberID' => 'all',
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

    public static function onRestXmlRequest(ServerRequestInterface $request)
    {
    }
}
