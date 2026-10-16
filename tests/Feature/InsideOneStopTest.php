<?php

namespace Maico\OneStop\Tests\Feature;

use Maico\OneStop\Tests\Fixtures\User;
use Maico\OneStop\Tests\TestCase;

class InsideOneStopTest extends TestCase
{
    public function test_the_head_tag_marks_pages_shown_inside_one_stop_and_hides_standalone_parts(): void
    {
        $html = (string) $this->blade('<x-one-stop::head />');

        $this->assertStringContainsString("classList.add('in-one-stop')", $html);
        $this->assertStringContainsString('html.in-one-stop .standalone-only{display:none!important}', $html);
        $this->assertStringContainsString('html:not(.in-one-stop) .one-stop-only{display:none!important}', $html);

        config(['one-stop.enabled' => false]);
        $this->assertSame('', trim((string) $this->blade('<x-one-stop::head />')));
    }

    public function test_inside_one_stop_the_sign_in_page_goes_to_one_stop_instead(): void
    {
        $this->withHeader('Sec-Fetch-Dest', 'iframe')
            ->get('/login')
            ->assertRedirect(route('one-stop.redirect', ['embedded' => 1]));
    }

    public function test_opened_directly_the_sign_in_page_is_unchanged(): void
    {
        $this->get('/login')->assertOk()->assertSee('login page');
        $this->withHeader('Sec-Fetch-Dest', 'document')->get('/login')->assertOk();
    }

    public function test_password_sign_in_inside_one_stop_can_be_allowed(): void
    {
        config(['one-stop.password_sign_in_inside_one_stop' => true]);

        $this->withHeader('Sec-Fetch-Dest', 'iframe')->get('/login')->assertOk();
    }

    public function test_nothing_changes_while_one_stop_is_disabled_or_for_signed_in_people(): void
    {
        $this->addOneStopColumns();
        $this->actingAs(User::create(['name' => 'A', 'email' => 'a@b.test', 'password' => 'x']));
        $this->withHeader('Sec-Fetch-Dest', 'iframe')->get('/login')->assertOk();

        auth()->logout();
        config(['one-stop.enabled' => false]);
        $this->withHeader('Sec-Fetch-Dest', 'iframe')->get('/login')->assertOk();
    }

    public function test_other_pages_inside_one_stop_are_unchanged(): void
    {
        $this->withHeader('Sec-Fetch-Dest', 'iframe')->get('/')->assertRedirect('/login');
    }
}
