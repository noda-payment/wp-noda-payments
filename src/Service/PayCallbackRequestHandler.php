<?php

declare(strict_types=1);

namespace NodaPay\Button\Service;

use NodaPay\Button\Api\Controller\BaseController;
use NodaPay\Button\NodapaySettings;
use NodaPay\Button\Repository\NodaPaymentsRepository;
use NodaPay\Button\Traits\NodaButtonSettings;
use WP_REST_Response;

class PayCallbackRequestHandler extends AbstractRequestHandler {


	const STATUS_DONE              = 'Done';
	const STATUS_PROCESSING        = 'Processing';
	const STATUS_FAILED            = 'Failed';
	const SUPPORTED_ORDER_STATUSES = [ 'Done', 'Processing', 'Failed' ];

	/**
	 * @var NodaPaymentsRepository
	 */
	private $paymentsRepository;

	use NodaButtonSettings;

	public function __construct( Validator $validator, NodaPaymentsRepository $paymentsRepository ) {
		parent::__construct( $validator );

		$this->paymentsRepository = $paymentsRepository;
	}

	protected function getValidationRules( array $requestData ): array {
		return [
			'PaymentId'         => [
				'required',
			],
			'Status'            => [
				'required',
				function ( $value ) {
					return ! is_string( $value ) ? 'Value is not a valid string' : null;
				},
				function ( $value ) {
					return in_array( $value, self::SUPPORTED_ORDER_STATUSES, true )
						? null
						: sprintf(
							'Value %s is not supported. Supported values are %s',
							$value,
							implode( ', ', self::SUPPORTED_ORDER_STATUSES )
						);
				},
			],
			'Signature'         => [
				'required',
				function( $value, $requestData ) {
					if ( ! isset( $requestData['PaymentId'] ) || ! isset( $requestData['Status'] ) ) {
						return 'Format of response does not allow to check request is genuine';
					}

					return $value === hash(
						'sha256',
						$requestData['PaymentId'] . $requestData['Status'] . $this->getSignatureKey()
					) ? null : 'Invalid signature value';
				},
			],
			'MerchantPaymentId' => [
				'required', // order number, which should be generated by WP
			],
			'Amount'            => [
				'required',
				function ( $value ) {
					return ! is_numeric( $value ) ? 'Value should be a valid number' : null;
				},
				function ( $value ) {
					return (float) $value > 0 ? null : 'Value should be greater then 0.00';
				},
			],
			'Currency'          => [
				'required',
				function ( $value ) {
					return in_array( strtoupper( $value ), NodapaySettings::AVAILABLE_CURRENCIES, true )
						? null
						: sprintf(
							'Currency %s is not supported. Supported values are %s',
							$value,
							implode( ', ', NodapaySettings::AVAILABLE_CURRENCIES )
						);
				},
			],
		];
	}

	protected function doProcessRequest( array $requestData ): WP_REST_Response {
		$this->paymentsRepository->updatePayment(
			(int) $requestData['PaymentId'],
			[ 'payment_status' => $this->mapPaymentStatus( $requestData['Status'] ) ]
		);

		return new WP_REST_Response(
			[ 'success' => true ],
			BaseController::HTTP_OK
		);
	}

	private function mapPaymentStatus( string $paymentStatus ): int {
		switch ( $paymentStatus ) {
			case self::STATUS_DONE:
				return NodaPaymentsRepository::ORDER_STATUS_DONE;
			case self::STATUS_FAILED:
				return NodaPaymentsRepository::ORDER_STATUS_FAILED;
			default:
				return NodaPaymentsRepository::ORDER_STATUS_PROCESSING;
		}
	}
}
