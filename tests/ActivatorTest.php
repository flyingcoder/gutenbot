<?php

namespace GutenBot\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use GutenBot\Activator;
use PHPUnit\Framework\TestCase;

class ActivatorTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_uuid_generated_on_first_activation(): void {
        $this->expectNotToPerformAssertions();

        Functions\expect( 'get_option' )
            ->once()
            ->with( 'aipb_client_id' )
            ->andReturn( false );

        Functions\expect( 'wp_generate_uuid4' )
            ->once()
            ->andReturn( '550e8400-e29b-41d4-a716-446655440000' );

        Functions\expect( 'update_option' )
            ->once()
            ->with( 'aipb_client_id', '550e8400-e29b-41d4-a716-446655440000', false );

        Functions\expect( 'wp_next_scheduled' )
            ->once()
            ->with( 'aipb_onboarding_scan' )
            ->andReturn( false );

        Functions\expect( 'wp_schedule_single_event' )->once();

        Activator::activate();
    }

    public function test_uuid_not_overwritten_on_reactivation(): void {
        $this->expectNotToPerformAssertions();

        Functions\expect( 'get_option' )
            ->once()
            ->with( 'aipb_client_id' )
            ->andReturn( '550e8400-e29b-41d4-a716-446655440000' );

        Functions\expect( 'wp_generate_uuid4' )->never();
        Functions\expect( 'update_option' )->never();

        Functions\expect( 'wp_next_scheduled' )
            ->once()
            ->with( 'aipb_onboarding_scan' )
            ->andReturn( false );

        Functions\expect( 'wp_schedule_single_event' )->once();

        Activator::activate();
    }

    public function test_cron_not_double_scheduled(): void {
        $this->expectNotToPerformAssertions();

        Functions\expect( 'get_option' )
            ->once()
            ->with( 'aipb_client_id' )
            ->andReturn( '550e8400-e29b-41d4-a716-446655440000' );

        Functions\expect( 'wp_next_scheduled' )
            ->once()
            ->with( 'aipb_onboarding_scan' )
            ->andReturn( 1700000100 ); // already scheduled

        Functions\expect( 'wp_schedule_single_event' )->never();

        Activator::activate();
    }
}
