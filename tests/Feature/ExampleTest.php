<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The landing page redirects visitors to the admin login.
     */
    public function test_the_application_redirects_to_the_admin_login(): void
    {
        $this->get('/')->assertRedirect(route('admin.login'));
    }
}
