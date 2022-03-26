<?php

declare(strict_types = 1);

namespace RTCKit\Eqivo\Tests\Acceptance\Rest\CancelScheduledHangup;

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
        $promise = self::$browser
            ->post(
                '/v0.1/CancelScheduledHangup/',
                [
                    'Authorization' => self::$authHeader,
                    'Content-type' => 'application/x-www-form-urlencoded',
                ]
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

    public function testBogusSchedHangupId(): void
    {
        $promise = self::$browser
            ->post(
                '/v0.1/CancelScheduledHangup/',
                [
                    'Authorization' => self::$authHeader,
                    'Content-type' => 'application/x-www-form-urlencoded',
                ],
                http_build_query([
                    'SchedHangupId' => '00000000-0000-0000-0000-000000000000',
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
