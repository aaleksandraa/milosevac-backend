<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic test example.
     */
    public function test_the_application_redirects_to_the_react_frontend(): void
    {
        $this->seed();

        $response = $this->get('/');

        $response->assertRedirect('http://localhost:8080/');
    }
}
