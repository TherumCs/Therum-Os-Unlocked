<?php
/**
 * Plugin Name: Therum SEO
 * Description: Completes site SEO without a third-party plugin. Bricks emits
 *   the <title> + a partial Open Graph set; this fills the gaps —
 *   meta descriptions where missing, og:image / og:description on case studies
 *   + contact, Twitter Card tags on every page, and JSON-LD structured data
 *   (Person, WebSite, CreativeWork + BreadcrumbList, About/ContactPage).
 *   Designed to COMPLEMENT Bricks, never duplicate a tag it already prints.
 * Version: 1.0.0
 * Author: Therum
 *
 * Tag ownership (audited 2026-06):
 *   Bricks  → <title>, robots, og:url/site_name/title/type, og:description
 *             + meta description on Home/About/Selected (page settings filled),
 *             og:image on Home/About/Selected.
 *   Missing → meta description on case studies + Contact; og:image +
 *             og:description on case studies/Contact; Twitter + JSON-LD on all.
 *   This plugin emits ONLY the missing items, gated by page context.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Therum_SEO' ) ) :

class Therum_SEO {

	/** OG/share image base (relative to home_url). */
	const OG_BASE = '/wp-content/uploads/bamleon';

	/** Case study slug → short, share-ready description (≈150 chars, no HTML). */
	private static function cs_descriptions() : array {
		return [
			'comcast'          => "Two tenures, eight years apart, on the largest U.S. internet provider. Senior Art Director, then UX lead across Xfinity's sales experience.",
			'siriusxm'         => "A new visual direction for a media giant. One tile framework, six content categories, every platform, redesigning how listeners discover audio.",
			'ford'             => "Renovating the cathedral while it's open. A century-old brand and a billion-dollar digital footprint, rebuilt component by component.",
			'timberland'       => "A two-tier enterprise design system across the VF Corp brands, Timberland, Vans, The North Face. A twelve-designer studio, a year of sprints.",
			'jpmorgan-chase'   => "At a bank serving millions daily, I directed the visual output that closed the gap between brand intent and brand execution.",
			'charter-spectrum' => "Same brand, six years apart, two agencies, two briefs. A 2018 holiday campaign toolkit and a later Spectrum suite.",
			'kingsland'        => "A modular case-study template system for a Brooklyn brand agency, four layouts built from a reorganizable module library.",
			'groundbreakers'   => "A 20-page digital magazine for Prologis, built with Edelman, and we got Pete Buttigieg in it. Real journalism, real design.",
			'electrolux'       => "A brand system that already worked, pushed for more dynamism without leaving home. Navy ground, cyan accent, technical line iconography.",
			'dailypay'         => "Four partner brands, one pay platform. A three-word brief turned into a flexible system across every surface.",
			'letsgetchecked'   => "Posed as a one-pager, shipped as a custom HTML solution inside Salesforce. The 2023 Health Equity Report, designed and engineered solo.",
			'jaywalk'          => "Brand identity by Kingsland; web design and photography direction by me, for New York Distilling Co.'s Jaywalk Rye.",
			'sidemoney'        => "Brand-as-system on WordPress. Live at sidemoney.co since 2019, now powering the Foot Locker Homegrown series.",
			'endurance'        => "Email design for a national auto-coverage brand, built with Lift Agency. Small box, long legal copy, design carrying both.",
			'therum-os'        => "WordPress's mental model is right; its implementation is stuck. Therum OS keeps the first and replaces the second.",
		];
	}

	/** Case study slug → display name (matches the OG image set). */
	private static function cs_names() : array {
		return [
			'comcast' => 'Comcast / Xfinity', 'siriusxm' => 'SiriusXM', 'ford' => 'Ford',
			'timberland' => 'Timberland', 'jpmorgan-chase' => 'JP Morgan Chase',
			'charter-spectrum' => 'Charter / Spectrum', 'kingsland' => 'Kingsland',
			'groundbreakers' => 'Groundbreakers', 'electrolux' => 'Electrolux',
			'dailypay' => 'DailyPay', 'letsgetchecked' => 'LetsGetChecked',
			'jaywalk' => 'Jaywalk Rye', 'sidemoney' => 'Sidemoney',
			'endurance' => 'Endurance', 'therum-os' => 'Therum OS',
		];
	}

	/** Static-page descriptions (used for Twitter on all; meta on Contact). */
	private static function page_descriptions() : array {
		return [
			'home'     => "Bam Leon, designer, operator, and studio lead. Fifteen years across Comcast, Ford, SiriusXM, JP Morgan, Timberland, and Macy's, direct and agency-side.",
			'about'    => "Bam Leon, designer, operator, and studio lead. Fifteen years across product, brand, and platform, direct and agency-side.",
			'selected' => "Selected work from fifteen years building for Comcast, SiriusXM, Ford, Timberland, JP Morgan, and more.",
			'contact'  => "Get in touch with Bam Leon, designer, operator, and studio lead. Project inquiries, collaborations, and studio work.",
		];
	}

	/**
	 * Resolve the current request into a context bundle:
	 *   ['kind' => home|about|selected|contact|case_study|other,
	 *    'slug', 'title', 'description', 'image', 'url']
	 */
	private static function context() : array {
		$home = home_url( '/' );
		$base = home_url( self::OG_BASE );
		$out  = [ 'kind' => 'other', 'slug' => '', 'title' => '', 'description' => '', 'image' => '', 'url' => '' ];

		if ( is_front_page() ) {
			$out['kind'] = 'home';
			$out['url']  = $home;
			$out['title'] = 'Bam Leon';
			$out['description'] = self::page_descriptions()['home'];
			$out['image'] = "$base/og-home.png";
			return $out;
		}

		if ( is_singular( 'case_study' ) ) {
			$slug = get_post_field( 'post_name', get_queried_object_id() );
			$out['kind'] = 'case_study';
			$out['slug'] = $slug;
			$out['url']  = get_permalink();
			$names = self::cs_names();
			$out['title'] = $names[ $slug ] ?? get_the_title();
			$descs = self::cs_descriptions();
			$out['description'] = $descs[ $slug ] ?? wp_strip_all_tags( get_the_excerpt() );
			$out['image'] = "$base/og/og-cs-$slug.png";
			return $out;
		}

		if ( is_page() ) {
			$slug = get_post_field( 'post_name', get_queried_object_id() );
			$pd   = self::page_descriptions();
			if ( in_array( $slug, [ 'about', 'selected', 'contact' ], true ) ) {
				$out['kind'] = $slug;
				$out['slug'] = $slug;
				$out['url']  = get_permalink();
				$out['title'] = get_the_title();
				$out['description'] = $pd[ $slug ] ?? '';
				$out['image'] = "$base/og-$slug.png";
				return $out;
			}
		}

		// Fallback (any other page/post): home image + tagline.
		$out['url']   = is_singular() ? get_permalink() : $home;
		$out['title'] = wp_get_document_title();
		$out['description'] = self::page_descriptions()['home'];
		$out['image'] = "$base/og-home.png";
		return $out;
	}

	/** Emit the gap-filling meta + Twitter + JSON-LD. Hooked to wp_head. */
	public static function head() {
		if ( is_admin() || is_feed() || is_robots() ) {
			return;
		}
		$c = self::context();
		$out = "\n<!-- Therum SEO -->\n";

		// 1. meta description — only where Bricks omits it (case studies, contact,
		//    and any non-Home/About/Selected fallback page).
		if ( in_array( $c['kind'], [ 'case_study', 'contact', 'other' ], true ) && $c['description'] ) {
			$out .= '<meta name="description" content="' . esc_attr( $c['description'] ) . "\" />\n";
		}

		// 2. og:image — Bricks omits it on case studies + contact. Width/height/alt
		//    round out the card. (Home/About/Selected already get og:image from Bricks.)
		if ( in_array( $c['kind'], [ 'case_study', 'contact', 'other' ], true ) && $c['image'] ) {
			$out .= '<meta property="og:image" content="' . esc_url( $c['image'] ) . "\" />\n";
			$out .= '<meta property="og:image:width" content="1200" />' . "\n";
			$out .= '<meta property="og:image:height" content="630" />' . "\n";
			$out .= '<meta property="og:image:alt" content="' . esc_attr( $c['title'] . ' — Bam Leon' ) . "\" />\n";
		}

		// 3. og:description — Bricks omits it on Contact (case studies already have it).
		if ( $c['kind'] === 'contact' && $c['description'] ) {
			$out .= '<meta property="og:description" content="' . esc_attr( $c['description'] ) . "\" />\n";
		}

		// 4. og:locale — universally absent; harmless to add once.
		$out .= '<meta property="og:locale" content="en_US" />' . "\n";

		// 5. Twitter Card — emitted on every page (nothing else prints these).
		if ( $c['title'] || $c['description'] || $c['image'] ) {
			$out .= '<meta name="twitter:card" content="summary_large_image" />' . "\n";
			if ( $c['title'] )       $out .= '<meta name="twitter:title" content="' . esc_attr( $c['title'] ) . "\" />\n";
			if ( $c['description'] ) $out .= '<meta name="twitter:description" content="' . esc_attr( $c['description'] ) . "\" />\n";
			if ( $c['image'] )       $out .= '<meta name="twitter:image" content="' . esc_url( $c['image'] ) . "\" />\n";
		}

		// 6. JSON-LD structured data.
		$out .= self::json_ld( $c );

		echo $out; // All values escaped above / json_encoded below.
	}

	/** Build the JSON-LD graph for the current context. */
	private static function json_ld( array $c ) : string {
		$home = home_url( '/' );
		$base = home_url( self::OG_BASE );

		$person = [
			'@type'       => 'Person',
			'@id'         => $home . '#bam',
			'name'        => 'Bam Leon',
			'url'         => $home,
			'jobTitle'    => 'Designer, operator, studio lead',
			'image'       => "$base/og-home.png",
			'sameAs'      => [
				'https://linkedin.com/in/bamleon',
				'https://read.cv/bamleon',
				'https://therum.studio',
				'https://sidemoney.co',
			],
			'worksFor'    => [
				'@type' => 'Organization',
				'name'  => 'Therum',
				'url'   => 'https://therum.studio',
			],
		];

		$graph = [];

		switch ( $c['kind'] ) {
			case 'home':
				$graph[] = $person;
				$graph[] = [
					'@type'     => 'WebSite',
					'@id'       => $home . '#website',
					'url'       => $home,
					'name'      => 'Bam Leon',
					'description' => $c['description'],
					'publisher' => [ '@id' => $home . '#bam' ],
					'inLanguage' => 'en-US',
				];
				break;

			case 'about':
				$graph[] = $person;
				$graph[] = [
					'@type'      => 'AboutPage',
					'url'        => $c['url'],
					'name'       => 'About Bam Leon',
					'description' => $c['description'],
					'about'      => [ '@id' => $home . '#bam' ],
					'inLanguage' => 'en-US',
				];
				break;

			case 'contact':
				$graph[] = [
					'@type'      => 'ContactPage',
					'url'        => $c['url'],
					'name'       => 'Contact Bam Leon',
					'description' => $c['description'],
					'about'      => [ '@id' => $home . '#bam' ],
					'inLanguage' => 'en-US',
				];
				break;

			case 'selected':
				$graph[] = [
					'@type'      => 'CollectionPage',
					'url'        => $c['url'],
					'name'       => 'Selected Work',
					'description' => $c['description'],
					'inLanguage' => 'en-US',
				];
				break;

			case 'case_study':
				$graph[] = [
					'@type'       => 'CreativeWork',
					'url'         => $c['url'],
					'name'        => $c['title'],
					'headline'    => $c['title'],
					'description' => $c['description'],
					'image'       => $c['image'],
					'inLanguage'  => 'en-US',
					'author'      => [ '@id' => $home . '#bam' ],
					'creator'     => [ '@id' => $home . '#bam' ],
					'isPartOf'    => [ '@type' => 'WebSite', 'url' => $home, 'name' => 'Bam Leon' ],
				];
				$graph[] = $person;
				$graph[] = [
					'@type'           => 'BreadcrumbList',
					'itemListElement' => [
						[ '@type' => 'ListItem', 'position' => 1, 'name' => 'Home',          'item' => $home ],
						[ '@type' => 'ListItem', 'position' => 2, 'name' => 'Selected Work', 'item' => home_url( '/selected/' ) ],
						[ '@type' => 'ListItem', 'position' => 3, 'name' => $c['title'],     'item' => $c['url'] ],
					],
				];
				break;

			default:
				return '';
		}

		$doc = [ '@context' => 'https://schema.org', '@graph' => $graph ];
		$json = wp_json_encode( $doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return '<script type="application/ld+json">' . $json . "</script>\n";
	}
}

// Priority 6: after Bricks' description (1), before its OG (99999). Order among
// og:/twitter tags is irrelevant to crawlers; this keeps the block together.
add_action( 'wp_head', [ 'Therum_SEO', 'head' ], 6 );

endif;
