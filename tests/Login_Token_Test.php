<?php
/**
 * Browser login token lifecycle.
 *
 * @package MammothWP\SelfTest
 */

namespace MammothWP\SelfTest\Tests;

use MammothWP\SelfTest\Browser\Login_Token;
use PHPUnit\Framework\TestCase;

final class Login_Token_Test extends TestCase
{
    protected function setUp(): void
    {
        \Wp_Stub_State::reset();
    }

    public function test_token_is_single_use()
    {
        $token = Login_Token::issue(7);

        $this->assertTrue(Login_Token::is_valid($token));
        $this->assertSame(7, Login_Token::consume($token));
        $this->assertSame(0, Login_Token::consume($token));
        $this->assertFalse(Login_Token::is_valid($token));
    }

    public function test_only_the_hash_is_stored()
    {
        $token = Login_Token::issue(7);

        $this->assertStringNotContainsString($token, wp_json_encode(\Wp_Stub_State::$options));
    }

    public function test_token_expires()
    {
        $token = Login_Token::issue(7, 1000);

        $this->assertTrue(Login_Token::is_valid($token, 1000 + Login_Token::TTL));
        $this->assertFalse(Login_Token::is_valid($token, 1001 + Login_Token::TTL));
        $this->assertSame(0, Login_Token::consume($token, 1001 + Login_Token::TTL));
    }

    public function test_unknown_or_empty_tokens_are_refused()
    {
        Login_Token::issue(7);

        $this->assertFalse(Login_Token::is_valid('nope'));
        $this->assertFalse(Login_Token::is_valid(''));
        $this->assertSame(0, Login_Token::consume('nope'));
    }

    public function test_revoke_all_destroys_sessions_and_drops_records()
    {
        $token = Login_Token::issue(7);
        Login_Token::consume($token);
        Login_Token::attach_session($token, 'session-a');

        $this->assertSame(1, Login_Token::revoke_all());
        $this->assertSame(['session-a'], \WP_Session_Tokens::$destroyed);
        $this->assertArrayNotHasKey(Login_Token::OPTION, \Wp_Stub_State::$options);
        $this->assertFalse(Login_Token::is_valid($token));
    }

    public function test_refused_in_production_whatever_the_filters_say()
    {
        \Wp_Stub_State::$environment = 'production';
        add_filter('mmwp_self_test_ui_enabled', function () {
            return true;
        });

        $this->assertFalse(Login_Token::available());
        $this->assertInstanceOf(\WP_Error::class, Login_Token::issue(7));
        $this->assertSame([], \Wp_Stub_State::$options);
    }

    public function test_tokens_issued_before_production_are_not_redeemable_there()
    {
        $token = Login_Token::issue(7);
        \Wp_Stub_State::$environment = 'production';

        $this->assertSame(0, Login_Token::consume($token));
    }

    public function test_refused_when_the_ui_gate_is_off()
    {
        add_filter('mmwp_self_test_ui_enabled', function () {
            return false;
        });

        $this->assertInstanceOf(\WP_Error::class, Login_Token::issue(7));
    }
}
