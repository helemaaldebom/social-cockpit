<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * MIM_SP_Cockpit_Bridge
 *
 * Vuurt een niet-blokkerende, HMAC-getekende webhook af naar Social Cockpit
 * zodra een social_post is aangemaakt door MIM_SP_Zip_Extractor.
 *
 * Configuratie via twee WordPress options (zie de Instellingen-pagina):
 *  - mim_sp_cockpit_webhook_url    (bv. https://socials.burodebom.nl/api/webhook/content)
 *  - mim_sp_cockpit_webhook_secret (gedeeld geheim met de Cockpit)
 *
 * Eén POST per social_post, idempotent dankzij external_id = social_post ID.
 * Stuurt URLs van media in plaats van de bestanden zelf — de Cockpit haalt ze op.
 */
class MIM_SP_Cockpit_Bridge {

    public const OPTION_URL    = 'mim_sp_cockpit_webhook_url';
    public const OPTION_SECRET = 'mim_sp_cockpit_webhook_secret';
    public const POST_META_SENT = '_mim_sp_cockpit_sent';

    /**
     * Hookbaar: koppel deze aan een eigen action vanuit MIM_SP_Zip_Extractor,
     * of roep handmatig aan direct na succesvolle create_social_post().
     */
    public static function register(): void {
        add_action( 'mim_sp_social_post_created', array( __CLASS__, 'send' ), 10, 2 );
    }

    /**
     * @param int   $post_id  ID van de aangemaakte social_post.
     * @param array $data     Originele submissiondata uit MIM_SP_Zip_Extractor::process().
     */
    public static function send( int $post_id, array $data ): void {
        $url    = trim( (string) get_option( self::OPTION_URL, '' ) );
        $secret = trim( (string) get_option( self::OPTION_SECRET, '' ) );

        if ( empty( $url ) || empty( $secret ) ) {
            error_log( '[MIM_SP_BRIDGE] URL of secret ontbreekt, overslaan.' );
            return;
        }

        // Idempotentie: één keer versturen per social_post.
        if ( get_post_meta( $post_id, self::POST_META_SENT, true ) ) {
            return;
        }

        $payload = self::build_payload( $post_id, $data );
        $body    = wp_json_encode( $payload );
        $sig     = 'sha256=' . hash_hmac( 'sha256', $body, $secret );

        $response = wp_remote_post( $url, array(
            'timeout'  => 15,
            'blocking' => true, // wachten zodat we per-post-meta kunnen markeren
            'headers'  => array(
                'Content-Type'        => 'application/json',
                'X-Webhook-Signature' => $sig,
            ),
            'body'     => $body,
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( '[MIM_SP_BRIDGE] HTTP fout: ' . $response->get_error_message() );
            return;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );

        if ( $code >= 200 && $code < 300 ) {
            update_post_meta( $post_id, self::POST_META_SENT, current_time( 'mysql' ) );
            error_log( '[MIM_SP_BRIDGE] Verzonden naar Cockpit (HTTP ' . $code . '), social_post ID ' . $post_id );
        } else {
            error_log( '[MIM_SP_BRIDGE] Cockpit antwoordde HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response ) );
        }
    }

    private static function build_payload( int $post_id, array $data ): array {
        $media_urls = array();

        // Originele MIM-data heeft attachment IDs in $data['attachment_ids'] of we
        // halen ze uit de gekoppelde media_items via ACF/post_parent.
        $attachment_ids = $data['attachment_ids'] ?? array();
        if ( empty( $attachment_ids ) ) {
            $attachment_ids = get_posts( array(
                'post_type'      => 'attachment',
                'post_parent'    => $post_id,
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ) );
        }

        foreach ( $attachment_ids as $att_id ) {
            $url = wp_get_attachment_url( (int) $att_id );
            if ( $url ) {
                $media_urls[] = $url;
            }
        }

        return array(
            'client'        => 'zts',
            'title'         => $data['post_naam'] ?? get_the_title( $post_id ),
            'brief'         => $data['post_beschrijving'] ?? '',
            'original_text' => $data['post_beschrijving'] ?? '',
            'channels'      => array( 'facebook', 'instagram', 'linkedin' ),
            'media_urls'    => $media_urls,
            'external_id'   => 'mim-sp-' . $post_id,
        );
    }
}
