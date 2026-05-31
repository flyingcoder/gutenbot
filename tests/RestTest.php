<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use GutenBot\AiClient;
use GutenBot\Rest;
use PHPUnit\Framework\TestCase;

class RestTest extends TestCase {

	private Rest $rest;

	/** @var AiClient&\PHPUnit\Framework\MockObject\MockObject */
	private AiClient $ai_client;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->ai_client = $this->createMock( AiClient::class );
		$this->rest      = new Rest( $this->ai_client );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// check_permission
	// ------------------------------------------------------------------

	public function test_check_permission_returns_true_for_authorised_user(): void {
		Functions\expect( 'current_user_can' )
			->once()
			->with( 'edit_pages' )
			->andReturn( true );

		$this->assertTrue( $this->rest->check_permission() );
	}

	public function test_check_permission_returns_false_for_unauthorised_user(): void {
		Functions\expect( 'current_user_can' )
			->once()
			->with( 'edit_pages' )
			->andReturn( false );

		$this->assertFalse( $this->rest->check_permission() );
	}

	// ------------------------------------------------------------------
	// get_status
	// ------------------------------------------------------------------

	public function test_get_status_returns_200_with_masked_url(): void {
		$long_url = 'https://abcdefghijklmnopqrstuvwxyz.supabase.co/functions/v1/gutenbot';

		Functions\expect( 'get_option' )
			->with( 'aipb_edge_function_url', '' )
			->andReturn( $long_url );

		Functions\expect( 'get_option' )
			->with( 'aipb_client_id', '' )
			->andReturn( '550e8400-e29b-41d4-a716-446655440000' );

		Functions\expect( 'get_option' )
			->with( 'aipb_onboarding_status', 'pending' )
			->andReturn( 'complete' );

		Functions\expect( 'get_option' )
			->with( 'aipb_onboarded_at', null )
			->andReturn( '2026-01-01 00:00:00' );

		$request  = new \WP_REST_Request();
		$response = $this->rest->get_status( $request );

		$this->assertSame( 200, $response->status );
		$this->assertTrue( $response->data['success'] );
		$this->assertStringEndsWith( '...', $response->data['data']['edge_function_url'] );
		$this->assertLessThanOrEqual( 33, strlen( $response->data['data']['edge_function_url'] ) );
	}

	public function test_get_status_returns_empty_string_when_url_not_configured(): void {
		Functions\expect( 'get_option' )
			->with( 'aipb_edge_function_url', '' )
			->andReturn( '' );

		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( '' );

		$request  = new \WP_REST_Request();
		$response = $this->rest->get_status( $request );

		$this->assertSame( '', $response->data['data']['edge_function_url'] );
	}

	// ------------------------------------------------------------------
	// generate
	// ------------------------------------------------------------------

	public function test_generate_returns_200_on_success(): void {
		$this->ai_client
			->expects( $this->once() )
			->method( 'generate' )
			->with( 'Build a landing page for our SaaS product.', 'landing', 42 )
			->willReturn( [
				'success' => true,
				'data'    => [ 'sections' => [], 'full_markup' => '<!-- wp:group -->' ],
			] );

		$request = new \WP_REST_Request();
		$request->set_param( 'content', 'Build a landing page for our SaaS product.' );
		$request->set_param( 'page_type', 'landing' );
		$request->set_param( 'post_id', 42 );

		$response = $this->rest->generate( $request );

		$this->assertSame( 200, $response->status );
		$this->assertTrue( $response->data['success'] );
	}

	public function test_generate_returns_500_when_ai_client_fails(): void {
		$this->ai_client
			->expects( $this->once() )
			->method( 'generate' )
			->willReturn( [ 'success' => false, 'error' => 'Edge Function URL not configured.' ] );

		$request = new \WP_REST_Request();
		$request->set_param( 'content', 'Some content.' );
		$request->set_param( 'page_type', 'about' );
		$request->set_param( 'post_id', 7 );

		$response = $this->rest->generate( $request );

		$this->assertSame( 500, $response->status );
		$this->assertFalse( $response->data['success'] );
	}

	// ------------------------------------------------------------------
	// rate
	// ------------------------------------------------------------------

	public function test_rate_returns_200_on_success(): void {
		$uuid = '550e8400-e29b-41d4-a716-446655440000';

		$this->ai_client
			->expects( $this->once() )
			->method( 'rate' )
			->with( $uuid, 1, false )
			->willReturn( true );

		$request = new \WP_REST_Request();
		$request->set_param( 'section_id', $uuid );
		$request->set_param( 'rating', 1 );
		$request->set_param( 'was_edited', false );

		$response = $this->rest->rate( $request );

		$this->assertSame( 200, $response->status );
		$this->assertTrue( $response->data['success'] );
	}

	public function test_rate_returns_500_when_ai_client_fails(): void {
		$uuid = '550e8400-e29b-41d4-a716-446655440000';

		$this->ai_client
			->expects( $this->once() )
			->method( 'rate' )
			->with( $uuid, -1, true )
			->willReturn( false );

		$request = new \WP_REST_Request();
		$request->set_param( 'section_id', $uuid );
		$request->set_param( 'rating', -1 );
		$request->set_param( 'was_edited', true );

		$response = $this->rest->rate( $request );

		$this->assertSame( 500, $response->status );
		$this->assertFalse( $response->data['success'] );
	}
}
