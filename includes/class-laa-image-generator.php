<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The "сгенерировать картинку" half of the cover module. Two providers,
 * picked by the same preset-style approach as the text models:
 *   — Yandex ART: async, returns an operation id you then poll;
 *   — any OpenAI-compatible /v1/images/generations endpoint: synchronous.
 * Both hand back raw image bytes; sideloading is LAA_Image_Service's job.
 */
class LAA_Image_Generator {

	const YANDEX_START  = 'https://llm.api.cloud.yandex.net/foundationModels/v1/imageGenerationAsync';
	const YANDEX_POLL   = 'https://llm.api.cloud.yandex.net/operations/';
	const POLL_ATTEMPTS = 20;
	const POLL_INTERVAL = 3; // seconds

	public static function providers() {
		return array(
			'yandex_art' => 'Yandex ART',
			'openai'     => 'OpenAI-совместимый (/v1/images/generations)',
		);
	}

	public static function ready( $provider = null ) {
		$s        = LAA_Settings::get_all();
		$provider = $provider ?: $s['image_provider'];

		if ( 'openai' === $provider ) {
			return '' !== $s['openai_images_key'];
		}
		return '' !== $s['yandex_api_key'] && '' !== $s['yandex_folder_id'];
	}

	/**
	 * @return array{ok:bool,bytes:string,mime:string,error:string}
	 */
	public static function generate( $prompt ) {
		$provider = LAA_Settings::get( 'image_provider', 'yandex_art' );

		if ( ! self::ready( $provider ) ) {
			return self::fail( 'Не заданы ключи доступа к генератору картинок' );
		}

		return 'openai' === $provider
			? self::generate_openai( $prompt )
			: self::generate_yandex( $prompt );
	}

	/** Builds the prompt from the template, with {title}/{theme}/{keywords} filled in. */
	public static function build_prompt( $title, $theme, $keywords ) {
		$template = (string) LAA_Settings::get( 'image_prompt_template', '' );
		if ( '' === trim( $template ) ) {
			$template = '{title}';
		}
		return strtr(
			$template,
			array(
				'{title}'    => (string) $title,
				'{theme}'    => (string) $theme,
				'{keywords}' => (string) $keywords,
			)
		);
	}

	// -------------------------------------------------------------- Yandex

	private static function generate_yandex( $prompt ) {
		$s = LAA_Settings::get_all();

		$messages = array(
			array(
				'text'   => $prompt,
				'weight' => 1,
			),
		);
		if ( '' !== trim( (string) $s['image_negative_prompt'] ) ) {
			$messages[] = array(
				'text'   => $s['image_negative_prompt'],
				'weight' => -1,
			);
		}

		$body = array(
			'modelUri'          => 'art://' . $s['yandex_folder_id'] . '/' . ltrim( $s['yandex_art_model'], '/' ),
			'generationOptions' => array(
				'mimeType'    => 'image/jpeg',
				'aspectRatio' => array(
					'widthRatio'  => '16',
					'heightRatio' => '9',
				),
			),
			'messages'          => $messages,
		);

		$response = wp_remote_post(
			self::YANDEX_START,
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Api-Key ' . $s['yandex_api_key'],
					'x-folder-id'   => $s['yandex_folder_id'],
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 60,
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::fail( 'Yandex ART: ' . $response->get_error_message() );
		}

		$code   = wp_remote_retrieve_response_code( $response );
		$parsed = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $parsed['id'] ) ) {
			return self::fail( 'Yandex ART HTTP ' . $code . ': ' . mb_substr( wp_remote_retrieve_body( $response ), 0, 300 ) );
		}

		return self::poll_yandex( $parsed['id'], $s['yandex_api_key'] );
	}

	/**
	 * Image generation takes tens of seconds, so the API is an operation you
	 * poll. This blocks the tick for up to a minute — acceptable for one
	 * article a day, and the alternative (a second scheduled pass) would mean
	 * publishing the article without its cover and editing it afterwards.
	 */
	private static function poll_yandex( $operation_id, $api_key ) {
		for ( $i = 0; $i < self::POLL_ATTEMPTS; $i++ ) {
			sleep( self::POLL_INTERVAL );

			$response = wp_remote_get(
				self::YANDEX_POLL . rawurlencode( $operation_id ),
				array(
					'headers' => array( 'Authorization' => 'Api-Key ' . $api_key ),
					'timeout' => 30,
				)
			);

			if ( is_wp_error( $response ) ) {
				continue;
			}

			$parsed = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $parsed ) ) {
				continue;
			}

			if ( ! empty( $parsed['error'] ) ) {
				$message = is_array( $parsed['error'] ) ? ( $parsed['error']['message'] ?? wp_json_encode( $parsed['error'] ) ) : $parsed['error'];
				return self::fail( 'Yandex ART: ' . $message );
			}

			if ( ! empty( $parsed['done'] ) ) {
				$base64 = $parsed['response']['image'] ?? '';
				if ( '' === $base64 ) {
					return self::fail( 'Yandex ART: операция завершена без картинки' );
				}
				$bytes = base64_decode( $base64, true );
				if ( false === $bytes ) {
					return self::fail( 'Yandex ART: не удалось декодировать картинку' );
				}
				return self::ok( $bytes, 'image/jpeg' );
			}
		}

		return self::fail( 'Yandex ART: превышено время ожидания генерации' );
	}

	// -------------------------------------------------------------- OpenAI

	private static function generate_openai( $prompt ) {
		$s = LAA_Settings::get_all();

		$response = wp_remote_post(
			$s['openai_images_endpoint'],
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $s['openai_images_key'],
				),
				'body'    => wp_json_encode(
					array(
						'model'  => $s['openai_images_model'],
						'prompt' => $prompt,
						'n'      => 1,
						'size'   => $s['openai_images_size'],
					)
				),
				'timeout' => 180,
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::fail( 'Генератор картинок: ' . $response->get_error_message() );
		}

		$code   = wp_remote_retrieve_response_code( $response );
		$parsed = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			return self::fail( 'Генератор картинок HTTP ' . $code . ': ' . mb_substr( wp_remote_retrieve_body( $response ), 0, 300 ) );
		}

		$item = $parsed['data'][0] ?? array();

		if ( ! empty( $item['b64_json'] ) ) {
			$bytes = base64_decode( $item['b64_json'], true );
			if ( false === $bytes ) {
				return self::fail( 'Генератор картинок: не удалось декодировать ответ' );
			}
			return self::ok( $bytes, 'image/png' );
		}

		if ( ! empty( $item['url'] ) ) {
			$file = wp_remote_get( $item['url'], array( 'timeout' => 120 ) );
			if ( is_wp_error( $file ) ) {
				return self::fail( 'Генератор картинок: не удалось скачать результат — ' . $file->get_error_message() );
			}
			$bytes = wp_remote_retrieve_body( $file );
			if ( '' === $bytes ) {
				return self::fail( 'Генератор картинок: пустой файл по ссылке' );
			}
			return self::ok( $bytes, wp_remote_retrieve_header( $file, 'content-type' ) ?: 'image/png' );
		}

		return self::fail( 'Генератор картинок: в ответе нет картинки' );
	}

	private static function ok( $bytes, $mime ) {
		return array(
			'ok'    => true,
			'bytes' => $bytes,
			'mime'  => $mime,
			'error' => '',
		);
	}

	private static function fail( $error ) {
		return array(
			'ok'    => false,
			'bytes' => '',
			'mime'  => '',
			'error' => $error,
		);
	}
}
