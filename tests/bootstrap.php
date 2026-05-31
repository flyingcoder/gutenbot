<?php

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server {
		const READABLE  = 'GET';
		const CREATABLE = 'POST';
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private array $params = [];

		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}

		public function set_param( string $key, $value ): void {
			$this->params[ $key ] = $value;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public array $data;
		public int $status;

		public function __construct( array $data, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}
	}
}
