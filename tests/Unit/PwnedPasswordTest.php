<?php

namespace Tests\Unit;

use App\Rules\PwnedPassword;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class PwnedPasswordTest extends TestCase
{
    public function test_compromised_password_is_rejected_using_k_anonymity(): void
    {
        $hash = strtoupper(sha1('Password1234!'));
        Http::fake(['api.pwnedpasswords.com/range/*' => Http::response(
            str_repeat('A', 35).":1\r\n".substr($hash, 5).':42'
        )]);
        $validator = Validator::make(['password' => 'Password1234!'], ['password' => [new PwnedPassword]]);

        $this->assertTrue($validator->fails());
        $this->assertSame(
            ['Ce mot de passe apparaît dans une fuite de données. Choisissez-en un autre.'],
            $validator->errors()->get('password')
        );
        Http::assertSent(fn ($request) => str_ends_with($request->url(), substr($hash, 0, 5))
            && ! str_contains($request->url(), substr($hash, 5))
            && ! str_contains($request->url(), 'Password1234'));
    }

    public function test_unlisted_password_is_accepted(): void
    {
        Http::fake(['api.pwnedpasswords.com/range/*' => Http::response('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA:1')]);
        $this->assertFalse(Validator::make(['password' => 'Unique-safe-password-84!'], ['password' => [new PwnedPassword]])->fails());
    }

    public function test_service_failure_and_malformed_response_fail_closed(): void
    {
        Http::fake(['api.pwnedpasswords.com/range/*' => Http::response('', 503)]);
        $this->assertTrue(Validator::make(['password' => 'Unique-safe-password-84!'], ['password' => [new PwnedPassword]])->fails());

        Http::fake(['api.pwnedpasswords.com/range/*' => Http::response('not-a-range-response')]);
        $this->assertTrue(Validator::make(['password' => 'Unique-safe-password-84!'], ['password' => [new PwnedPassword]])->fails());
    }

    public function test_connection_failure_fails_closed_with_an_explicit_message(): void
    {
        Http::fake(fn () => throw new \RuntimeException('network unavailable'));

        $validator = Validator::make(['password' => 'Unique-safe-password-84!'], ['password' => [new PwnedPassword]]);

        $this->assertTrue($validator->fails());
        $this->assertSame(
            ['La sécurité du mot de passe ne peut pas être vérifiée pour le moment. Réessayez plus tard.'],
            $validator->errors()->get('password')
        );
    }
}
